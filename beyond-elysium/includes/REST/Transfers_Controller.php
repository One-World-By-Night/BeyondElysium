<?php

namespace BeyondElysium\REST;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Character_Exporter;
use BeyondElysium\Services\GEX_Xml_Parser;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for chronicle-to-chronicle character transfer (GX-8/9).
 * The outbound routes (initiate/acknowledge/release/decline/list) are
 * ordinary, capability-gated ST actions on the home chronicle. `inbound` is
 * this plugin's SECOND unauthenticated route (after `Verify_Controller`) -
 * it has to be, since the caller is another WordPress installation with no
 * session on this one - and carries the same posture: trust comes from an
 * independent callback to the sender's own `/verify/{code}`, never from the
 * POST body alone.
 *
 * The payload crossing the wire is a real `.gex` XML document (the same one
 * `Character_Exporter`/`GEX_Xml_Parser` already read and write), not a
 * bespoke envelope - one serializer for both the file download and the wire
 * transfer, and an ST can read what left (§8.2).
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-8, GX-9, §8
 */
class Transfers_Controller extends Base_Controller {

	protected $rest_base = 'transfers';

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

		foreach ( [ 'acknowledge', 'release', 'decline' ] as $action ) {
			register_rest_route( $this->namespace, "/(?P<game_slug>[a-z0-9\\-]+)/transfers/(?P<id>\\d+)/{$action}", [
				[
					'methods'             => 'POST',
					'callback'            => [ $this, $action ],
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
				// this handler's own callback-verify against the sender's home site, never
				// from the request being authenticated.
				'permission_callback' => '__return_true',
			],
		] );
	}

	/**
	 * Lists every transfer row touching this chronicle, either direction,
	 * newest first.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$table = Manager::table( 'character_transfers' );
		$rows  = Manager::get_results(
			"SELECT * FROM {$table} WHERE home_slug = %s OR host_slug = %s ORDER BY id DESC LIMIT 200",
			$game->slug,
			$game->slug
		);

		return $this->success( $rows );
	}

	/**
	 * Initiates an outbound transfer: exports the character as a transfer
	 * document (real verification attestation embedded, uuid carried),
	 * takes a snapshot of the sheet as it leaves, records a `pending`
	 * transfer row, and - when a host site was given - POSTs the payload
	 * there directly. A clean, host-confirmed acceptance moves the row
	 * straight to `abroad` in this same request; anything else (no host
	 * given, the host unreachable, or the host parking it for its own ST's
	 * review) leaves the row `pending`, with the exported document returned
	 * so the offline carrier - download and email it - always works.
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
			return $this->error( 'already_travelling', __( 'This character already has an open outbound transfer.', 'beyond-elysium' ), 409 );
		}

		$host_site  = trim( (string) $request->get_param( 'host_site' ) );
		$host_slug  = trim( (string) $request->get_param( 'host_slug' ) );
		$has_host   = $host_site !== '' && $host_slug !== '';

		// Full ST content, never hide_st - the receiving Storyteller needs to run this
		// character for real, exactly the reasoning §8.2 gives for choosing XML at all.
		$export = Character_Exporter::export( $character_id, [ 'hide_st' => false, 'as_transfer' => true ] );

		$snapshot_id = Snapshot::create( $character_id, null );

		$transfer_id = Transfer::create( [
			'character_uuid' => $character->uuid,
			'character_id'   => $character_id,
			'direction'      => 'outbound',
			'state'          => 'pending',
			'home_slug'      => $game->slug,
			'home_site'      => home_url(),
			'home_chronicle' => $game->name,
			'host_slug'      => $has_host ? $host_slug : null,
			'host_site'      => $has_host ? untrailingslashit( $host_site ) : null,
			'snapshot_id'    => $snapshot_id,
			'payload_hash'   => hash( 'sha256', $export['xml'] ),
			'initiated_by'   => get_current_user_id(),
		] );

		if ( ! $has_host ) {
			return $this->success( [
				'transfer' => Transfer::find( $transfer_id ),
				'xml'      => $export['xml'],
				'warnings' => $export['warnings'],
			] );
		}

		$post = $this->post_to_host( $host_site, $host_slug, $export['xml'], $game, $character->uuid );

		if ( $post['ok'] && ( $post['body']['accepted'] ?? false ) ) {
			Transfer::transition( $transfer_id, 'abroad', [
				'host_chronicle' => $post['body']['host_chronicle'] ?? null,
			] );
		} elseif ( $post['ok'] && ! empty( $post['body']['error'] ) ) {
			Transfer::transition( $transfer_id, 'declined', [ 'notes' => (string) $post['body']['error'] ] );
		} else {
			// Unreachable host, timeout, or a needs-review response - the row stays pending
			// rather than guessing; the offline carrier (the file this response still
			// includes) always works regardless of what the host's server is doing.
			Transfer::transition( $transfer_id, 'pending', [ 'notes' => $post['note'] ?? null ] );
		}

		return $this->success( [
			'transfer' => Transfer::find( $transfer_id ),
			'xml'      => $export['xml'],
			'warnings' => $export['warnings'],
			'host'     => $post['body'] ?? null,
		] );
	}

	/**
	 * Home ST manually marks a still-pending outbound transfer as received
	 * abroad - the offline carrier's own path to `abroad`, for when the
	 * host confirmed receipt some other way (email, a phone call) rather
	 * than through the online POST.
	 */
	public function acknowledge( $request ) {
		return $this->manual_transition( $request, 'pending', 'abroad' );
	}

