<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Core\Notifications;
use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Submission;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\GEX_Parser;
use BeyondElysium\Services\GEX_Xml_Parser;
use BeyondElysium\Services\GV_Binary_Reader;
use BeyondElysium\Services\Sheet_Verification;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a player-sent Grapevine file, waiting for a
 * Storyteller's review (F-122, owner: "Players need to be able to submit GEX
 * that ST can approve. Same process as a send sheet/transfer/visit thing.").
 *
 * Reuses `Import_Controller`'s own parse/preview/commit machinery wholesale
 * rather than a second pipeline - the gap this closes was a front door and a
 * waiting room, not a missing importer. Covers both halves: sending
 * (`preview`, `create`, `withdraw`, `/my/submissions`) and a Storyteller's
 * review (`list`, `review`, `verification`, `accept`, `refuse`).
 *
 * @see BE_PROCESS/design/player-grapevine-file-design.md §6, §7
 */
class Submissions_Controller extends Base_Controller {

	protected $rest_base = 'submissions';

	/** An uploaded file larger than this is refused before it is even read. */
	const MAX_UPLOAD_BYTES = 5 * MB_IN_BYTES;

	/** Waiting submissions a single chronicle may hold before new ones are turned away. */
	const MAX_WAITING = 50;

	public function register_routes(): void {
		// Registered before the game-scoped routes below: WordPress matches routes in
		// registration order, and /(?P<game_slug>...)/submissions would otherwise read
		// /my/submissions as the waiting list of a chronicle literally named "my"
		// (Games_Controller's own /my/games relies on the identical ordering).
		register_rest_route( $this->namespace, '/my/submissions', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_my_submissions' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/submissions/preview', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'preview' ],
				// Bootstrap gate: the site-wide capability plus a real chronicle, no
				// membership required - every WordPress role down to subscriber holds
				// be_edit_own_characters, so this is genuinely "anyone signed in"
				// (Characters_Controller::create_item()'s own join-request path).
				'permission_callback' => $this->permission( 'be_edit_own_characters', true ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/submissions', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_items' ],
				'permission_callback' => $this->permission( 'be_import' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_edit_own_characters', true ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/submissions/(?P<id>\d+)/withdraw', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'withdraw' ],
				'permission_callback' => $this->permission( 'be_edit_own_characters', true ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/submissions/(?P<id>\d+)/review', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'review' ],
				'permission_callback' => $this->permission( 'be_import' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/submissions/(?P<id>\d+)/verification', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'verification' ],
				'permission_callback' => $this->permission( 'be_import' ),
			],
		] );

