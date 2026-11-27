<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Coordinator_Request;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * A Storyteller sending waiting changes, or a pending character's picks, to the Archivist Toolkit as themselves. Each one
 * becomes one R&U Request under its own key, so a send whose answer was lost can be sent again without filing a second.
 * A demo chronicle only shows what would be filed.
 */
class Coordinator_Sender {

	/**
	 * @param object                                                                                           $game
	 * @param \WP_User                                                                                         $sender    The Storyteller sending, whose own account the Toolkit acts for.
	 * @param object                                                                                           $character
	 * @param int[]                                                                                            $change_ids
	 * @param array{rules?:array<int,array<string,mixed>>,coordinators?:string[],note?:string,purpose?:string} $options
	 * @return array{mode:string,results?:array<int,array<string,mixed>>,previews?:array<int,array<string,mixed>>}|\WP_Error
	 */
	public static function send( object $game, \WP_User $sender, object $character, array $change_ids, array $options ) {
		$ready = self::ready( $game, $character, $options );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		[ $mode, $purpose ] = $ready;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $change_ids ) ) ) );
		if ( $ids === [] ) {
			return self::error( 'invalid_param', __( 'Name at least one change to send.', 'beyond-elysium' ), 400 );
		}
		$items = [];
		foreach ( $ids as $id ) {
			$change = Change::find( $id );
			if ( ! $change || (int) $change->character_id !== (int) $character->id ) {
				return self::error( 'not_found', __( 'That change was not found.', 'beyond-elysium' ), 404 );
			}
			$refused = self::check_change( $change, $purpose );
			if ( $refused !== null ) {
				return $refused;
			}
			$block   = isset( $change->change_data['block_slug'] ) ? Schema_Block::find_for_game( (string) $change->change_data['block_slug'], (string) ( $character->owner_slug ?? $game->slug ) ) : null;
			$items[] = [
				'id'       => (int) $change->id,
				'key'      => null,
				'change'   => $change,
				'pick'     => null,
				'line'     => Coordinator_Line::of( $change, $block ? (string) $block->name : '' ),
				'uuid'     => null,
				'existing' => Coordinator_Request::for_change( (int) $change->id ),
				'source'   => 'change/' . (int) $change->id,
			];
		}
		return self::deliver( $game, $sender, $character, $items, $options, $mode, $purpose );
	}

	/**
	 * Sends a pending character's picks, named by their keys. What is asked is worked out again here from the character's
	 * own sheet, never taken from the caller.
	 *
	 * @param string[]            $pick_keys
	 * @param array<string,mixed> $options
	 * @return array{mode:string,results?:array<int,array<string,mixed>>,previews?:array<int,array<string,mixed>>}|\WP_Error
	 */
	public static function send_picks( object $game, \WP_User $sender, object $character, array $pick_keys, array $options ) {
		$ready = self::ready( $game, $character, $options );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		[ $mode, $purpose ] = $ready;

		$wanted = array_values( array_unique( array_map( 'strval', $pick_keys ) ) );
		$known  = [];
		foreach ( Pending_Picks::for_character( $game, $character ) as $pick ) {
			$known[ $pick['key'] ] = $pick;
		}
		$items = [];
		foreach ( $wanted as $key ) {
			$pick = $known[ $key ] ?? null;
			if ( $pick === null ) {
				return self::error( 'not_found', __( 'That pick was not found on this character.', 'beyond-elysium' ), 404 );
			}
			$uuid     = Pending_Picks::uuid( (int) $game->id, (int) $character->id, $key );
			$existing = Coordinator_Request::find_by_uuid( $uuid );
			$refused  = self::check_request( $existing );
			if ( $refused !== null ) {
				return $refused;
			}
			$items[] = [
				'id'       => null,
				'key'      => $key,
				'change'   => null,
				'pick'     => [ 'key' => $key, 'section' => $pick['section'], 'section_label' => $pick['section_label'], 'name' => $pick['name'] ],
				'line'     => [ 'label' => $pick['name'], 'section' => $pick['section_label'], 'detail' => 'new character', 'xp' => 0, 'reason' => $pick['reason'], 'player_note' => null ],
				'uuid'     => $uuid,
				'existing' => $existing,
				'source'   => 'pick/' . (int) $character->id . '/' . $key,
			];
		}
		if ( $items === [] ) {
			return self::error( 'invalid_param', __( 'Name at least one pick to send.', 'beyond-elysium' ), 400 );
		}
		return self::deliver( $game, $sender, $character, $items, $options, $mode, $purpose );
	}

	/**
	 * Whether this chronicle sends at all, and the purpose asked for.
	 *
	 * @param array<string,mixed> $options
	 * @return array{0:string,1:string}|\WP_Error The mode and the purpose.
	 */
	private static function ready( object $game, object $character, array $options ) {
		$mode = Coordinator_Send::mode( $game );
		if ( $mode === null ) {
			return self::error( 'coordinator_send_off', __( 'Sending to a Coordinator is not switched on for this chronicle.', 'beyond-elysium' ), 409 );
		}
		if ( $mode === Coordinator_Send::LIVE && empty( $character->oat_character_uuid ) ) {
			return self::error( 'oat_character_not_linked', __( 'Link this character to its record in the Archivist Toolkit first.', 'beyond-elysium' ), 409 );
		}
		$purpose = ( $options['purpose'] ?? Coordinator_Request::APPROVAL ) === Coordinator_Request::NOTIFY ? Coordinator_Request::NOTIFY : Coordinator_Request::APPROVAL;
		return [ $mode, $purpose ];
	}

	/**
	 * Whether a change may be sent now, and the refusal when it may not.
	 */
	private static function check_change( object $change, string $purpose ): ?\WP_Error {
		$refused = self::check_request( Coordinator_Request::for_change( (int) $change->id ) );
		if ( $refused !== null ) {
			return $refused;
		}
		$allowed = $purpose === Coordinator_Request::NOTIFY ? [ 'pending', 'approved' ] : [ 'pending' ];
		if ( ! in_array( (string) $change->status, $allowed, true ) ) {
			return self::error( 'not_waiting', __( 'This change is no longer waiting for approval.', 'beyond-elysium' ), 409 );
		}
		return null;
	}

	/**
	 * The refusal for a request that already exists and cannot simply be sent again.
	 */
	private static function check_request( ?object $existing ): ?\WP_Error {
		if ( $existing === null || Coordinator_Request::is_resendable( $existing ) ) {
			return null;
		}
		if ( $existing->state === 'filed' ) {
			return self::error( 'already_filed', __( 'This was already sent to the Archivist Toolkit.', 'beyond-elysium' ), 409 );
		}
		if ( in_array( $existing->state, [ 'approved', 'denied', 'cancelled' ], true ) ) {
			return self::error( 'coordinator_answered', __( 'The Coordinator has already answered this.', 'beyond-elysium' ), 409 );
		}
		return self::error( 'with_coordinator', __( 'This is already with a Coordinator.', 'beyond-elysium' ), 409 );
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @param array<string,mixed>            $options
	 * @return array{mode:string,results?:array<int,array<string,mixed>>,previews?:array<int,array<string,mixed>>}
	 */
	private static function deliver( object $game, \WP_User $sender, object $character, array $items, array $options, string $mode, string $purpose ): array {
		if ( $mode === Coordinator_Send::PREVIEW ) {
			$previews = [];
			foreach ( $items as $item ) {
				$previews[] = [
					'change_id' => $item['id'],
					'key'       => $item['key'],
					'body'      => self::body( $game, $sender, $character, $item, $options, $purpose, 'preview', [] ),
				];
			}
			return [ 'mode' => Coordinator_Send::PREVIEW, 'previews' => $previews ];
		}

		$results = [];
		$filed   = [];
		$stopped = false;
		foreach ( $items as $item ) {
			$result = [ 'change_id' => $item['id'], 'key' => $item['key'] ];
			if ( $stopped ) {
				$results[] = $result + [ 'state' => 'not_sent', 'request' => null ];
				continue;
			}
			$lock_id = $item['id'] ?? 1000000000 + (int) hexdec( substr( (string) $item['key'], 0, 7 ) );
			if ( ! Coordinator_Request::lock( (int) $lock_id ) ) {
				$results[] = $result + [ 'state' => 'refused', 'request' => null, 'code' => 'with_coordinator', 'message' => __( 'This is already being sent.', 'beyond-elysium' ) ];
				$stopped   = true;
				continue;
			}
			try {
				$outcome = self::file_item( $game, $sender, $character, $item, $options, $purpose, $filed );
			} finally {
				Coordinator_Request::unlock( (int) $lock_id );
			}
			$results[] = $result + $outcome;
			if ( in_array( $outcome['state'], [ 'sent', 'filed' ], true ) ) {
				$filed[] = (int) $outcome['request']['oat_entry_id'];
			} else {
				$stopped = true;
			}
		}
		return [ 'mode' => Coordinator_Send::LIVE, 'results' => $results ];
	}

	/**
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $options
	 * @param int[]               $filed   The Toolkit ids of the requests already filed in this send.
	 * @return array<string,mixed> `state`, `request`, and for a failure `code` and `message`.
	 */
	private static function file_item( object $game, \WP_User $sender, object $character, array $item, array $options, string $purpose, array $filed ): array {
		// A notification is made after the ordinary approval, which a failed send does not undo.
		if ( $item['change'] !== null && $purpose === Coordinator_Request::NOTIFY && (string) $item['change']->status === 'pending' ) {
			if ( ! Change_Engine::approve( (int) $item['id'], (int) $sender->ID, null ) ) {
				return [ 'state' => 'refused', 'request' => null, 'code' => 'approve_failed', 'message' => __( 'The change could not be approved, so nothing was sent.', 'beyond-elysium' ) ];
			}
			$item['change'] = Change::find( (int) $item['id'] ) ?? $item['change'];
			$item['line']   = Coordinator_Line::of( $item['change'], (string) $item['line']['section'] );
		}

		$existing = $item['existing'];
		$uuid     = $existing !== null ? $existing->uuid : ( $item['uuid'] ?? wp_generate_uuid4() );
		$body     = self::body( $game, $sender, $character, $item, $options, $purpose, $uuid, $filed );

		if ( $existing !== null ) {
			$row_id = $existing->id;
			Coordinator_Request::mark( $row_id, [ 'state' => 'sending', 'purpose' => $purpose, 'payload' => $body, 'check_error' => null ] );
		} else {
			$row_id = Coordinator_Request::create( [
				'uuid'         => $uuid,
				'game_id'      => (int) $game->id,
				'character_id' => (int) $character->id,
				'change_id'    => $item['id'],
				'pick'         => $item['pick'],
				'purpose'      => $purpose,
				'sent_by'      => (int) $sender->ID,
				'payload'      => $body,
			] );
		}

		$answer = Oat_Client::call( 'submit', $body, $sender );

		if ( ! $answer['ok'] ) {
			if ( in_array( $answer['code'], [ 'oat_unreachable', 'oat_not_json' ], true ) ) {
				Coordinator_Request::mark( $row_id, [ 'state' => 'unconfirmed', 'check_error' => $answer['message'] ] );
				$view = Coordinator_Request::find( $row_id );
				return [ 'state' => 'unconfirmed', 'request' => $view ? Coordinator_Request::view( $view, true ) : null, 'code' => $answer['code'], 'message' => $answer['message'] ];
			}
			Coordinator_Request::discard( $row_id );
			return [ 'state' => 'refused', 'request' => null, 'code' => $answer['code'], 'message' => $answer['message'] ];
		}

		$now   = current_time( 'mysql' );
		$entry = $answer['data'];
		$state = $purpose === Coordinator_Request::NOTIFY ? 'filed' : 'sent';
		Coordinator_Request::mark( $row_id, [
			'state'        => $state,
			'oat_entry_id' => (int) ( $entry['entry_id'] ?? 0 ),
			'oat_status'   => (string) ( $entry['status'] ?? '' ),
			'oat_step'     => (string) ( $entry['current_step'] ?? '' ),
			'sent_at'      => $now,
			'checked_at'   => $now,
			'check_error'  => null,
		] );
		$view = Coordinator_Request::find( $row_id );
		if ( $purpose === Coordinator_Request::APPROVAL && $view ) {
			self::tell_player( $game, $sender, $character, $item, $options, (int) $view->oat_entry_id );
		}
		return [ 'state' => $state, 'request' => $view ? Coordinator_Request::view( $view, true ) : null ];
	}

	/**
	 * The body the Toolkit's submit takes for one item.
	 *
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $options
	 * @param int[]               $filed
	 * @return array<string,mixed>
	 */
	private static function body( object $game, \WP_User $sender, object $character, array $item, array $options, string $purpose, string $uuid, array $filed ): array {
		return Oat_Request_Builder::build( [
			'chronicle'        => Oat_Connection::chronicle_slug( $game ) ?? (string) $game->slug,
			'uuid'             => $uuid,
			'character_uuid'   => (string) ( $character->oat_character_uuid ?? '' ),
			'character_name'   => (string) $character->name,
			'is_npc'           => ! empty( $character->is_npc ),
			'character_status' => (string) ( $character->status ?? '' ),
			'sender_name'      => (string) $sender->display_name,
			'sender_email'     => (string) $sender->user_email,
			'line'             => $item['line'],
			'rules'            => array_values( (array) ( $options['rules'] ?? [] ) ),
			'coordinators'     => array_values( (array) ( $options['coordinators'] ?? [] ) ),
			'note'             => (string) ( $options['note'] ?? '' ),
			'purpose'          => $purpose,
			'source'           => (string) wp_parse_url( home_url(), PHP_URL_HOST ) . '/' . (string) $game->slug . '/' . $item['source'],
			'prior'            => $filed,
		] );
	}

	/**
	 * Tells the player that something of theirs went to a Coordinator.
	 *
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $options
	 */
	private static function tell_player( object $game, \WP_User $sender, object $character, array $item, array $options, int $entry_id ): void {
		$signal  = Coordinator_Signal::of( $item['line']['reason'] );
		$offices = $signal['coordinators'];
		if ( $offices === [] ) {
			$offices = array_map( static fn( $slug ): string => ucfirst( (string) $slug ), array_values( (array) ( $options['coordinators'] ?? [] ) ) );
		}
		Notices::to_person( $game, (int) ( $character->wp_user_id ?? 0 ), 'coordinator_sent', [
			'character_id'   => (int) $character->id,
			'character_name' => (string) $character->name,
			'text'           => (string) $item['line']['label'],
			'offices'        => array_values( array_unique( $offices ) ),
			'entry_id'       => $entry_id,
		], (int) $sender->ID );
	}

	private static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