	/**
	 * Home ST permanently gives the character up - a real move, not travel.
	 * Only legal from `abroad`: a character still merely `pending` hasn't
	 * been confirmed to have arrived anywhere yet.
	 */
	public function release( $request ) {
		return $this->manual_transition( $request, 'abroad', 'released' );
	}

	/**
	 * Home ST cancels a still-pending transfer - the host never took it, or
	 * never will.
	 */
	public function decline( $request ) {
		return $this->manual_transition( $request, 'pending', 'declined' );
	}

	/**
	 * Shared body for the three manual, single-legal-predecessor state
	 * transitions above.
	 *
	 * @param \WP_REST_Request $request
	 * @param string           $from_state
	 * @param string           $to_state
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function manual_transition( $request, string $from_state, string $to_state ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$transfer = Transfer::find( (int) $request['id'] );
		if ( ! $transfer || $transfer->home_slug !== $request['game_slug'] || $transfer->direction !== 'outbound' ) {
			return $this->error( 'transfer_not_found', __( 'Transfer not found for this chronicle.', 'beyond-elysium' ), 404 );
		}
		if ( $transfer->state !== $from_state ) {
			return $this->error(
				'invalid_state',
				sprintf(
					/* translators: 1: the state required for this action, 2: the transfer's actual current state */
					__( 'This action requires the transfer to be "%1$s", but it is "%2$s".', 'beyond-elysium' ),
					$from_state,
					$transfer->state
				),
				409
			);
		}

		Transfer::transition( (int) $transfer->id, $to_state );
		return $this->success( Transfer::find( (int) $transfer->id ) );
	}

	/**
	 * Receives an inbound transfer payload from another chronicle - possibly
	 * on another WordPress installation entirely. Never trusts the POST body
	 * on its own: calls back to the claimed home site's own public verify
	 * endpoint and only proceeds once that confirms the payload is genuine,
	 * current, and really issued by the site it claims to be from. A clean
	 * import (nothing needs a human's review) creates or updates the local
	 * character and records an `inbound` transfer row in `visiting` state;
	 * anything that needs review is parked as an ordinary import job instead
	 * of guessed at, resolvable through the existing Import page like any
	 * other file - the same graceful path the offline carrier always uses.
	 * Matches the work order (§9, GX-9) literally: callback-verify, then
	 * `build_preview` → `blocking_reason` → `apply_import`.
	 *
	 * **Declared limitation, carried from the design doc (§8.2), not fixed
	 * here:** this authenticates the *issuer*, not the *requester* - it
	 * proves a payload really was attested to by the site it claims to be
	 * from, not that this chronicle asked for or wants it. An attacker who
	 * stands up their own genuine Beyond Elysium install can attest to and
	 * send an unsolicited, fabricated character here, and a clean one would
	 * be created with no human in the loop. This is no wider a hole than
	 * the existing plain Import page already is (anyone holding
	 * `be_import` can upload arbitrary fabricated `.gex` content today) -
	 * the difference is `inbound` needs no authentication at all, by
	 * necessity, since the caller has no session on this install to hold a
	 * capability with. Logged rather than silently accepted or silently
	 * over-built past what the work order asks for.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function inbound( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$payload        = (string) $request->get_param( 'payload' );
		$short_code     = (string) $request->get_param( 'short_code' );
		$home_site      = untrailingslashit( (string) $request->get_param( 'home_site' ) );
		$home_slug      = (string) $request->get_param( 'home_slug' );
		$home_chronicle = (string) $request->get_param( 'home_chronicle' );
		$character_uuid = (string) $request->get_param( 'character_uuid' );

		if ( $payload === '' || $short_code === '' || $home_site === '' ) {
			return $this->error( 'invalid_param', __( 'A transfer payload, code, and home site are required.', 'beyond-elysium' ), 400 );
		}

		$check = $this->verify_with_home( $home_site, $short_code, $payload );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		try {
			$parsed = GEX_Xml_Parser::parse_string( $payload );
		} catch ( \RuntimeException $e ) {
			return $this->error( 'parse_failed', $e->getMessage(), 400 );
		}

		$preview = Import_Controller::build_preview( $parsed, $game->slug, (int) $game->id, [], 'XML' );
		$block   = Import_Controller::blocking_reason( $preview, [] );

		$transfer_id = Transfer::create( [
			'character_uuid' => $character_uuid,
			'direction'      => 'inbound',
			'state'          => $block === null ? 'visiting' : 'offered',
			'home_slug'      => $home_slug,
			'home_site'      => $home_site,
			'home_chronicle' => $home_chronicle,
			'host_slug'      => $game->slug,
			'host_site'      => home_url(),
			'host_chronicle' => $game->name,
			'payload_hash'   => hash( 'sha256', $payload ),
			'initiated_by'   => get_current_user_id(),
		] );

		if ( $block !== null ) {
			// Parked exactly like an ordinary uploaded file's job would be - the host's own
			// ST resolves it through the existing Import page, not a transfer-specific UI.
			$job_id = wp_generate_uuid4();
			set_transient(
				'be_import_job_' . $job_id,
				[ 'game_id' => (int) $game->id, 'parsed' => $parsed, 'preview' => $preview, 'source_file' => 'transfer.gex', 'format' => 'XML' ],
				HOUR_IN_SECONDS
			);
			return $this->success( [
				'accepted' => false,
				'error'    => $block['message'],
				'job_id'   => $job_id,
			], 200 );
		}

		global $wpdb;
		$nested = (int) $wpdb->get_var( 'SELECT @@autocommit' ) === 0;
		$wpdb->query( $nested ? 'SAVEPOINT be_transfer_inbound' : 'START TRANSACTION' );

		try {
			$result = Import_Controller::apply_import( (int) $game->id, $game->slug, $parsed, 'transfer.gex', [] );
		} catch ( \Throwable $e ) {
			$wpdb->query( $nested ? 'ROLLBACK TO SAVEPOINT be_transfer_inbound' : 'ROLLBACK' );
			return $this->error( 'commit_failed', $e->getMessage(), 500 );
		}

		$wpdb->query( $nested ? 'RELEASE SAVEPOINT be_transfer_inbound' : 'COMMIT' );

		$created_character = $result['characters'][0] ?? null;
		if ( $created_character !== null ) {
			Transfer::transition( $transfer_id, 'visiting', [ 'character_id' => $created_character['id'] ] );
		}

		return $this->success( [
			'accepted'       => true,
			'host_chronicle' => $game->name,
			'character'      => $created_character,
		] );
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
	 * @return true|\WP_Error
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

		return true;
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
	 * @param string $character_uuid
	 * @return array{ok:bool,body:array<string,mixed>|null,note?:string}
	 */
	private function post_to_host( string $host_site, string $host_slug, string $xml, object $game, string $character_uuid ): array {
		$attestation_row = null;
		if ( preg_match( '/code=([A-Za-z0-9-]+)/', $xml, $m ) ) {
			$attestation_row = Attestation::resolve( $m[1] );
		}

		$url = untrailingslashit( $host_site ) . '/wp-json/' . $this->namespace . '/' . $host_slug . '/transfers/inbound';

		$response = wp_safe_remote_post( $url, [
			'timeout' => 20,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'payload'        => $xml,
				'short_code'     => $attestation_row->short_code ?? '',
				'home_site'      => home_url(),
				'home_slug'      => $game->slug,
				'home_chronicle' => $game->name,
				'character_uuid' => $character_uuid,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'body' => null, 'note' => $response->get_error_message() ];
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return [ 'ok' => true, 'body' => is_array( $body ) ? $body : null ];
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
