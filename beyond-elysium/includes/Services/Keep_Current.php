<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Transfer;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and sends a kept-current character's own sheet to its host whenever it changes.
 */
class Keep_Current {

	/**
	 * Fires when a scheduled delivery comes due.
	 */
	const DELIVER_HOOK = 'be_keep_current_deliver';

	/**
	 * How long a batch of changes waits before one delivery goes out.
	 */
	const DEBOUNCE_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * How long a visit can go without a confirmed delivery before it is flagged unreachable.
	 */
	const UNREACHABLE_AFTER_HOURS = 24;

	/**
	 * The open inbound visit a character's own copy is held under, when both sides have agreed to keep it current -
	 * what a host's own change/XP-award path checks before applying anything locally.
	 *
	 * @param int $character_id
	 * @return object|null
	 */
	public static function inbound_kept_current_visit( int $character_id ): ?object {
		return Transfer::open_inbound_kept_current_visit( $character_id );
	}

	/**
	 * The inbound visit a plot-entry share offer binds to - open, or ended within the grace window, kept current
	 * or not. Null when the character isn't a real visitor (a hand-carried copy has no row to find).
	 *
	 * @param int $character_id
	 * @return object|null
	 */
	public static function shareable_visit_for_character( int $character_id ): ?object {
		return Transfer::find_shareable_visit_for_character( $character_id );
	}

	/**
	 * A character changed: schedules one delivery, five minutes out, for each open visit both sides have agreed to
	 * keep current - unscheduling a pending one first, so a batch of changes still sends only one update.
	 *
	 * @param int    $character_id
	 * @param string $what 'sheet_data' | 'xp' | 'name'.
	 */
	public static function on_change( int $character_id, string $what ): void {
		foreach ( Transfer::kept_current_outbound_visits( $character_id ) as $visit ) {
			Transfer::bump_sequence( (int) $visit->id );
			self::reschedule( (int) $visit->id );
		}
	}

