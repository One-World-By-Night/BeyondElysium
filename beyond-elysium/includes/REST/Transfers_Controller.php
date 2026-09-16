<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Notifications;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Character_Exporter;
use BeyondElysium\Services\GEX_Xml_Parser;
use BeyondElysium\Services\Not_Exportable_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for chronicle-to-chronicle character transfer (GX-8/9).
 * A transfer needs a Storyteller's approval on both sides (owner ruling,
 * 2026-09-14): the home chronicle's Storyteller initiates it, and the
 * receiving chronicle's Storyteller reviews the offer and accepts or refuses
 * it - nothing is written to the receiving chronicle before that.
 *
 * `inbound` is this plugin's SECOND unauthenticated route (after
 * `Verify_Controller`) - it has to be, since the caller is another WordPress
 * installation with no session on this one - and carries the same posture:
 * trust comes from an independent callback to the sender's own
 * `/verify/{code}`, never from the POST body alone. All it can do is leave an
 * offer waiting for review.
 *
 * The payload crossing the wire is a real `.gex` XML document (the same one
 * `Character_Exporter`/`GEX_Xml_Parser` already read and write), not a
 * bespoke envelope - one serializer for both the file download and the wire
 * transfer, and an ST can read what left (§8.2).
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-8, GX-9, §8
 * @see BE_PROCESS/1.0.0-review.md F-003, F-005, F-006
 */
class Transfers_Controller extends Base_Controller {

	protected $rest_base = 'transfers';

	/** Inbound offers accepted per IP per rolling minute. */
	const INBOUND_RATE_LIMIT = 10;

	/** Offers one chronicle may hold waiting for review before new ones are turned away. */
	const MAX_WAITING_OFFERS = 50;

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/transfers', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_items' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/transfers/outbound', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'initiate' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );

		// Home side.
		foreach ( [ 'acknowledge', 'release', 'decline' ] as $action ) {
			register_rest_route( $this->namespace, "/(?P<game_slug>[a-z0-9\\-]+)/transfers/(?P<id>\\d+)/{$action}", [
				[
					'methods'             => 'POST',
					'callback'            => [ $this, $action ],
					'permission_callback' => $this->permission( 'be_manage_characters' ),
				],
			] );
		}