		foreach ( [ 'accept', 'refuse' ] as $action ) {
			register_rest_route( $this->namespace, "/(?P<game_slug>[a-z0-9\\-]+)/submissions/(?P<id>\\d+)/{$action}", [
				[
					'methods'             => 'POST',
					'callback'            => [ $this, $action ],
					'permission_callback' => $this->permission( 'be_import' ),
				],
			] );
		}
	}

	/**
	 * Reads an uploaded Grapevine file and reports what it holds - format,
	 * every character it carries, and whether each is allowed in this
	 * chronicle - without storing anything. The first real look a sender
	 * gets before choosing which character is theirs and committing to send.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$read = $this->read_upload( $request );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		[ 'parsed' => $parsed, 'format' => $format ] = $read;

		if ( empty( $parsed['characters'] ) ) {
			return $this->error( 'no_character', __( 'This file doesn\'t hold a character.', 'beyond-elysium' ), 422 );
		}

		$characters = [];
		foreach ( $parsed['characters'] as $index => $character ) {
			$check = $this->creation_check( $game, $character );
			$characters[] = [
				'index'      => $index,
				'name'       => (string) ( $character['name'] ?? '' ),
				'stack_slug' => (string) ( $character['race'] ?? '' ),
				'stack_name' => $this->stack_display_name( (string) ( $character['race'] ?? '' ) ),
				'allowed'    => $check === null,
				'reason'     => $check,
				'verifiable' => $format === 'XML' && count( $parsed['characters'] ) === 1
					&& Sheet_Verification::code_from( $character ) !== null,
			];
		}

		return $this->success( [ 'format' => $format, 'characters' => $characters ] );
	}

	/**
	 * Records a request to send one character from an uploaded file to this
	 * chronicle. Stores only the chosen character - every other list in the
	 * file is emptied, and its uuid is dropped (1.0.0-review F-003/F-059: a
	 * uuid in a file proves nothing, so a later accept matches by name only,
	 * same as any ordinary import).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$arrival = (string) $request->get_param( 'arrival' );
		if ( ! in_array( $arrival, [ 'joining', 'visiting' ], true ) ) {
			return $this->error( 'invalid_param', __( 'arrival must be "joining" or "visiting".', 'beyond-elysium' ), 400 );
		}

		$read = $this->read_upload( $request );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		[ 'xml' => $xml, 'parsed' => $parsed, 'format' => $format, 'source_file' => $source_file ] = $read;

		if ( empty( $parsed['characters'] ) ) {
			return $this->error( 'no_character', __( 'This file doesn\'t hold a character.', 'beyond-elysium' ), 422 );
		}

		$character_index = $request->get_param( 'character_index' );
		if ( count( $parsed['characters'] ) > 1 ) {
			if ( $character_index === null || ! isset( $parsed['characters'][ (int) $character_index ] ) ) {
				return $this->error( 'choose_character', __( 'This file holds several characters. Pick yours.', 'beyond-elysium' ), 400 );
			}
			$character = $parsed['characters'][ (int) $character_index ];
		} else {
			if ( $character_index !== null && ! isset( $parsed['characters'][ (int) $character_index ] ) ) {
				return $this->error( 'invalid_param', __( 'character_index does not match this file.', 'beyond-elysium' ), 400 );
			}
			$character = $parsed['characters'][0];
		}

		$check = $this->creation_check( $game, $character );
		if ( $check !== null ) {
			return $this->error( 'not_allowed', $check, 400 );
		}

		$user_id     = get_current_user_id();
		$is_member   = Authorization::can( 'be_edit_own_characters' );
		if ( ! $is_member && $this->has_pending_join_character( $game, $user_id ) ) {
			return $this->error(
				'join_already_requested',
				__( 'Your character is already waiting for this chronicle\'s Storytellers to approve it.', 'beyond-elysium' ),
				409
			);
		}

		if ( Submission::count_waiting( (int) $game->id ) >= self::MAX_WAITING ) {
			return $this->error(
				'too_many_waiting',
				sprintf(
					/* translators: %s: chronicle name */
					__( '%s has too many sheets waiting for review. Try again once its Storytellers have caught up.', 'beyond-elysium' ),
					$game->name
				),
				429
			);
		}

		unset( $character['uuid'] );
		$stored_parsed = [
			'version'    => $parsed['version'] ?? '',
			'players'    => [],
			'characters' => [ $character ],
			'queries'    => [],
			'items'      => [],
			'rotes'      => [],
			'locations'  => [],
			'actions'    => [],
			'plots'      => [],
			'rumors'     => [],
		];
		$encoded_parsed = wp_json_encode( $stored_parsed );
		if ( $encoded_parsed === false ) {
			return $this->error(
				'unreadable_text',
				__( 'Part of this file isn\'t readable text. Re-export it from Grapevine and try again.', 'beyond-elysium' ),
				422
			);
		}

		// Kept only when the file is XML, holds one character, and that character carries a
		// real Beyond Elysium verification code (§5.1) - needed to re-hash the file at review
		// time. A multi-character file's own $xml still names every character; storing it here
		// would be no narrower than storing the whole upload, so it is dropped instead.
		$verification_source = ( $format === 'XML' && count( $parsed['characters'] ) === 1 ) ? $xml : null;

		$home_chronicle = $request->get_param( 'home_chronicle' );

		try {
			$id = Submission::create( [
				'game_id'              => (int) $game->id,
				'submitted_by'         => $user_id,
				'arrival'              => $arrival,
				'home_chronicle'       => $home_chronicle ? sanitize_text_field( (string) $home_chronicle ) : null,
				'character_name'       => (string) ( $character['name'] ?? '' ),
				'stack_slug'           => (string) ( $character['race'] ?? '' ),
				'source_file'          => $source_file,
				'format'               => $format,
				'file_hash'            => hash( 'sha256', $xml ),
				'parsed'               => $encoded_parsed,
				'verification_source'  => $verification_source,
			] );
		} catch ( \RuntimeException $e ) {
			return $this->error(
				'already_waiting',
				sprintf(
					/* translators: %s: chronicle name */
					__( 'You already have a sheet waiting for %s. Withdraw it first, or wait for an answer.', 'beyond-elysium' ),
					$game->name
				),
				409
			);
		}

		$row = Submission::find( $id );
		if ( ! $row ) {
			return $this->error( 'submission_not_found', __( 'Submission not found.', 'beyond-elysium' ), 404 );
		}
		Notifications::submission_received( $game, $row, wp_get_current_user() );

		return $this->success( array_merge( (array) $row, [ 'game_name' => $game->name ] ), 201 );
	}

	/**
	 * A sender withdraws their own still-waiting submission. Another
	 * chronicle's row, or another sender's, is treated identically to a
	 * missing one - never revealing that a request exists at all.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function withdraw( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$row = Submission::find( (int) $request['id'] );
		if ( ! $row || (int) $row->game_id !== (int) $game->id || (int) $row->submitted_by !== get_current_user_id() ) {
			return $this->error( 'submission_not_found', __( 'Submission not found.', 'beyond-elysium' ), 404 );
		}
		if ( $row->state !== 'waiting' ) {
			return $this->error(
				'invalid_state',
				sprintf(
					/* translators: %s: the submission's actual current state */
					__( 'This submission is no longer waiting - it is "%s".', 'beyond-elysium' ),
					$row->state
				),
				409
			);
		}

		Submission::transition( (int) $row->id, 'withdrawn', [ 'answered_by' => get_current_user_id() ] );
		return $this->success( Submission::find( (int) $row->id ) );
	}

	/**
	 * This chronicle's own waiting submissions, newest first - the Import
	 * page's own Waiting for Review list, alongside transfers.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		// The model stays a thin data layer with no WP-API dependency (Transfer's own
		// precedent); resolving the sender's display name for the list view - not just
		// review() - happens here instead.
		$rows = array_map( function ( $row ) {
			$sender = get_userdata( (int) $row->submitted_by );
			$row->sender_name = $sender ? $sender->display_name : null;
			return $row;
		}, Submission::waiting_for_game( (int) $game->id ) );

		return $this->success( $rows );
	}

	/**
	 * Shows a waiting submission the way the Import page shows a parsed
	 * file: counts, duplicates needing a decision, and traits needing
	 * review, plus who sent it and whether it still passes the same
	 * creation checks it passed when it arrived (a restriction can change
	 * while a file waits). Never blocks review on a verification failure -
	 * that is what the separate `verification` route is for.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function review( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		$row  = is_wp_error( $game ) ? $game : $this->waiting_submission( $request, $game );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$stored  = json_decode( (string) $row->parsed, true );
		$preview = Import_Controller::build_preview( $stored, $game->slug, (int) $game->id, [], $row->format );
		$preview['duplicates'] = Import_Controller::with_changes( $preview['duplicates'], $stored, $game->slug );

		foreach ( $preview['duplicates'] as $index => $dup ) {
			$existing = Character::find_by_name_in_game( $dup['character'], $game->slug );
			$preview['duplicates'][ $index ]['overwrite_allowed'] = $existing !== null
				&& ( (int) $existing->wp_user_id === (int) $row->submitted_by || (bool) $existing->is_npc );
			$preview['duplicates'][ $index ]['existing_owner'] = $existing ? $this->owner_display_name( $existing ) : null;
		}

		$check = $this->creation_check( $game, $stored['characters'][0] ?? [] );
		if ( $check !== null ) {
			$preview['warnings'][] = sprintf(
				/* translators: %s: the reason this character can no longer be created here */
				__( '%s It was allowed when this was sent. Accepting will be refused until that changes.', 'beyond-elysium' ),
				$check
			);
		}

		$sender = get_userdata( (int) $row->submitted_by );

		return $this->success( [
			'submission'   => array_merge( (array) Submission::find( (int) $row->id ), [
				'game_name'    => $game->name,
				'sender_name'  => $sender ? $sender->display_name : null,
				'sender_email' => $sender ? $sender->user_email : null,
			] ),
			'preview' => array_merge( [ 'job_id' => 'submission-' . (int) $row->id ], $preview ),
		] );
	}

	/**
	 * Checks a waiting submission's own verification code, if it carries
	 * one - a real callback to the issuing site's own /verify/{code}
	 * (Sheet_Verification), never blocking review or acceptance either way.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function verification( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		$row  = is_wp_error( $game ) ? $game : $this->waiting_submission( $request, $game );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( $row->verification_source === null ) {
			return $this->success( [ 'status' => 'none' ] );
		}

		$stored    = json_decode( (string) $row->parsed, true );
		$character = $stored['characters'][0] ?? [];
		return $this->success( Sheet_Verification::check( (string) $row->verification_source, $character ) );
	}

	/**
	 * Accepts a waiting submission: re-checks it still passes the creation
	 * rules, requires every duplicate/trait decision an import would, then
	 * imports the character in one transaction under the sender's own
	 * identity - joining as an ordinary character, or visiting with a real
	 * transfer row so the existing Visiting badge and Send home/Keep for
	 * good apply unchanged.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function accept( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		$pre  = is_wp_error( $game ) ? $game : Submission::find_with_file( (int) $request['id'] );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}
		if ( ! $pre || (int) $pre->game_id !== (int) $game->id ) {
			return $this->error( 'submission_not_found', __( 'Submission not found.', 'beyond-elysium' ), 404 );
		}
		if ( $pre->state !== 'waiting' ) {
			return $this->error( 'submission_already_answered', __( 'This sheet was already answered by someone else. Reload to see where it stands.', 'beyond-elysium' ), 409 );
		}

		$stored    = json_decode( (string) $pre->parsed, true );
		$character = $stored['characters'][0] ?? [];

		// Resolved before the transaction opens, same ordering Transfers_Controller::accept()
		// uses - a network call must never happen while a row lock is held.
		$verified_issuer = null;
		if ( $pre->verification_source !== null ) {
			$verification = Sheet_Verification::check( (string) $pre->verification_source, $character );
			if ( in_array( $verification['status'], [ 'unchanged', 'changed', 'revoked' ], true ) ) {
				$verified_issuer = $verification['issuer'];
			}
		}

		$resolutions   = (array) ( $request->get_param( 'resolutions' ) ?? [] );
		$body_arrival  = $request->get_param( 'arrival' );

		$savepoint = Transaction::begin( 'be_submission_accept' );

		$row = Submission::find_for_update( (int) $pre->id );
		if ( $row === null || $row->state !== 'waiting' ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'submission_already_answered', __( 'This sheet was already answered by someone else. Reload to see where it stands.', 'beyond-elysium' ), 409 );
		}

		$check = $this->creation_check( $game, $character );
		if ( $check !== null ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'not_allowed', $check, 409 );
		}

		$preview = Import_Controller::build_preview( $stored, $game->slug, (int) $game->id, $resolutions, $row->format );
		$block   = Import_Controller::blocking_reason( $preview, $resolutions );
		if ( $block !== null ) {
			Transaction::rollback( $savepoint );
			return $this->error( $block['code'], $block['message'], $block['status'] );
		}

		foreach ( $preview['duplicates'] as $dup ) {
			$action = $resolutions['duplicates'][ $dup['character'] ] ?? '';
			if ( $action === 'skip' ) {
				Transaction::rollback( $savepoint );
				return $this->error( 'nothing_to_accept', __( 'Skipping the character leaves nothing to accept - refuse the sheet instead.', 'beyond-elysium' ), 400 );
			}
			if ( $action === 'overwrite' ) {
				$existing = Character::find_by_name_in_game( $dup['character'], $game->slug );
				$allowed  = $existing !== null
					&& ( (int) $existing->wp_user_id === (int) $row->submitted_by || (bool) $existing->is_npc );
				if ( ! $allowed ) {
					Transaction::rollback( $savepoint );
					return $this->error(
						'overwrite_not_allowed',
						sprintf(
							/* translators: 1: character name, 2: the account that owns the existing character */
							__( '%1$s here belongs to %2$s. Import it as a new character, or refuse the sheet.', 'beyond-elysium' ),
							$dup['character'],
							$existing ? $this->owner_display_name( $existing ) : __( 'someone else', 'beyond-elysium' )
						),
						409
					);
				}
			}
		}

		try {
			$result    = Import_Controller::apply_import( (int) $game->id, $game->slug, $stored, $row->source_file, $resolutions, [ 'submitted_by' => (int) $row->submitted_by ] );
			$character_row = $result['characters'][0] ?? null;
			if ( $character_row === null ) {
				throw new \RuntimeException( 'The submitted file held no character to import.' );
			}

			Game_Member::ensure_player( (int) $game->id, (int) $row->submitted_by );

			$arrival = in_array( $body_arrival, [ 'joining', 'visiting' ], true ) ? $body_arrival : $row->arrival;
			if ( $arrival === 'visiting' ) {
				$character_uuid = Character::find( (int) $character_row['id'] )->uuid ?? '';
				if ( $character_uuid !== '' && Transfer::find_open( $character_uuid, 'inbound' ) === null ) {
					Transfer::create( [
						'character_uuid' => $character_uuid,
						'character_id'   => (int) $character_row['id'],
						'character_name' => (string) $character_row['name'],
						'direction'      => 'inbound',
						'state'          => 'visiting',
						'home_slug'      => (string) ( $verified_issuer['slug'] ?? '' ),
						'home_site'      => (string) ( $verified_issuer['site'] ?? '' ),
						'home_chronicle' => (string) ( $verified_issuer['chronicle'] ?? $row->home_chronicle ?? '' ),
						'host_slug'      => $game->slug,
						'host_site'      => home_url(),
						'host_chronicle' => $game->name,
						'payload_hash'   => hash( 'sha256', (string) $row->parsed ),
						'initiated_by'   => (int) $row->submitted_by,
						'notes'          => __( 'Arrived with a Grapevine file its player sent in.', 'beyond-elysium' ),
					] );
				}
			}

			Submission::transition( (int) $row->id, 'accepted', [
				'character_id' => (int) $character_row['id'],
				'answered_by'  => get_current_user_id(),
				'arrival'      => $arrival,
			] );
		} catch ( \Throwable $e ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'commit_failed', $e->getMessage(), 500 );
		}
		Transaction::commit( $savepoint );

		Game_Stats_Controller::invalidate( $game->slug );
		$final = Submission::find( (int) $row->id );
		if ( $final ) {
			Notifications::submission_answered( $game, $final );
		}

		return $this->success( [ 'submission' => $final, 'character' => $character_row ] );
	}

	/**
	 * Refuses a waiting submission with an optional note back to the
	 * sender. Nothing was ever written for it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function refuse( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		$row  = is_wp_error( $game ) ? $game : $this->waiting_submission( $request, $game );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$note = $request->get_param( 'note' );
		Submission::transition( (int) $row->id, 'refused', [
			'answered_by' => get_current_user_id(),
			'answer_note' => $note ? mb_substr( sanitize_textarea_field( (string) $note ), 0, 1000 ) : null,
		] );

		$final = Submission::find( (int) $row->id );
		if ( $final ) {
			Notifications::submission_answered( $game, $final );
		}

		return $this->success( $final );
	}

	/**
	 * The waiting submission named in the URL, when it belongs to this
	 * chronicle. Shared by review(), verification(), and refuse() - accept()
	 * re-reads and row-locks its own copy instead, since it needs the
	 * pre-transaction verification call to happen before any lock is taken.
	 *
	 * @param \WP_REST_Request $request
	 * @param object           $game
	 * @return object|\WP_Error
	 */
	private function waiting_submission( $request, object $game ) {
		$row = Submission::find_with_file( (int) $request['id'] );
		if ( ! $row || (int) $row->game_id !== (int) $game->id ) {
			return $this->error( 'submission_not_found', __( 'Submission not found.', 'beyond-elysium' ), 404 );
		}
		if ( $row->state !== 'waiting' ) {
			return $this->error(
				'invalid_state',
				sprintf(
					/* translators: %s: the submission's actual current state */
					__( 'This submission is no longer waiting - it is "%s".', 'beyond-elysium' ),
					$row->state
				),
				409
			);
		}
		return $row;
	}

	/**
	 * @param object $character A row from Character::find()/find_by_name_in_game().
	 * @return string
	 */
	private function owner_display_name( object $character ): string {
		if ( ! empty( $character->is_npc ) ) {
			return __( 'an NPC', 'beyond-elysium' );
		}
		if ( ! empty( $character->wp_user_id ) ) {
			$user = get_userdata( (int) $character->wp_user_id );
			if ( $user ) {
				return $user->display_name;
			}
		}
		return __( 'no player', 'beyond-elysium' );
	}

	/**
	 * The caller's own last submissions across every chronicle they've sent
	 * one to, newest first.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_my_submissions( $request ) {
		return $this->success( Submission::for_user( get_current_user_id() ) );
	}

	/**
	 * Reads and parses an uploaded Grapevine file, common to preview() and
	 * create_item(). Rejects an oversized, unreadable, wrong-format, or
	 * unparseable file before either caller does anything format-specific.
	 *
	 * @param \WP_REST_Request $request
	 * @return array{xml:string,parsed:array<string,mixed>,format:string,source_file:string}|\WP_Error
	 */
	private function read_upload( $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file']['tmp_name'] ) ) {
			return $this->error( 'invalid_param', __( 'A file upload is required.', 'beyond-elysium' ), 400 );
		}
		if ( (int) ( $files['file']['size'] ?? 0 ) > self::MAX_UPLOAD_BYTES ) {
			return $this->error(
				'file_too_large',
				__( 'This file is larger than 5 MB. Export just your character from Grapevine and try again.', 'beyond-elysium' ),
				413
			);
		}

		$source_file = (string) ( $files['file']['name'] ?? 'upload.gex' );
		$data        = @file_get_contents( $files['file']['tmp_name'] );
		if ( $data === false || $data === '' ) {
			return $this->error( 'invalid_param', __( 'The uploaded file could not be read.', 'beyond-elysium' ), 400 );
		}

		$format = Import_Controller::sniff_format( $data );
		if ( $format === 'GVBG' ) {
			return $this->error(
				'unsupported_format',
				__( 'This is a whole game file. Export just your character as an exchange file (.gex) and send that.', 'beyond-elysium' ),
				400
			);
		}
		if ( ! in_array( $format, [ 'GVBE', 'XML' ], true ) ) {
			return $this->error( 'invalid_format', __( 'This isn\'t a Grapevine exchange file.', 'beyond-elysium' ), 400 );
		}

		try {
			$parsed = $format === 'XML'
				? GEX_Xml_Parser::parse_string( $data )
				: GEX_Parser::parse_binary( new GV_Binary_Reader( $data, $source_file ) );
		} catch ( \RuntimeException $e ) {
			return $this->error( 'parse_failed', $e->getMessage(), 400 );
		}

		return [ 'xml' => $data, 'parsed' => $parsed, 'format' => $format, 'source_file' => $source_file ];
	}

	/**
	 * Whether a parsed character may be created in this chronicle - the
	 * enabled_stacks (Decision 092) and sub-faction (Decision 097) checks
	 * `Characters_Controller::create_item()` already enforces for a
	 * hand-built character, applied here to a file's own character before any
	 * row exists to check. Null means allowed; a string is the reason it
	 * isn't, in the exact wording a sender or Storyteller should read.
	 *
	 * @param object               $game
	 * @param array<string,mixed>  $character
	 * @return string|null
	 */
	private function creation_check( object $game, array $character ): ?string {
		$stack_slug = (string) ( $character['race'] ?? '' );

		$allowed_stacks = array_column( Creature_Stack::all_for_game( $game->slug ), 'slug' );
		if ( ! in_array( $stack_slug, $allowed_stacks, true ) ) {
			$stack_name = $this->stack_display_name( $stack_slug );
			return $stack_name === null
				? sprintf(
					/* translators: %s: creature type raw slug */
					__( 'This site doesn\'t have %s set up.', 'beyond-elysium' ),
					$stack_slug
				)
				: sprintf(
					/* translators: 1: creature type name, 2: chronicle name */
					__( '%1$s characters aren\'t part of %2$s.', 'beyond-elysium' ),
					$stack_name,
					$game->name
				);
		}

		$resolved = Creature_Stack::resolve( $stack_slug, $game->slug );
		if ( ! $resolved ) {
			return sprintf(
				/* translators: %s: creature type raw slug */
				__( 'This site doesn\'t have %s set up.', 'beyond-elysium' ),
				$stack_slug
			);
		}

		$sheet_data = Import_Controller::identity_sheet_data( $stack_slug, $character );
		$disallowed = Creature_Stack::find_disallowed_identity_value( $stack_slug, $sheet_data, $resolved['blocks'], $game->slug );
		if ( $disallowed ) {
			return sprintf(
				/* translators: 1: identity field name, 2: the disallowed value */
				__( '%1$s "%2$s" isn\'t allowed in %3$s.', 'beyond-elysium' ),
				$disallowed['field'],
				$disallowed['value'],
				$game->name
			);
		}

		return null;
	}

	/**
	 * @param string $stack_slug
	 * @return string|null The stack's display name, or null when this install has no such stack at all.
	 */
	private function stack_display_name( string $stack_slug ): ?string {
		$stack = Creature_Stack::find_by_slug( $stack_slug );
		return $stack ? $stack->name : null;
	}

	/**
	 * Whether this user already has a pending-approval, hand-built join
	 * character waiting in this chronicle - the other half of the "one
	 * waiting request per person per chronicle" rule, matching the exact
	 * query `Characters_Controller::create_item()`'s own join branch uses.
	 *
	 * @param object $game
	 * @param int    $user_id
	 * @return bool
	 */
	private function has_pending_join_character( object $game, int $user_id ): bool {
		$waiting = Manager::get_var(
			'SELECT COUNT(*) FROM ' . Manager::table( 'characters' ) . " WHERE owner_type = 'chronicle' AND owner_slug = %s AND wp_user_id = %d AND status = 'pending'",
			$game->slug,
			$user_id
		);
		return (int) $waiting > 0;
	}

	/**
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