	/**
	 * @param int $visit_id
	 */
	private static function reschedule( int $visit_id ): void {
		$next = wp_next_scheduled( self::DELIVER_HOOK, [ $visit_id ] );
		if ( $next !== false ) {
			wp_unschedule_event( $next, self::DELIVER_HOOK, [ $visit_id ] );
		}
		wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, self::DELIVER_HOOK, [ $visit_id ] );
	}

	/**
	 * Sends the character's current sheet to its host: name, sheet data, XP, the next sequence, and a fresh code
	 * over all of it - revoking the visit's previous one. Always records the attempt; only a confirmed delivery
	 * advances `delivered_sequence`.
	 *
	 * @param int $visit_id
	 * @return bool Whether the host confirmed it.
	 */
	public static function deliver( int $visit_id ): bool {
		$visit = Transfer::find( $visit_id );
		if ( $visit === null || ! $visit->keep_current || ! $visit->keep_current_accepted
			|| $visit->direction !== 'outbound' || ! Transfer::is_open( $visit )
		) {
			return false;
		}

		$character = Character::find( (int) $visit->character_id );
		if ( $character === null ) {
			return false;
		}

		$payload = [
			'uuid'       => $character->uuid,
			'name'       => $character->name,
			'sheet_data' => $character->sheet_data,
			'xp_earned'  => (int) $character->xp_earned,
			'xp_unspent' => (int) $character->xp_unspent,
			'sequence'   => (int) $visit->sequence,
		];
		$sheet_hash = self::canonical_hash( $payload );

		$attestation = Attestation::issue( $character, 'transfer', $sheet_hash );
		if ( ! empty( $visit->last_code_id ) ) {
			Attestation::revoke( (int) $visit->last_code_id );
		}

		$response = wp_safe_remote_post(
			untrailingslashit( (string) $visit->host_site ) . '/wp-json/be/v1/' . $visit->host_slug . '/transfers/' . ( $visit->peer_uuid ?: $visit->character_uuid ) . '/from-home',
			[
				'timeout' => 20,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => (string) wp_json_encode( array_merge( $payload, [
					'type'      => 'update',
					'code'      => $attestation->short_code,
					'home_site' => home_url(),
					'home_slug' => $visit->home_slug,
				] ) ),
			]
		);

		$confirmed = ! is_wp_error( $response )
			&& (int) wp_remote_retrieve_response_code( $response ) >= 200
			&& (int) wp_remote_retrieve_response_code( $response ) < 300;

		Transfer::record_delivery( $visit_id, (int) $attestation->id, $confirmed );

		return $confirmed;
	}

	/**
	 * The hash a delivery's own code is bound to - the same fields, in the same order, whichever side builds the
	 * payload (the sending character's own live data, or the host's own received request fields).
	 *
	 * @param array<string,mixed> $payload
	 * @return string
	 */
	public static function canonical_hash( array $payload ): string {
		return hash( 'sha256', (string) wp_json_encode( [
			'uuid'       => (string) ( $payload['uuid'] ?? '' ),
			'name'       => (string) ( $payload['name'] ?? '' ),
			'sheet_data' => $payload['sheet_data'] ?? [],
			'xp_earned'  => (int) ( $payload['xp_earned'] ?? 0 ),
			'xp_unspent' => (int) ( $payload['xp_unspent'] ?? 0 ),
			'sequence'   => (int) ( $payload['sequence'] ?? 0 ),
		] ) );
	}

	/**
	 * Retries every visit whose latest change was never confirmed delivered, always with the newest sheet, then
	 * flags anything that has gone a full day with nothing getting through.
	 */
	public static function sweep(): void {
		foreach ( Transfer::visits_needing_delivery() as $visit ) {
			self::deliver( (int) $visit->id );
		}

		Transfer::mark_long_unreachable( self::UNREACHABLE_AFTER_HOURS );
		Transfer::mark_long_unreachable_inbound( self::UNREACHABLE_AFTER_HOURS );
	}

	/**
	 * The host's own side of a delivery: a snapshot of what the copy held before, then its name, sheet and XP
	 * replaced with what arrived. Every trait_list/tiered_power entry is checked against this chronicle's own
	 * resolved catalog, independent of whatever it was at home - one lacking a match here lands `custom`. What the
	 * host owns - plot links, attendance, connections, its own chronicle copies of blocks - is never touched.
	 *
	 * @param object               $visit The host's own inbound row.
	 * @param array<string,mixed>  $body  The update's own fields (name, sheet_data, xp_earned, xp_unspent, sequence).
	 * @return bool False only when the character itself no longer exists.
	 */
	public static function apply_update( object $visit, array $body ): bool {
		$character = Character::find( (int) $visit->character_id );
		if ( $character === null ) {
			return false;
		}

		Snapshot::create( (int) $character->id, null );

		$old_sheet      = (array) $character->sheet_data;
		$incoming_sheet = (array) self::clean_markup( (array) ( $body['sheet_data'] ?? [] ) );
		$resolved       = Creature_Stack::resolve( (string) $character->stack_slug, (string) $visit->host_slug );
		$blocks         = $resolved['blocks'] ?? [];

		$changed_sections = [];
		$custom_entries   = [];

		foreach ( $incoming_sheet as $block_slug => $entries ) {
			if ( ( $old_sheet[ $block_slug ] ?? null ) !== $entries ) {
				$changed_sections[] = $block_slug;
			}

			$block = $blocks[ $block_slug ] ?? null;
			if ( $block === null || ! is_array( $entries ) ) {
				continue;
			}

			$definition  = $block->definition;
			$section_type = $block->section_type ?? '';
			$catalog      = $section_type === 'trait_list' ? (array) ( $definition->items ?? [] )
				: ( $section_type === 'tiered_power' ? (array) ( $definition->powers ?? [] ) : null );
			if ( $catalog === null ) {
				continue;
			}

			foreach ( $entries as &$entry ) {
				$entry = (array) $entry;
				$name  = (string) ( $entry['name'] ?? '' );
				$found = $section_type === 'trait_list'
					? Trait_Alias_Resolver::find_item_by_name( $catalog, $name )
					: Trait_Alias_Resolver::find_power_by_name( $catalog, $name );
				$entry['custom'] = $found === null;
				if ( $found === null && $name !== '' ) {
					$custom_entries[] = "{$block_slug}: {$name}";
				}
			}
			unset( $entry );
			$incoming_sheet[ $block_slug ] = $entries;
		}

		$name = sanitize_text_field( (string) ( $body['name'] ?? '' ) );
		Character::update_header( (int) $character->id, [ 'name' => $name !== '' ? $name : (string) $character->name ] );
		Character::update_sheet_data( (int) $character->id, $incoming_sheet );

		$earned_delta  = (int) ( $body['xp_earned'] ?? 0 ) - (int) $character->xp_earned;
		$unspent_delta = (int) ( $body['xp_unspent'] ?? 0 ) - (int) $character->xp_unspent;
		if ( $earned_delta !== 0 || $unspent_delta !== 0 ) {
			Character::update_xp( (int) $character->id, $earned_delta, $unspent_delta );
		}

		Transfer::append_update_log( (int) $visit->id, [
			'when'     => current_time( 'mysql', true ),
			'sequence' => (int) ( $body['sequence'] ?? 0 ),
			'changed'  => $changed_sections,
			'custom'   => $custom_entries,
		] );

		return true;
	}

	/**
	 * A value from another site with every string holding markup run through wp_kses_post(), at any depth. A string
	 * with no `<` is returned as it is.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function clean_markup( $value ) {
		if ( is_string( $value ) ) {
			return strpos( $value, '<' ) === false ? $value : wp_kses_post( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::clean_markup( $item );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			return self::clean_markup( (array) $value );
		}
		return $value;
	}

	/**
	 * A host's own change or XP award, forwarded to the character's real home rather than applied here. Mints a
	 * fresh, short-lived verification code over the change's own content and posts it to home's `from-host` route.
	 *
	 * @param object      $visit  The host's own inbound row.
	 * @param object|null $change The local, `forwarded`-status change row just recorded.
	 */
	public static function forward_change( object $visit, ?object $change ): void {
		if ( $change === null || $visit->home_site === '' || $visit->home_slug === '' ) {
			return;
		}

		self::post_forward( $visit, [
			'type'        => 'change',
			'change_type' => $change->change_type,
			'change_data' => $change->change_data,
			'host_note'   => $change->notes,
		] );
	}

	/**
	 * A host's own free-text note about a visiting character, shared to home - Storytellers-only there once a
	 * reviewer approves it, with no effect on the sheet either way.
	 *
	 * @param object $visit The host's own inbound row.
	 * @param string $note
	 */
	public static function forward_note( object $visit, string $note ): void {
		if ( $visit->home_site === '' || $visit->home_slug === '' ) {
			return;
		}

		self::post_forward( $visit, [
			'type'      => 'note',
			'host_note' => $note,
		] );
	}

	/**
	 * Tells a visit's real home that this chronicle renamed itself - identified by the slug home still has stored,
	 * carrying the new one to replace it with.
	 *
	 * @param object $visit          The host's own inbound row, read before the local rename rewrote it.
	 * @param string $old_host_slug  What home still calls this chronicle.
	 * @param string $new_host_slug
	 * @param string $new_host_chronicle
	 */
	public static function notify_rename( object $visit, string $old_host_slug, string $new_host_slug, string $new_host_chronicle ): void {
		if ( $visit->home_site === '' || $visit->home_slug === '' ) {
			return;
		}

		self::post_forward( $visit, [
			'type'               => 'moved',
			'new_host_slug'      => $new_host_slug,
			'new_host_chronicle' => $new_host_chronicle,
		], $old_host_slug );
	}

	/**
	 * A host's own request to pair a player-submitted character with its real home - sent once, when the host
	 * accepts a submission that asked to be kept current and the file carried a code verifying its own home.
	 * Fire-and-forget: a refusal, no answer at all, or no verifiable home in the first place, simply leaves the
	 * host's own copy with `keep_current` set and `keep_current_accepted` unset - unpaired, same as any other
	 * unaccepted keep-current request.
	 *
	 * @param object $character    The host's own local copy.
	 * @param string $home_site
	 * @param string $home_slug
	 * @param string $home_code    The code the file's own `id` field carried, naming home as its issuer.
	 * @param string $host_chronicle
	 */
	public static function send_pairing_request( object $character, string $home_site, string $home_slug, string $home_code, string $host_chronicle ): void {
		if ( $home_site === '' || $home_slug === '' ) {
			return;
		}

		$fields = [
			'type'           => 'pairing',
			'home_code'      => $home_code,
			'host_chronicle' => $host_chronicle,
		];
		$hash        = hash( 'sha256', (string) wp_json_encode( [ 'uuid' => $character->uuid ] + $fields ) );
		$attestation = Attestation::issue_visit_pairing( $character, $hash, [
			'visit_uuid' => $character->uuid,
			'action'     => 'pairing',
		] );

		wp_safe_remote_post(
			untrailingslashit( $home_site ) . '/wp-json/be/v1/' . $home_slug . '/transfers/' . $character->uuid . '/from-host',
			[
				'timeout' => 20,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => (string) wp_json_encode( array_merge( $fields, [
					'code'      => $attestation->short_code,
					'host_site' => home_url(),
					'host_slug' => (string) $character->owner_slug,
				] ) ),
			]
		);
	}

	/**
	 * Home's own confirmation, once it approves a pairing request: tells the host to set its own already-existing
	 * row's `keep_current_accepted` too, matching this new outbound row's own flags.
	 *
	 * @param object $visit     Home's own newly-created outbound row.
	 * @param string $host_uuid The host's own local copy's uuid - a submission always mints its own fresh identity,
	 *                          never home's, so this is never `$visit->character_uuid`.
	 */
	public static function notify_pairing_accepted( object $visit, string $host_uuid ): void {
		if ( $host_uuid === '' ) {
			return;
		}
		$character = Character::find( (int) $visit->character_id );
		if ( $character === null ) {
			return;
		}

		$fields      = [ 'type' => 'pairing_accepted', 'home_uuid' => $visit->character_uuid ];
		$hash        = hash( 'sha256', (string) wp_json_encode( [ 'uuid' => $host_uuid ] + $fields ) );
		$attestation = Attestation::issue_visit_item( $character, $hash, [
			'visit_uuid' => $visit->character_uuid,
			'action'     => 'pairing_accepted',
		] );

		wp_safe_remote_post(
			untrailingslashit( (string) $visit->host_site ) . '/wp-json/be/v1/' . $visit->host_slug . '/transfers/' . $host_uuid . '/from-home',
			[
				'timeout' => 20,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => (string) wp_json_encode( array_merge( $fields, [
					'code'      => $attestation->short_code,
					'home_site' => home_url(),
					'home_slug' => $visit->home_slug,
				] ) ),
			]
		);
	}

	/**
	 * Shared body for `forward_change()`/`forward_note()`/`notify_rename()`: issues a `visit_item` code bound to
	 * the exact content being sent, and posts it to home's `from-host` route.
	 *
	 * @param object               $visit
	 * @param array<string,mixed>  $fields Merged into the body alongside `type`/`code`/`host_site`/`host_slug`.
	 * @param string|null          $host_slug Overrides `$visit->host_slug` as the identifying field in the body.
	 */
	private static function post_forward( object $visit, array $fields, ?string $host_slug = null ): void {
		$character = Character::find( (int) $visit->character_id );
		if ( $character === null ) {
			return;
		}

		$home_uuid   = $visit->peer_uuid ?: $visit->character_uuid;
		$hash        = hash( 'sha256', (string) wp_json_encode( [ 'uuid' => $home_uuid ] + $fields ) );
		$attestation = Attestation::issue_visit_item( $character, $hash, [
			'visit_uuid' => $visit->character_uuid,
			'action'     => $fields['type'],
		] );

		$body = array_merge( $fields, [
			'code'      => $attestation->short_code,
			'host_site' => home_url(),
			'host_slug' => $host_slug ?? $visit->host_slug,
		] );

		wp_safe_remote_post(
			untrailingslashit( (string) $visit->home_site ) . '/wp-json/be/v1/' . $visit->home_slug . '/transfers/' . $home_uuid . '/from-host',
			[
				'timeout' => 20,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => (string) wp_json_encode( $body ),
			]
		);
	}
}