		// Host side: an offer is reviewed and accepted like an import, by whoever may import here.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/transfers/(?P<id>\d+)/review', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'review' ],
				'permission_callback' => $this->permission( 'be_import' ),
			],
		] );
		foreach ( [ 'accept', 'refuse' ] as $action ) {
			register_rest_route( $this->namespace, "/(?P<game_slug>[a-z0-9\\-]+)/transfers/(?P<id>\\d+)/{$action}", [
				[
					'methods'             => 'POST',
					'callback'            => [ $this, $action ],
					'permission_callback' => $this->permission( 'be_import' ),
				],
			] );
		}
		foreach ( [ 'send-home' => 'send_home', 'retain' => 'retain' ] as $path => $method ) {
			register_rest_route( $this->namespace, "/(?P<game_slug>[a-z0-9\\-]+)/transfers/(?P<id>\\d+)/{$path}", [
				[
					'methods'             => 'POST',
					'callback'            => [ $this, $method ],
					'permission_callback' => $this->permission( 'be_manage_characters' ),
				],
			] );
		}

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/transfers/inbound', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'inbound' ],
				// Deliberate: the caller is another WordPress install with no session here
				// (GX-8/9, matching Verify_Controller's own precedent). Trust comes from
				// this handler's own callback-verify against the sender's home site, and
				// the most it can do is leave an offer for this chronicle's Storytellers.
				'permission_callback' => '__return_true',
			],
		] );
	}

	/**
	 * Lists every transfer row this chronicle is party to on this site -
	 * outbound rows it is home to, inbound rows it hosts - newest first,
	 * without stored payloads.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		return $this->success( Transfer::for_game( $game->slug ) );
	}

	/**
	 * Initiates an outbound transfer: exports the character as a transfer
	 * document (real verification attestation embedded, uuid carried),
	 * takes a snapshot of the sheet as it leaves, records a `pending`
	 * transfer row, and - when a host site was given - POSTs the payload
	 * there directly, where it waits for the host's Storytellers. The row
	 * stays `pending` until the home Storyteller marks it received abroad;
	 * the exported document is always returned, so the offline carrier -
	 * download and email it - always works.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function initiate( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character_id = (int) $request->get_param( 'character_id' );
		$character    = Character::find( $character_id );
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		if ( Transfer::find_open( $character->uuid, 'outbound' ) !== null ) {
			return $this->already_travelling();
		}

		$host_site  = trim( (string) $request->get_param( 'host_site' ) );
		$host_slug  = trim( (string) $request->get_param( 'host_slug' ) );
		$has_host   = $host_site !== '' && $host_slug !== '';

		// Full ST content, never hide_st - the receiving Storyteller needs to run this
		// character for real, exactly the reasoning §8.2 gives for choosing XML at all.
		try {
			$export = Character_Exporter::export( $character_id, [ 'hide_st' => false, 'as_transfer' => true ] );
		} catch ( Not_Exportable_Exception $e ) {
			return $this->error( 'not_exportable', $e->getMessage(), 422 );
		}

		$snapshot_id = Snapshot::create( $character_id, null );

		try {
			$transfer_id = Transfer::create( [
				'character_uuid' => $character->uuid,
				'character_id'   => $character_id,
				'character_name' => $character->name,
				'direction'      => 'outbound',
				'state'          => 'pending',
				'home_slug'      => $game->slug,
				'home_site'      => home_url(),
				'home_chronicle' => $game->name,
				'host_slug'      => $has_host ? $host_slug : null,
				'host_site'      => $has_host ? untrailingslashit( $host_site ) : null,
				'attestation_id' => $export['attestation_id'] ?? null,
				'snapshot_id'    => $snapshot_id,
				'payload_hash'   => hash( 'sha256', $export['xml'] ),
				'initiated_by'   => get_current_user_id(),
			] );
		} catch ( \RuntimeException $e ) {
			// Another send of this character was recorded first, or the row didn't save (1.0.0-review F-110).
			return Transfer::find_open( $character->uuid, 'outbound' ) !== null ? $this->already_travelling() : $this->transfer_not_recorded();
		}

		if ( ! $has_host ) {
			return $this->success( [
				'transfer' => Transfer::find( $transfer_id ),
				'xml'      => $export['xml'],
				'warnings' => $export['warnings'],
			] );
		}

		$post = $this->post_to_host( $host_site, $host_slug, $export['xml'], $game, (string) ( $export['short_code'] ?? '' ) );
		$body = $post['body'] ?? [];

		if ( $post['ok'] && ! empty( $body['accepted'] ) ) {
			// A host still running a version that accepted on arrival.
			Transfer::transition( $transfer_id, 'abroad', [ 'host_chronicle' => $body['host_chronicle'] ?? null ] );
		} elseif ( $post['ok'] && ! empty( $body['pending_review'] ) ) {
			Transfer::transition( $transfer_id, 'pending', [
				'host_chronicle' => $body['host_chronicle'] ?? null,
				'notes'          => __( 'Waiting for the host chronicle\'s Storytellers to accept it.', 'beyond-elysium' ),
			] );
		} else {
			// Unreachable host, or the host turned the offer away - the row stays pending
			// rather than guessing; the offline carrier (the file this response still
			// includes) always works regardless of what the host's server is doing.
			Transfer::transition( $transfer_id, 'pending', [ 'notes' => $post['note'] ?? ( $body['message'] ?? null ) ] );
		}

		return $this->success( [
			'transfer' => Transfer::find( $transfer_id ),
			'xml'      => $export['xml'],
			'warnings' => $export['warnings'],
			'host'     => $post['body'] ?? null,
		] );
	}

	/**
	 * Home ST marks a still-pending outbound transfer as received abroad -
	 * once the host's Storytellers have accepted it, or the host confirmed
	 * receipt some other way (email, a phone call).
	 */
	public function acknowledge( $request ) {
		return $this->manual_transition( $request, 'home', 'pending', 'abroad' );
	}

	/**
	 * Home ST permanently gives the character up - a real move, not travel.
	 * Only legal from `abroad`: a character still merely `pending` hasn't
	 * been confirmed to have arrived anywhere yet.
	 */
	public function release( $request ) {
		return $this->manual_transition( $request, 'home', 'abroad', 'released' );
	}

	/**
	 * Home ST cancels a still-pending transfer - the host never took it, or
	 * never will. Revokes the transfer's verification code, so an offer still
	 * waiting at the host can no longer be accepted.
	 */
	public function decline( $request ) {
		$response = $this->manual_transition( $request, 'home', 'pending', 'declined' );
		if ( ! is_wp_error( $response ) && ! empty( $response->get_data()->attestation_id ) ) {
			Attestation::revoke( (int) $response->get_data()->attestation_id );
		}
		return $response;
	}

	/**
	 * Host ST sends a visiting character back - the visit is over.
	 */
	public function send_home( $request ) {
		return $this->manual_transition( $request, 'host', 'visiting', 'sent_home' );
	}

	/**
	 * Host ST keeps a visiting character for good.
	 */
	public function retain( $request ) {
		return $this->manual_transition( $request, 'host', 'visiting', 'retained' );
	}

	/**
	 * Host ST refuses an offer. Nothing was ever written for it; the stored
	 * payload is discarded.
	 */
	public function refuse( $request ) {
		return $this->manual_transition( $request, 'host', 'offered', 'declined', [ 'payload' => null ] );
	}

	/**
	 * Shared body for the manual, single-legal-predecessor state transitions.
	 * `home` actions apply to this chronicle's outbound rows, `host` actions
	 * to the inbound rows it hosts.
	 *
	 * @param \WP_REST_Request     $request
	 * @param string               $side 'home' | 'host'.
	 * @param string               $from_state
	 * @param string               $to_state
	 * @param array<string,mixed>  $extra Columns to set alongside the state.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function manual_transition( $request, string $side, string $from_state, string $to_state, array $extra = [] ) {
		$transfer = $this->transfer_in_state( $request, $side, $from_state );
		if ( is_wp_error( $transfer ) ) {
			return $transfer;
		}

		Transfer::transition( (int) $transfer->id, $to_state, $extra );
		return $this->success( self::without_payload( Transfer::find( (int) $transfer->id ) ) );
	}

	/**
	 * Receives a transfer offer from another chronicle - possibly on another
	 * WordPress installation entirely. Never trusts the POST body on its own:
	 * calls back to the claimed home site's own public verify endpoint, and
	 * only once that confirms the payload is genuine, current, and really
	 * issued by the site it claims to be from does it record the offer - in
	 * its transfer row, payload included - and tell this chronicle's
	 * Storytellers. Nothing is imported here; `accept()` does that, after a
	 * Storyteller has reviewed it (1.0.0-review F-003, F-005).
	 *
	 * This authenticates the issuer, not the requester: anyone who stands up
	 * their own Beyond Elysium install can attest to and send a character
	 * here. Such an offer can only wait for a Storyteller to refuse it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function inbound( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		if ( self::is_rate_limited() ) {
			return $this->error( 'rate_limited', __( 'Too many transfer requests. Please try again shortly.', 'beyond-elysium' ), 429 );
		}

		$payload    = (string) $request->get_param( 'payload' );
		$short_code = (string) $request->get_param( 'short_code' );
		$home_site  = untrailingslashit( (string) $request->get_param( 'home_site' ) );

		if ( $payload === '' || $short_code === '' || $home_site === '' ) {
			return $this->error( 'invalid_param', __( 'A transfer payload, code, and home site are required.', 'beyond-elysium' ), 400 );
		}

		$verified = $this->verify_with_home( $home_site, $short_code, $payload );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		try {
			$parsed = GEX_Xml_Parser::parse_string( $payload );
		} catch ( \RuntimeException $e ) {
			return $this->error( 'parse_failed', $e->getMessage(), 400 );
		}

		$characters = $parsed['characters'] ?? [];
		$uuid       = (string) ( $characters[0]['uuid'] ?? '' );
		if ( count( $characters ) !== 1 || $uuid === '' ) {
			return $this->error( 'invalid_payload', __( 'A transfer document carries exactly one character, with its identity.', 'beyond-elysium' ), 400 );
		}

		if ( Transfer::find_open( $uuid, 'inbound' ) !== null ) {
			return $this->already_offered();
		}
		if ( count( array_filter( Transfer::for_game( $game->slug, self::MAX_WAITING_OFFERS + 1 ), static fn( $row ) => $row->direction === 'inbound' && $row->state === 'offered' ) ) >= self::MAX_WAITING_OFFERS ) {
			return $this->error( 'too_many_offers', __( 'This chronicle has too many transfers waiting for review. Try again once its Storytellers have caught up.', 'beyond-elysium' ), 429 );
		}

		// The home chronicle's name and slug come from the verified issuer, not the request body.
		try {
			$transfer_id = Transfer::create( [
				'character_uuid' => $uuid,
				'character_name' => (string) ( $characters[0]['name'] ?? '' ),
				'direction'      => 'inbound',
				'state'          => 'offered',
				'home_slug'      => (string) ( $verified['issuer']['slug'] ?? $request->get_param( 'home_slug' ) ),
				'home_site'      => $home_site,
				'home_chronicle' => (string) ( $verified['issuer']['chronicle'] ?? $request->get_param( 'home_chronicle' ) ),
				'host_slug'      => $game->slug,
				'host_site'      => home_url(),
				'host_chronicle' => $game->name,
				'payload_hash'   => hash( 'sha256', $payload ),
				'payload'        => $payload,
				'initiated_by'   => get_current_user_id(),
			] );
		} catch ( \RuntimeException $e ) {
			// Another offer of this character was recorded first, or the row didn't save (1.0.0-review F-110).
			return Transfer::find_open( $uuid, 'inbound' ) !== null ? $this->already_offered() : $this->transfer_not_recorded();
		}

		$transfer = Transfer::find( $transfer_id );
		if ( $transfer ) {
			Notifications::transfer_offered( $game, $transfer );
		}

		return $this->success( [
			'accepted'       => false,
			'pending_review' => true,
			'host_chronicle' => $game->name,
		], 202 );
	}

	/**
	 * Shows a waiting offer the way the Import page shows a parsed file:
	 * counts, duplicates needing a decision, and traits needing review. A
	 * character this chronicle already holds also shows what differs from
	 * that sheet (1.0.0-review F-044).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function review( $request ) {
		$game  = $this->resolve_game( $request['game_slug'] );
		$offer = is_wp_error( $game ) ? $game : $this->transfer_in_state( $request, 'host', 'offered' );
		if ( is_wp_error( $offer ) ) {
			return $offer;
		}

		try {
			$parsed = GEX_Xml_Parser::parse_string( (string) $offer->payload );
		} catch ( \RuntimeException $e ) {
			return $this->error( 'parse_failed', $e->getMessage(), 500 );
		}

		$preview               = Import_Controller::build_preview( $parsed, $game->slug, (int) $game->id, [], 'XML' );
		$preview['duplicates'] = Import_Controller::with_changes( $preview['duplicates'], $parsed, $game->slug );

		return $this->success( [
			'transfer' => self::without_payload( $offer ),
			'preview'  => array_merge( [ 'job_id' => 'transfer-' . (int) $offer->id ], $preview ),
		] );
	}

	/**
	 * Accepts a waiting offer with the Storyteller's decisions - the same
	 * `resolutions` shape an import commit takes. The home site is asked
	 * again first, so an offer its Storytellers have since cancelled cannot
	 * be accepted; then the character is imported in one transaction, the
	 * row moves to `visiting`, and the stored payload is discarded. A
	 * character arriving back at the chronicle it left also closes that
	 * chronicle's outbound row as `returned`.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function accept( $request ) {
		$game  = $this->resolve_game( $request['game_slug'] );
		$offer = is_wp_error( $game ) ? $game : $this->transfer_in_state( $request, 'host', 'offered' );
		if ( is_wp_error( $offer ) ) {
			return $offer;
		}

		$payload = (string) $offer->payload;
		if ( ! preg_match( '/code=([A-Za-z0-9-]+)/', $payload, $m ) ) {
			return $this->error( 'verify_failed', __( 'This transfer carries no verification code.', 'beyond-elysium' ), 400 );
		}
		$verified = $this->verify_with_home( (string) $offer->home_site, $m[1], $payload );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		try {
			$parsed = GEX_Xml_Parser::parse_string( $payload );
		} catch ( \RuntimeException $e ) {
			return $this->error( 'parse_failed', $e->getMessage(), 500 );
		}

		$resolutions = (array) ( $request->get_param( 'resolutions' ) ?? [] );
		$preview     = Import_Controller::build_preview( $parsed, $game->slug, (int) $game->id, $resolutions, 'XML' );
		$block       = Import_Controller::blocking_reason( $preview, $resolutions );
		if ( $block !== null ) {
			return $this->error( $block['code'], $block['message'], $block['status'] );
		}
		foreach ( $preview['duplicates'] as $dup ) {
			if ( ( $resolutions['duplicates'][ $dup['character'] ] ?? '' ) === 'skip' ) {
				return $this->error( 'nothing_to_accept', __( 'Skipping the character leaves nothing to accept - refuse the transfer instead.', 'beyond-elysium' ), 400 );
			}
		}

		$savepoint = Transaction::begin( 'be_transfer_accept' );

		// Checked again, row-locked, now that the home chronicle has answered: another Storyteller's
		// accept that finished during that call must not see this one import the character again
		// (1.0.0-review F-070).
		$still_offered = Transfer::find_for_update( (int) $offer->id );
		if ( $still_offered === null || $still_offered->state !== 'offered' ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'transfer_already_answered', __( 'This offer was already answered by someone else. Reload to see where it stands.', 'beyond-elysium' ), 409 );
		}

		try {
			$result    = Import_Controller::apply_import( (int) $game->id, $game->slug, $parsed, 'transfer.gex', $resolutions );
			$character = $result['characters'][0] ?? null;
			if ( $character === null ) {
				throw new \RuntimeException( 'The transfer document held no character to import.' );
			}

			Transfer::transition( (int) $offer->id, 'visiting', [ 'character_id' => (int) $character['id'], 'payload' => null ] );

			$left_from_here = Transfer::find_open( (string) $offer->character_uuid, 'outbound' );
			if ( $left_from_here !== null && $left_from_here->home_slug === $game->slug ) {
				Transfer::transition( (int) $left_from_here->id, 'returned' );
			}
		} catch ( \Throwable $e ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'commit_failed', $e->getMessage(), 500 );
		}
		Transaction::commit( $savepoint );

		return $this->success( [
			'transfer'  => self::without_payload( Transfer::find( (int) $offer->id ) ),
			'character' => $character,
		] );
	}

	/**
	 * The transfer named in the URL, when it belongs to this chronicle's side
	 * of the journey and is in the state an action requires.
	 *
	 * @param \WP_REST_Request $request
	 * @param string           $side 'home' (outbound rows) | 'host' (inbound rows).
	 * @param string           $state
	 * @return object|\WP_Error
	 */
	private function transfer_in_state( $request, string $side, string $state ) {
		$transfer = Transfer::find( (int) $request['id'] );
		$ours     = $transfer !== null && ( $side === 'home'
			? $transfer->direction === 'outbound' && $transfer->home_slug === $request['game_slug']
			: $transfer->direction === 'inbound' && $transfer->host_slug === $request['game_slug'] );

		if ( ! $ours ) {
			return $this->error( 'transfer_not_found', __( 'Transfer not found for this chronicle.', 'beyond-elysium' ), 404 );
		}
		if ( $transfer->state !== $state ) {
			return $this->error(
				'invalid_state',
				sprintf(
					/* translators: 1: the state required for this action, 2: the transfer's actual current state */
					__( 'This action requires the transfer to be "%1$s", but it is "%2$s".', 'beyond-elysium' ),
					$state,
					$transfer->state
				),
				409
			);
		}
		return $transfer;
	}

	/**
	 * A transfer row without its stored payload - the full character document
	 * is the import's to read, not every response's to carry.
	 *
	 * @param object|null $transfer
	 * @return object|null
	 */
	private static function without_payload( ?object $transfer ): ?object {
		if ( $transfer !== null ) {
			unset( $transfer->payload );
		}
		return $transfer;
	}

	/**
	 * Calls the claimed home site's own public verify endpoint and confirms
	 * every check §8.2 requires before an inbound payload can be trusted:
	 * the code resolves, is not revoked, its attested sheet hash matches
	 * this exact payload, and the issuer really is the site it claims to
	 * be. Never trusts the POST body alone - this callback is the whole
	 * authentication mechanism (§8.2).
	 *
	 * @param string $home_site
	 * @param string $short_code
	 * @param string $payload
	 * @return array<string,mixed>|\WP_Error The home site's verified response.
	 */
	private function verify_with_home( string $home_site, string $short_code, string $payload ) {
		$response = wp_safe_remote_get(
			$home_site . '/wp-json/be/v1/verify/' . rawurlencode( $short_code ),
			[ 'timeout' => 15 ]
		);

		if ( is_wp_error( $response ) ) {
			return $this->error( 'verify_unreachable', __( 'Could not reach the home chronicle to verify this transfer.', 'beyond-elysium' ), 502 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status === 404 ) {
			return $this->error( 'verify_failed', __( 'The home chronicle does not recognize this verification code.', 'beyond-elysium' ), 400 );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $this->error( 'verify_failed', __( 'The home chronicle returned an unreadable verification response.', 'beyond-elysium' ), 502 );
		}

		if ( empty( $data['valid'] ) || ! empty( $data['revoked'] ) ) {
			return $this->error( 'verify_failed', __( 'This transfer is no longer valid according to the home chronicle.', 'beyond-elysium' ), 400 );
		}

		// Only a code the home chronicle issued for a transfer vouches for one. A verified export's
		// code is a player's to mint, and its document passes the hash below with a uuid added by
		// hand (1.0.0-review F-059).
		if ( ( $data['kind'] ?? '' ) !== 'transfer' ) {
			return $this->error( 'verify_failed', __( 'The home chronicle did not issue this code for a transfer.', 'beyond-elysium' ), 400 );
		}

		// sheet_hash was hashed from the canonical (no verification URL/uuid) document, never
		// the one actually transmitted - reconstruct that same canonical form before comparing
		// (§8.2's own "sha256(canonicalize(payload))"), the same way Verify_Controller's own
		// still_matches() always re-derives its comparison hash rather than hashing a held file.
		$expected_hash = (string) ( $data['attested']['sheet_hash'] ?? '' );
		$actual_hash   = hash( 'sha256', Character_Exporter::canonicalize_transfer_payload( $payload ) );
		if ( $expected_hash === '' || ! hash_equals( $expected_hash, $actual_hash ) ) {
			return $this->error( 'verify_failed', __( 'This payload does not match what the home chronicle attested to.', 'beyond-elysium' ), 400 );
		}

		if ( ! hash_equals( untrailingslashit( (string) ( $data['issuer']['site'] ?? '' ) ), untrailingslashit( $home_site ) ) ) {
			return $this->error( 'verify_failed', __( 'The verifying site does not match the claimed home site.', 'beyond-elysium' ), 400 );
		}

		return $data;
	}

	/**
	 * @return \WP_Error
	 */
	private function already_travelling(): \WP_Error {
		return $this->error( 'already_travelling', __( 'This character already has an open outbound transfer.', 'beyond-elysium' ), 409 );
	}

	/**
	 * @return \WP_Error
	 */
	private function already_offered(): \WP_Error {
		return $this->error( 'already_offered', __( 'This character is already waiting for review or visiting here.', 'beyond-elysium' ), 409 );
	}

	/**
	 * @return \WP_Error
	 */
	private function transfer_not_recorded(): \WP_Error {
		return $this->error( 'create_failed', __( 'Failed to create transfer.', 'beyond-elysium' ), 500 );
	}

	/**
	 * POSTs a transfer payload to another chronicle's inbound route. Uses
	 * `wp_safe_remote_post()`, not `wp_remote_post()`, since the target URL
	 * is operator-supplied rather than a hardcoded trusted endpoint.
	 *
	 * @param string $host_site
	 * @param string $host_slug
	 * @param string $xml
	 * @param object $game
	 * @param string $short_code
	 * @return array{ok:bool,body:array<string,mixed>|null,note?:string} `ok` means the host answered with a 2xx.
	 */
	private function post_to_host( string $host_site, string $host_slug, string $xml, object $game, string $short_code ): array {
		$url = untrailingslashit( $host_site ) . '/wp-json/' . $this->namespace . '/' . $host_slug . '/transfers/inbound';

		$response = wp_safe_remote_post( $url, [
			'timeout' => 20,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => (string) wp_json_encode( [
				'payload'        => $xml,
				'short_code'     => $short_code,
				'home_site'      => home_url(),
				'home_slug'      => $game->slug,
				'home_chronicle' => $game->name,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'body' => null, 'note' => $response->get_error_message() ];
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return [ 'ok' => $status >= 200 && $status < 300, 'body' => is_array( $body ) ? $body : null ];
	}

	/**
	 * Counts one inbound request against the caller's IP for the rolling
	 * minute, the same transient pattern as Verify_Controller's own limit.
	 */
	private static function is_rate_limited(): bool {
		$ip  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$key = 'be_transfer_rl_' . sha1( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= self::INBOUND_RATE_LIMIT ) {
			return true;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Looks up the game record for the given slug and returns a 404 error
	 * when no game matches it. Matches every other controller's own copy
	 * of this helper - not shared via `Base_Controller`.
	 *
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	protected function resolve_game( string $game_slug ) {
		$game = Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		return $game;
	}
}
