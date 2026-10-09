<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Notifications;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Character_Exporter;
use BeyondElysium\Services\GEX_Xml_Parser;
use BeyondElysium\Services\Keep_Current;
use BeyondElysium\Services\Not_Exportable_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for chronicle-to-chronicle character transfer.
 */
class Transfers_Controller extends Base_Controller {

	protected $rest_base = 'transfers';

	/**
	 * Inbound offers accepted per IP per rolling minute.
	 */
	const INBOUND_RATE_LIMIT = 10;

	/**
	 * Offers one chronicle may hold waiting for review before new ones are turned away.
	 */
	const MAX_WAITING_OFFERS = 50;

	/**
	 * Changes forwarded from one host a chronicle may hold waiting before that host is turned away.
	 */
	const MAX_WAITING_FROM_HOST = 50;

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

		// Home side. There is no Acknowledge - the host's own accept tells home by itself.
		foreach ( [ 'release', 'decline' ] as $action ) {
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
		foreach ( [ 'send-home' => 'send_home', 'retain' => 'retain', 'keep-current' => 'keep_current', 'keep-current/accept' => 'keep_current_accept', 'note' => 'note' ] as $path => $method ) {
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
				// Called by another WordPress install, so it carries no session here.
				'permission_callback' => '__return_true',
			],
		] );

		// The accepted/ended cross-site calls: a host telling home its row moved, or either side telling the
		// other a visit ended. Both are called by another WordPress install, so neither carries a session here.
		foreach ( [ 'from-host' => 'from_host', 'from-home' => 'from_home' ] as $path => $method ) {
			register_rest_route( $this->namespace, "/(?P<game_slug>[a-z0-9\\-]+)/transfers/(?P<uuid>[0-9a-fA-F\\-]+)/{$path}", [
				[
					'methods'             => 'POST',
					'callback'            => [ $this, $method ],
					'permission_callback' => '__return_true',
				],
			] );
		}
	}

	/**
	 * Lists every transfer row this chronicle is party to on this site.
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
	 * Initiates an outbound transfer: exports the character as a transfer document (real verification attestation
	 * embedded, uuid carried), takes a snapshot of the sheet as it leaves, records a `pending` transfer row.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function initiate( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		if ( \BeyondElysium\Services\Demo_Chronicle::is_demo( $game ) ) {
			return $this->error( 'demo_locked', __( 'A demo chronicle cannot send characters to another site.', 'beyond-elysium' ), 403 );
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

		// Full ST content, never hide_st.
		try {
			$export = Character_Exporter::export( $character_id, [ 'hide_st' => false, 'as_transfer' => true ] );
		} catch ( Not_Exportable_Exception $e ) {
			return $this->error( 'not_exportable', $e->getMessage(), 422 );
		}

		$snapshot_id  = Snapshot::create( $character_id, null );
		$keep_current = (bool) $request->get_param( 'keep_current' );

		try {
			$transfer_id = Transfer::create( [
				'character_uuid' => $character->uuid,
				'character_id'   => $character_id,
				'character_name' => $character->name,
				'direction'      => 'outbound',
				'state'          => 'offered',
				'home_slug'      => $game->slug,
				'home_site'      => home_url(),
				'home_chronicle' => $game->name,
				'host_slug'      => $has_host ? $host_slug : null,
				'host_site'      => $has_host ? untrailingslashit( $host_site ) : null,
				'attestation_id' => $export['attestation_id'] ?? null,
				'snapshot_id'    => $snapshot_id,
				'payload_hash'   => hash( 'sha256', $export['xml'] ),
				'initiated_by'   => get_current_user_id(),
				'keep_current'   => $keep_current,
			] );
		} catch ( \RuntimeException $e ) {
			$already_open = $has_host
				? Transfer::find_open_visit( $character->uuid, untrailingslashit( $host_site ), $host_slug, 'outbound' )
				: Transfer::find_open( $character->uuid, 'outbound' );
			return $already_open !== null ? $this->already_travelling() : $this->transfer_not_recorded();
		}

		if ( ! $has_host ) {
			return $this->success( [
				'transfer' => Transfer::find( $transfer_id ),
				'xml'      => $export['xml'],
				'warnings' => $export['warnings'],
			] );
		}

		$post = $this->post_to_host( $host_site, $host_slug, $export['xml'], $game, (string) ( $export['short_code'] ?? '' ), $keep_current );
		$body = $post['body'] ?? [];

		if ( $post['ok'] && ! empty( $body['accepted'] ) ) {
			// A host that accepted on arrival.
			Transfer::transition( $transfer_id, 'visiting', [ 'host_chronicle' => $body['host_chronicle'] ?? null ] );
		} elseif ( $post['ok'] && ! empty( $body['pending_review'] ) ) {
			Transfer::transition( $transfer_id, 'offered', [
				'host_chronicle' => $body['host_chronicle'] ?? null,
				'notes'          => __( 'Waiting for the host chronicle\'s Storytellers to accept it.', 'beyond-elysium' ),
			] );
		} else {
			// Unreachable host, or the host turned the offer away.
			Transfer::transition( $transfer_id, 'offered', [ 'notes' => $post['note'] ?? ( $body['message'] ?? null ) ] );
		}

		return $this->success( [
			'transfer' => Transfer::find( $transfer_id ),
			'xml'      => $export['xml'],
			'warnings' => $export['warnings'],
			'host'     => $post['body'] ?? null,
		] );
	}

	/**
	 * Home ST permanently gives a visiting character up.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function release( $request ) {
		return $this->manual_transition( $request, 'home', 'visiting', 'released', [], true );
	}

	/**
	 * Home ST cancels a still-open offer.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function decline( $request ) {
		$response = $this->manual_transition( $request, 'home', 'offered', 'declined' );
		if ( ! is_wp_error( $response ) && ! empty( $response->get_data()->attestation_id ) ) {
			Attestation::revoke( (int) $response->get_data()->attestation_id );
		}
		return $response;
	}

	/**
	 * Host ST sends a visiting character back.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function send_home( $request ) {
		return $this->manual_transition( $request, 'host', 'visiting', 'ended', [], true );
	}

	/**
	 * Host ST keeps a visiting character for good.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retain( $request ) {
		return $this->manual_transition( $request, 'host', 'visiting', 'retained', [], true );
	}

	/**
	 * Host ST refuses an offer.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function refuse( $request ) {
		return $this->manual_transition( $request, 'host', 'offered', 'refused', [ 'payload' => null ] );
	}

	/**
	 * Shared body for the manual, single-legal-predecessor state transitions.
	 *
	 * @param \WP_REST_Request     $request
	 * @param string               $side 'home' | 'host'.
	 * @param string               $from_state
	 * @param string               $to_state
	 * @param array<string,mixed>  $extra Columns to set alongside the state.
	 * @param bool                 $notify Whether this ending calls the other side of the visit.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function manual_transition( $request, string $side, string $from_state, string $to_state, array $extra = [], bool $notify = false ) {
		$transfer = $this->transfer_in_state( $request, $side, $from_state );
		if ( is_wp_error( $transfer ) ) {
			return $transfer;
		}

		Transfer::transition( (int) $transfer->id, $to_state, $extra );
		$updated = Transfer::find( (int) $transfer->id );

		if ( $side === 'host' && $to_state === 'ended' && $updated !== null && $updated->character_id !== null ) {
			Character::update_header( (int) $updated->character_id, [ 'status' => 'inactive' ] );
		}

		if ( $notify && $updated !== null && $updated->character_id !== null ) {
			$this->tell_other_side( $updated, 'end', Character::find( (int) $updated->character_id ), [ 'state' => $updated->state ], $updated->state );
		}

		return $this->success( self::without_payload( $updated ) );
	}

	/**
	 * Home or host turns keep-current on or off for an open visit, telling the other side when one exists.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function keep_current( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$transfer = Transfer::find( (int) $request['id'] );
		$ours     = $transfer !== null && (
			( $transfer->direction === 'outbound' && $transfer->home_slug === $game->slug )
			|| ( $transfer->direction === 'inbound' && $transfer->host_slug === $game->slug )
		);
		if ( ! $ours ) {
			return $this->error( 'transfer_not_found', __( 'Transfer not found for this chronicle.', 'beyond-elysium' ), 404 );
		}
		if ( ! in_array( $transfer->state, [ 'offered', 'visiting' ], true ) ) {
			return $this->error( 'invalid_state', __( 'This visit is not open.', 'beyond-elysium' ), 409 );
		}

		$on    = (bool) $request->get_param( 'on' );
		$extra = $on ? [ 'keep_current' => 1 ] : [ 'keep_current' => 0, 'keep_current_accepted' => 0 ];
		Transfer::transition( (int) $transfer->id, $transfer->state, $extra );
		$updated = Transfer::find( (int) $transfer->id );

		if ( $updated !== null && $updated->state === 'visiting' && $updated->character_id !== null ) {
			$this->tell_other_side( $updated, 'keep_current', Character::find( (int) $updated->character_id ), [ 'on' => $on ? 1 : 0 ], $on ? '1' : '0' );
		}

		return $this->success( self::without_payload( $updated ) );
	}

	/**
	 * The host agrees to keep a visiting character current, on an already-open visit rather than at review time.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function keep_current_accept( $request ) {
		$transfer = $this->transfer_in_state( $request, 'host', 'visiting' );
		if ( is_wp_error( $transfer ) ) {
			return $transfer;
		}

		Transfer::transition( (int) $transfer->id, 'visiting', [ 'keep_current' => 1, 'keep_current_accepted' => 1 ] );
		$updated = Transfer::find( (int) $transfer->id );

		if ( $updated !== null && $updated->character_id !== null ) {
			$this->tell_other_side( $updated, 'keep_current_accept', Character::find( (int) $updated->character_id ) );
		}

		return $this->success( self::without_payload( $updated ) );
	}

	/**
	 * Receives a transfer offer from another chronicle.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function inbound( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		if ( \BeyondElysium\Services\Demo_Chronicle::is_demo( $game ) ) {
			return $this->error( 'demo_locked', __( 'A demo chronicle cannot receive a transfer offer.', 'beyond-elysium' ), 403 );
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
				'keep_current'   => (bool) $request->get_param( 'keep_current' ),
			] );
		} catch ( \RuntimeException $e ) {
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
	 * Shows a waiting offer the way the Import page shows a parsed file: counts, duplicates needing a decision, and
	 * traits needing review.
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
	 * Accepts a waiting offer with the Storyteller's decisions.
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

			$accept_extra = [ 'character_id' => (int) $character['id'], 'payload' => null ];
			if ( $offer->keep_current && $request->get_param( 'keep_current_accepted' ) ) {
				$accept_extra['keep_current_accepted'] = 1;
			}
			Transfer::transition( (int) $offer->id, 'visiting', $accept_extra );
		} catch ( \Throwable $e ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'commit_failed', $e->getMessage(), 500 );
		}
		Transaction::commit( $savepoint );

		$updated = Transfer::find( (int) $offer->id );
		if ( $updated !== null ) {
			$this->tell_other_side( $updated, 'accept', Character::find( (int) $character['id'] ), [
				'host_chronicle'        => $updated->host_chronicle,
				'keep_current_accepted' => $updated->keep_current_accepted ? 1 : 0,
			] );
		}

		return $this->success( [
			'transfer'  => self::without_payload( $updated ),
			'character' => $character,
		] );
	}

	/**
	 * Home's end of the accept/end handshake: a host telling this chronicle its own row has moved, verified against
	 * the host's own `/verify/{code}` before anything here is written.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function from_host( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		if ( self::is_rate_limited() ) {
			return $this->error( 'rate_limited', __( 'Too many requests. Please try again shortly.', 'beyond-elysium' ), 429 );
		}

		// Read from the route's own URL segment, not the merged request params.
		$uuid      = strtolower( (string) ( $request->get_url_params()['uuid'] ?? '' ) );
		$type      = (string) $request->get_param( 'type' );
		$host_site = untrailingslashit( (string) $request->get_param( 'host_site' ) );
		$host_slug = (string) $request->get_param( 'host_slug' );
		$code      = (string) $request->get_param( 'code' );

		if ( $uuid === '' || $host_site === '' || $host_slug === '' || $code === '' ) {
			return $this->error( 'invalid_param', __( 'A visit identity and verification code are required.', 'beyond-elysium' ), 400 );
		}

		// A pairing request names no existing visit at all - home has never heard of this character.
		if ( $type === 'pairing' ) {
			return $this->receive_pairing_request( $game, $uuid, $host_site, $host_slug, $code, $request );
		}

		$transfer = Transfer::find_open_visit( $uuid, $host_site, $host_slug, 'outbound' );
		if ( $transfer === null || $transfer->home_slug !== $game->slug ) {
			return $this->error( 'transfer_not_found', __( 'No open visit matches this call.', 'beyond-elysium' ), 404 );
		}

		if ( $type === 'accept' ) {
			if ( $transfer->state !== 'offered' ) {
				return $this->error( 'invalid_state', __( 'This visit is not waiting to be accepted.', 'beyond-elysium' ), 409 );
			}
			$verified = $this->verify_visit_code( $host_site, $code, self::visit_hash( $uuid, $host_site, $host_slug, $type ) );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			$accept_extra = [
				'host_chronicle' => (string) ( $request->get_param( 'host_chronicle' ) ?: $transfer->host_chronicle ),
			];
			if ( $request->get_param( 'keep_current_accepted' ) ) {
				$accept_extra['keep_current_accepted'] = 1;
				$accept_extra['keep_current']          = 1;
			}
			Transfer::transition( (int) $transfer->id, 'visiting', $accept_extra );
			return $this->success( [ 'ok' => true ] );
		}

		if ( $type === 'end' ) {
			if ( $transfer->state !== 'visiting' ) {
				return $this->error( 'invalid_state', __( 'This visit is not open to end.', 'beyond-elysium' ), 409 );
			}
			$their_state = (string) $request->get_param( 'state' );
			$mapped      = self::mirror_state( $their_state );
			if ( $mapped === null ) {
				return $this->error( 'invalid_param', __( 'Unknown ending state.', 'beyond-elysium' ), 400 );
			}
			$verified = $this->verify_visit_code( $host_site, $code, self::visit_hash( $uuid, $host_site, $host_slug, $type, $their_state ) );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			Transfer::transition( (int) $transfer->id, $mapped );
			return $this->success( [ 'ok' => true ] );
		}

		if ( $type === 'keep_current' ) {
			$on = (bool) $request->get_param( 'on' );
			$verified = $this->verify_visit_code( $host_site, $code, self::visit_hash( $uuid, $host_site, $host_slug, $type, $on ? '1' : '0' ) );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			Transfer::transition( (int) $transfer->id, $transfer->state, $on
				? [ 'keep_current' => 1 ]
				: [ 'keep_current' => 0, 'keep_current_accepted' => 0 ] );
			return $this->success( [ 'ok' => true ] );
		}

		if ( $type === 'keep_current_accept' ) {
			if ( $transfer->state !== 'visiting' ) {
				return $this->error( 'invalid_state', __( 'This visit is not open.', 'beyond-elysium' ), 409 );
			}
			$verified = $this->verify_visit_code( $host_site, $code, self::visit_hash( $uuid, $host_site, $host_slug, $type ) );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			Transfer::transition( (int) $transfer->id, 'visiting', [ 'keep_current' => 1, 'keep_current_accepted' => 1 ] );
			return $this->success( [ 'ok' => true ] );
		}

		if ( $type === 'moved' ) {
			$new_host_slug      = (string) $request->get_param( 'new_host_slug' );
			$new_host_chronicle = (string) $request->get_param( 'new_host_chronicle' );
			if ( $new_host_slug === '' ) {
				return $this->error( 'invalid_param', __( 'A new chronicle slug is required.', 'beyond-elysium' ), 400 );
			}
			$fields        = [ 'type' => 'moved', 'new_host_slug' => $new_host_slug, 'new_host_chronicle' => $new_host_chronicle ];
			$expected_hash = hash( 'sha256', (string) wp_json_encode( [ 'uuid' => $uuid ] + $fields ) );
			$verified      = $this->verify_visit_code( $host_site, $code, $expected_hash );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			Transfer::update_host_identity( (int) $transfer->id, $new_host_slug, $new_host_chronicle );
			return $this->success( [ 'ok' => true ] );
		}

		if ( $type === 'change' || $type === 'note' ) {
			$host_note = $request->get_param( 'host_note' );
			$fields    = $type === 'note'
				? [ 'type' => 'note', 'host_note' => (string) $host_note ]
				: [
					'type'        => 'change',
					'change_type' => (string) $request->get_param( 'change_type' ),
					'change_data' => (array) $request->get_param( 'change_data' ),
					'host_note'   => $host_note,
				];
			$expected_hash = hash( 'sha256', (string) wp_json_encode( [ 'uuid' => $uuid ] + $fields ) );
			$verified      = $this->verify_visit_code( $host_site, $code, $expected_hash );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}

			if ( Change::count_pending_from_host( $host_site, $host_slug ) >= self::MAX_WAITING_FROM_HOST ) {
				return $this->error( 'too_many_waiting', __( 'This chronicle has too many items waiting from that host. Try again once its Storytellers have caught up.', 'beyond-elysium' ), 429 );
			}

			if ( $type === 'change' && ! in_array( (string) $request->get_param( 'change_type' ), \BeyondElysium\Services\Change_Validator::REST_CHANGE_TYPES, true ) ) {
				return $this->error( 'invalid_param', __( 'That kind of change cannot be forwarded.', 'beyond-elysium' ), 400 );
			}
			$host_note = $host_note !== null ? Keep_Current::clean_markup( (string) $host_note ) : null;

			$change_id = Change::create( [
				'character_id'    => (int) $transfer->character_id,
				'change_type'     => $type === 'note' ? 'visit_note' : (string) $request->get_param( 'change_type' ),
				'category'        => $type === 'note' ? 'visit' : 'experience',
				'change_data'     => $type === 'note' ? [ 'note' => (string) $host_note ] : (array) Keep_Current::clean_markup( (array) $request->get_param( 'change_data' ) ),
				'xp_cost'         => 0,
				'status'          => 'pending',
				'submitted_by'    => 0,
				'source_visit_id' => (int) $transfer->id,
				'host_note'       => $host_note !== null ? (string) $host_note : null,
			] );
			if ( ! $change_id ) {
				return $this->error( 'create_failed', __( 'Failed to record this.', 'beyond-elysium' ), 500 );
			}

			return $this->success( [ 'ok' => true ] );
		}

		return $this->error( 'invalid_param', __( 'Unknown call type.', 'beyond-elysium' ), 400 );
	}

	/**
	 * Home's end of a host's pairing request: verifies the host really issued the call, resolves the file's own
	 * home-issued code locally (no network call - it is home's own attestation) to find which local character this
	 * is about, and files a pending `visit_pairing` change for a Storyteller to decide.
	 *
	 * @param object            $game
	 * @param string            $uuid
	 * @param string            $host_site
	 * @param string            $host_slug
	 * @param string            $code
	 * @param \WP_REST_Request  $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function receive_pairing_request( $game, string $uuid, string $host_site, string $host_slug, string $code, $request ) {
		$home_code      = (string) $request->get_param( 'home_code' );
		$host_chronicle = (string) $request->get_param( 'host_chronicle' );

		$fields        = [ 'type' => 'pairing', 'home_code' => $home_code, 'host_chronicle' => $host_chronicle ];
		$expected_hash = hash( 'sha256', (string) wp_json_encode( [ 'uuid' => $uuid ] + $fields ) );
		$verified      = $this->verify_visit_code( $host_site, $code, $expected_hash, 'visit_pairing' );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		// The URL's own uuid is the HOST's local copy, which a submission always gets its own fresh identity -
		// never home's. The attestation's own character_id is what actually names which home character this is.
		$attestation = Attestation::resolve( $home_code );
		if ( $attestation === null || $attestation->game_slug !== $game->slug || $attestation->revoked_at !== null ) {
			return $this->error( 'character_not_found', __( 'The file this request names is not one this chronicle issued.', 'beyond-elysium' ), 404 );
		}

		if ( Change::has_pending_visit_pairing( (int) $attestation->character_id, $host_site, $host_slug ) ) {
			return $this->error( 'already_waiting', __( 'A request from this chronicle to keep this character current is already waiting.', 'beyond-elysium' ), 409 );
		}

		$change_id = Change::create( [
			'character_id' => (int) $attestation->character_id,
			'change_type'  => 'visit_pairing',
			'category'     => 'visit',
			'change_data'  => [
				'host_site'      => $host_site,
				'host_slug'      => $host_slug,
				'host_chronicle' => $host_chronicle,
				// The host's own local copy has its own fresh uuid, never home's - what the return leg,
				// confirming agreement back to the host, must address its own row by.
				'host_uuid'      => $uuid,
			],
			'xp_cost'      => 0,
			'status'       => 'pending',
			'submitted_by' => 0,
		] );
		if ( ! $change_id ) {
			return $this->error( 'create_failed', __( 'Failed to record this.', 'beyond-elysium' ), 500 );
		}

		return $this->success( [ 'ok' => true ] );
	}

	/**
	 * Host side: shares a free-text note about a visiting character with its real home, with no sheet effect.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function note( $request ) {
		$transfer = $this->transfer_in_state( $request, 'host', 'visiting' );
		if ( is_wp_error( $transfer ) ) {
			return $transfer;
		}
		if ( ! $transfer->keep_current || ! $transfer->keep_current_accepted ) {
			return $this->error( 'invalid_state', __( 'This visit is not an agreed keep-current visit.', 'beyond-elysium' ), 409 );
		}

		$note = trim( (string) $request->get_param( 'note' ) );
		if ( $note === '' ) {
			return $this->error( 'invalid_param', __( 'A note is required.', 'beyond-elysium' ), 400 );
		}

		Keep_Current::forward_note( $transfer, $note );
		return $this->success( [ 'ok' => true ] );
	}

	/**
	 * Host's end of the same handshake: home telling this chronicle a visit ended.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function from_home( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		if ( self::is_rate_limited() ) {
			return $this->error( 'rate_limited', __( 'Too many requests. Please try again shortly.', 'beyond-elysium' ), 429 );
		}

		$uuid      = strtolower( (string) ( $request->get_url_params()['uuid'] ?? '' ) );
		$type      = (string) $request->get_param( 'type' );
		$home_site = untrailingslashit( (string) $request->get_param( 'home_site' ) );
		$home_slug = (string) $request->get_param( 'home_slug' );
		$code      = (string) $request->get_param( 'code' );

		if ( $uuid === '' || $home_site === '' || $home_slug === '' || $code === '' ) {
			return $this->error( 'invalid_param', __( 'A visit identity and verification code are required.', 'beyond-elysium' ), 400 );
		}

		$transfer = Transfer::find_open_visit_from_home( $uuid, $home_site, $home_slug, $game->slug );
		if ( $transfer === null ) {
			return $this->error( 'transfer_not_found', __( 'No open visit matches this call.', 'beyond-elysium' ), 404 );
		}

		if ( $type === 'end' ) {
			if ( $transfer->state !== 'visiting' ) {
				return $this->error( 'invalid_state', __( 'This visit is not open to end.', 'beyond-elysium' ), 409 );
			}
			$their_state = (string) $request->get_param( 'state' );
			$mapped      = self::mirror_state( $their_state );
			if ( $mapped === null ) {
				return $this->error( 'invalid_param', __( 'Unknown ending state.', 'beyond-elysium' ), 400 );
			}
			$verified = $this->verify_visit_code( $home_site, $code, self::visit_hash( $uuid, $home_site, $home_slug, $type, $their_state ) );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			Transfer::transition( (int) $transfer->id, $mapped );
			if ( $mapped === 'ended' && $transfer->character_id !== null ) {
				Character::update_header( (int) $transfer->character_id, [ 'status' => 'inactive' ] );
			}
			return $this->success( [ 'ok' => true ] );
		}

		if ( $type === 'pairing_accepted' ) {
			$home_uuid     = (string) $request->get_param( 'home_uuid' );
			$fields        = [ 'type' => 'pairing_accepted', 'home_uuid' => $home_uuid ];
			$expected_hash = hash( 'sha256', (string) wp_json_encode( [ 'uuid' => $uuid ] + $fields ) );
			$verified      = $this->verify_visit_code( $home_site, $code, $expected_hash );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			Transfer::transition( (int) $transfer->id, $transfer->state, [ 'keep_current_accepted' => 1 ] );
			if ( $home_uuid !== '' ) {
				Transfer::set_peer_uuid( (int) $transfer->id, $home_uuid );
			}
			return $this->success( [ 'ok' => true ] );
		}

		if ( $type === 'keep_current' ) {
			$on       = (bool) $request->get_param( 'on' );
			$verified = $this->verify_visit_code( $home_site, $code, self::visit_hash( $uuid, $home_site, $home_slug, $type, $on ? '1' : '0' ) );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			Transfer::transition( (int) $transfer->id, $transfer->state, $on
				? [ 'keep_current' => 1 ]
				: [ 'keep_current' => 0, 'keep_current_accepted' => 0 ] );
			return $this->success( [ 'ok' => true ] );
		}

		if ( $type === 'update' ) {
			if ( $transfer->state !== 'visiting' || ! $transfer->keep_current || ! $transfer->keep_current_accepted ) {
				return $this->error( 'invalid_state', __( 'This visit is not an open, agreed keep-current visit.', 'beyond-elysium' ), 409 );
			}

			$sequence = (int) $request->get_param( 'sequence' );
			if ( $sequence <= (int) $transfer->delivered_sequence ) {
				return $this->error( 'stale_sequence', __( 'This update is older than what was already applied here.', 'beyond-elysium' ), 409 );
			}

			$body          = (array) $request->get_params();
			$expected_hash = Keep_Current::canonical_hash( $body );
			$verified      = $this->verify_visit_code( $home_site, $code, $expected_hash, 'transfer' );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}

			if ( ! Keep_Current::apply_update( $transfer, $body ) ) {
				return $this->error( 'character_not_found', __( 'The visiting character no longer exists here.', 'beyond-elysium' ), 404 );
			}

			Transfer::set_delivered_sequence( (int) $transfer->id, $sequence );
			Transfer::mark_received( (int) $transfer->id );
			return $this->success( [ 'ok' => true ] );
		}

		return $this->error( 'invalid_param', __( 'Unknown call type.', 'beyond-elysium' ), 400 );
	}

	/**
	 * Tells the other side of a visit about a local event: the host's own accept, either side's own ending action,
	 * or a keep-current change. Issues a fresh, short-lived verification code the receiving site calls back to
	 * confirm, and swallows an unreachable or refusing other side - the local action already stands regardless.
	 *
	 * @param object               $transfer    The transfer row, already carrying its own new local state.
	 * @param string               $type        'accept' | 'end' | 'keep_current' | 'keep_current_accept'.
	 * @param object|null          $character   The local character row to mint the call's own verification code from.
	 * @param array<string,mixed>  $body_extra  Extra fields to send alongside `type`/`code`.
	 * @param string               $hash_detail What the call's own verification code is bound to beyond its type - the
	 *                                          ending state, the keep-current value.
	 */
	private function tell_other_side( object $transfer, string $type, ?object $character, array $body_extra = [], string $hash_detail = '' ): void {
		if ( $character === null ) {
			return;
		}

		$is_host    = $transfer->direction === 'inbound';
		$our_site   = home_url();
		$our_slug   = $is_host ? (string) $transfer->host_slug : (string) $transfer->home_slug;
		$their_site = $is_host ? (string) $transfer->home_site : (string) $transfer->host_site;
		$their_slug = $is_host ? (string) $transfer->home_slug : (string) $transfer->host_slug;
		$route      = $is_host ? 'from-host' : 'from-home';

		if ( $their_site === '' || $their_slug === '' ) {
			return;
		}

		$hash        = self::visit_hash( (string) $transfer->character_uuid, $our_site, $our_slug, $type, $hash_detail );
		$attestation = Attestation::issue_visit_item( $character, $hash, [
			'visit_uuid' => $transfer->character_uuid,
			'action'     => $type,
		] );

		$body = array_merge( [ 'type' => $type, 'code' => $attestation->short_code ], $body_extra );
		if ( $is_host ) {
			$body['host_site'] = $our_site;
			$body['host_slug'] = $our_slug;
		} else {
			$body['home_site'] = $our_site;
			$body['home_slug'] = $our_slug;
		}

		wp_safe_remote_post(
			untrailingslashit( $their_site ) . '/wp-json/' . $this->namespace . '/' . $their_slug . '/transfers/' . $transfer->character_uuid . '/' . $route,
			[
				'timeout' => 20,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => (string) wp_json_encode( $body ),
			]
		);
	}

	/**
	 * Calls the claimed site's own public verify endpoint and confirms the code resolves, is not revoked, was
	 * issued for a visit, matches the expected binding hash, and the issuer is the site it claims to be.
	 *
	 * @param string $claimed_site
	 * @param string $code
	 * @param string $expected_hash
	 * @param string $expected_kind 'visit_item' (the default - a cross-site call) or 'transfer' (a keep-current update).
	 * @return array<string,mixed>|\WP_Error
	 */
	private function verify_visit_code( string $claimed_site, string $code, string $expected_hash, string $expected_kind = 'visit_item' ) {
		if ( \BeyondElysium\Services\Remote_Site::is_link_local( $claimed_site ) ) {
			return $this->error( 'unsafe_site', __( 'That site address cannot be reached from here.', 'beyond-elysium' ), 400 );
		}
		$response = wp_safe_remote_get( untrailingslashit( $claimed_site ) . '/wp-json/be/v1/verify/' . rawurlencode( $code ), [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) ) {
			return $this->error( 'verify_unreachable', __( 'Could not reach the other chronicle\'s site to verify this call.', 'beyond-elysium' ), 502 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status === 404 ) {
			return $this->error( 'verify_failed', __( 'The other chronicle\'s site does not recognize this verification code.', 'beyond-elysium' ), 400 );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $this->error( 'verify_failed', __( 'The other chronicle\'s site returned an unreadable verification response.', 'beyond-elysium' ), 502 );
		}
		if ( empty( $data['valid'] ) || ! empty( $data['revoked'] ) ) {
			return $this->error( 'verify_failed', __( 'This verification code is no longer valid.', 'beyond-elysium' ), 400 );
		}
		if ( ( $data['kind'] ?? '' ) !== $expected_kind ) {
			return $this->error( 'verify_failed', __( 'The other chronicle\'s site did not issue this code for this call.', 'beyond-elysium' ), 400 );
		}
		if ( ! hash_equals( untrailingslashit( (string) ( $data['issuer']['site'] ?? '' ) ), untrailingslashit( $claimed_site ) ) ) {
			return $this->error( 'verify_failed', __( 'The verifying site does not match the claimed site.', 'beyond-elysium' ), 400 );
		}

		$actual_hash = (string) ( $data['attested']['sheet_hash'] ?? '' );
		if ( $actual_hash === '' || ! hash_equals( $expected_hash, $actual_hash ) ) {
			return $this->error( 'verify_failed', __( 'This call does not match what the other chronicle\'s site attested to.', 'beyond-elysium' ), 400 );
		}

		return $data;
	}

	/**
	 * The binding hash a visit's own cross-site call is issued and verified against: the caller's claimed identity
	 * plus what it is claiming.
	 *
	 * @param string $uuid
	 * @param string $site
	 * @param string $slug
	 * @param string $type
	 * @param string $state
	 * @return string
	 */
	private static function visit_hash( string $uuid, string $site, string $slug, string $type, string $state = '' ): string {
		return hash( 'sha256', $uuid . '|' . untrailingslashit( $site ) . '|' . $slug . '|' . $type . '|' . $state );
	}

	/**
	 * The other side's own terminal state for one side's ending action: `ended` mirrors itself, `released`
	 * (home gives the character up for good) mirrors `retained` (the host keeps it for good), and back again.
	 *
	 * @param string $state
	 * @return string|null Null when the state names nothing a visit can end in.
	 */
	private static function mirror_state( string $state ): ?string {
		$map = [ 'ended' => 'ended', 'released' => 'retained', 'retained' => 'released' ];
		return $map[ $state ] ?? null;
	}

	/**
	 * The transfer named in the URL, when it belongs to this chronicle's side of the journey and is in the state an
	 * action requires.
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
	 * A transfer row without its stored payload.
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
	 * Calls the claimed home site's public verify endpoint and confirms the code resolves, is not revoked, its attested
	 * sheet hash matches this exact payload, and the issuer is the site it claims to be.
	 *
	 * @param string $home_site
	 * @param string $short_code
	 * @param string $payload
	 * @return array<string,mixed>|\WP_Error The home site's verified response.
	 */
	private function verify_with_home( string $home_site, string $short_code, string $payload ) {
		if ( \BeyondElysium\Services\Remote_Site::is_link_local( $home_site ) ) {
			return $this->error( 'unsafe_site', __( 'That site address cannot be reached from here.', 'beyond-elysium' ), 400 );
		}
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

		// Only a code the home chronicle issued for a transfer vouches for one.
		if ( ( $data['kind'] ?? '' ) !== 'transfer' ) {
			return $this->error( 'verify_failed', __( 'The home chronicle did not issue this code for a transfer.', 'beyond-elysium' ), 400 );
		}

		// sheet_hash was hashed from the canonical (no verification URL/uuid) document.
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
	 * POSTs a transfer payload to another chronicle's inbound route.
	 *
	 * @param string $host_site
	 * @param string $host_slug
	 * @param string $xml
	 * @param object $game
	 * @param string $short_code
	 * @return array{ok:bool,body:array<string,mixed>|null,note?:string} `ok` means the host answered with a 2xx.
	 */
	private function post_to_host( string $host_site, string $host_slug, string $xml, object $game, string $short_code, bool $keep_current = false ): array {
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
				'keep_current'   => $keep_current,
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
	 * Counts one inbound request against the caller's IP for the rolling minute, the same transient pattern as
	 * Verify_Controller's own limit.
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
	 * Looks up the game record for the given slug and returns a 404 error when no game matches it.
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
