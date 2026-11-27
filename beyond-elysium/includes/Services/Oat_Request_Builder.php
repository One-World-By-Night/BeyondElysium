<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * The body of the R&U Request BE files in the Archivist Toolkit for one change: the form fields the Toolkit's own
 * submit sends, the regulation level its rules work out, and a plain account of what is asked for the Coordinator to
 * read. Pure - no database, no network.
 */
class Oat_Request_Builder {

	public const DOMAIN = 'character_lifecycle';
	public const FORM   = 'cl_ru_request';

	/**
	 * The most characters of the Storyteller's own note that go to the Coordinator.
	 */
	public const NOTE_LIMIT = 1000;

	/**
	 * How the Toolkit ranks a regulation level; a request that needs a Coordinator ranks 2 or higher.
	 */
	private const PRIORITY = [
		'council_vote'         => 4,
		'disallowed'           => 3,
		'coordinator_approval' => 2,
		'coordinator_notify'   => 1,
	];

	/**
	 * @param array<string,mixed> $in `chronicle` (the Toolkit's slug), `uuid` (the key that makes a resend safe),
	 *                                `character_uuid`, `character_name`, `is_npc`, `character_status`, `sender_name`,
	 *                                `sender_email`, `line` (`label`, `section`, `detail`, `xp`, `reason`, `player_note`),
	 *                                `rules` (each `id`, `pc_level`, `npc_level`), `coordinators` (office slugs), `note`,
	 *                                `purpose` (`approval` or `notify`), `source`, and `prior` (the Toolkit ids of the
	 *                                requests already filed in this send).
	 * @return array<string,mixed> The JSON body for `oat/submit`.
	 */
	public static function build( array $in ): array {
		$approval = ( $in['purpose'] ?? 'approval' ) !== 'notify';
		$rules    = array_values( (array) ( $in['rules'] ?? [] ) );
		$line     = (array) $in['line'];

		$description = self::description( $in, $line );
		$note        = $description;
		$storyteller = trim( mb_substr( (string) ( $in['note'] ?? '' ), 0, self::NOTE_LIMIT ) );
		if ( $storyteller !== '' ) {
			$note .= "\nStoryteller's note: " . $storyteller;
		}

		$meta = [
			'chronicle_slug'   => (string) $in['chronicle'],
			'submitter_name'   => (string) $in['sender_name'],
			'submitter_email'  => (string) $in['sender_email'],
			'character_name'   => (string) $in['character_uuid'],
			'ru_sub_status'    => self::toolkit_status( (string) ( $in['character_status'] ?? '' ) ),
			'action_type'      => 'ru_request',
			'requires_coord'   => $approval ? '1' : '0',
			'regulation_level' => self::regulation_level( $rules, (bool) ( $in['is_npc'] ?? false ), $approval ),
			'be_source'        => (string) ( $in['source'] ?? '' ),
		];
		if ( $rules === [] ) {
			$meta['item_description'] = trim( (string) ( $line['section'] ?? '' ) . ': ' . (string) ( $line['label'] ?? '' ), ': ' );
		}
		$offices = self::offices( (array) ( $in['coordinators'] ?? [] ) );
		if ( $offices !== [] ) {
			$meta['involved_coordinators'] = (string) wp_json_encode( $offices );
		}

		$body = [
			'domain'          => self::DOMAIN,
			'form_slug'       => self::FORM,
			'chronicle_slug'  => (string) $in['chronicle'],
			'title'           => (string) $in['character_name'] . ': ' . (string) ( $line['label'] ?? '' ),
			'description'     => $description,
			'rules'           => array_map( static fn( $rule ): int => (int) $rule['id'], $rules ),
			'meta'            => $meta,
			'note'            => $note,
			'idempotency_key' => (string) $in['uuid'],
			'full_chain'      => true,
		];

		$prior = array_values( array_filter( array_map( 'intval', (array) ( $in['prior'] ?? [] ) ) ) );
		if ( $prior !== [] ) {
			$body['batch'] = [ 'prior' => $prior, 'mode' => 'list' ];
		}
		return $body;
	}

	/**
	 * What is asked, in plain lines: who sent it, what it is, BE's reason, and the player's note.
	 *
	 * @param array<string,mixed> $in
	 * @param array<string,mixed> $line
	 */
	private static function description( array $in, array $line ): string {
		$what = (string) ( $line['label'] ?? '' ) . ' (' . (string) ( $line['section'] ?? '' ) . ')';
		$detail = trim( (string) ( $line['detail'] ?? '' ) );
		if ( $detail !== '' ) {
			$what .= ': ' . $detail;
		}
		$xp = (int) ( $line['xp'] ?? 0 );
		if ( $xp > 0 ) {
			$what .= ', ' . $xp . ' XP';
		}

		$text = 'Sent from Beyond Elysium by ' . (string) $in['sender_name'] . ".\n" . (string) $in['character_name'] . ' - ' . $what;
		$reason = trim( (string) ( $line['reason'] ?? '' ) );
		if ( $reason !== '' ) {
			$text .= "\nReason in Beyond Elysium:\n" . $reason;
		}
		$player = trim( (string) ( $line['player_note'] ?? '' ) );
		if ( $player !== '' ) {
			$text .= "\nPlayer's note: " . $player;
		}
		return $text;
	}

	/**
	 * The level the Toolkit gives the request: the highest of the ticked rules' levels for this kind of character, but
	 * never below Coordinator Approval for a request that asks for one, and always Coordinator Notify for a notification.
	 *
	 * @param array<int,array<string,mixed>> $rules
	 */
	private static function regulation_level( array $rules, bool $is_npc, bool $approval ): string {
		if ( ! $approval ) {
			return 'coordinator_notify';
		}
		$best     = 0;
		$best_key = 'coordinator_approval';
		foreach ( $rules as $rule ) {
			$level = ( $is_npc && ! empty( $rule['npc_level'] ) ) ? (string) $rule['npc_level'] : (string) ( $rule['pc_level'] ?? '' );
			if ( isset( self::PRIORITY[ $level ] ) && self::PRIORITY[ $level ] > $best ) {
				$best     = self::PRIORITY[ $level ];
				$best_key = $level;
			}
		}
		return $best >= self::PRIORITY['coordinator_approval'] ? $best_key : 'coordinator_approval';
	}

	/**
	 * The Toolkit's character status that comes nearest to BE's.
	 */
	private static function toolkit_status( string $status ): string {
		$map = [ 'dead' => 'dead', 'inactive' => 'inactive', 'retired' => 'inactive', 'removed_ic' => 'removed_ic', 'removed_ooc' => 'removed_ooc' ];
		return $map[ strtolower( $status ) ] ?? 'active';
	}

	/**
	 * Office slugs, lower-case, each once.
	 *
	 * @param array<int,mixed> $offices
	 * @return string[]
	 */
	private static function offices( array $offices ): array {
		$clean = [];
		foreach ( $offices as $office ) {
			$slug = strtolower( trim( (string) $office ) );
			if ( $slug !== '' ) {
				$clean[ $slug ] = $slug;
			}
		}
		return array_values( $clean );
	}
}
