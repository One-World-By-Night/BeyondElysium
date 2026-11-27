<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Coordinator_Request;
use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * Reads where a request sent to the Archivist Toolkit stands, as the Storyteller who sent it, and acts on the answer:
 * the Coordinator's approval applies the change, a denial or a cancellation rejects it. Run hourly, and by hand from a
 * request's own row.
 */
class Coordinator_Reader {

	/**
	 * The most requests one run reads.
	 */
	public const BATCH = 20;

	/**
	 * Reads every approval that is due, oldest read first, a second apart, and stops at the first one that could not
	 * be read.
	 *
	 * @param int $pause Seconds between reads.
	 * @return array{read:int,stopped:bool}
	 */
	public static function run( int $pause = 1 ): array {
		$read    = 0;
		$stopped = false;

		foreach ( Coordinator_Request::due( self::BATCH ) as $request ) {
			$game = Game::find( $request->game_id );
			if ( ! $game || Coordinator_Send::mode( $game ) !== Coordinator_Send::LIVE ) {
				continue;
			}
			$sender = get_user_by( 'id', $request->sent_by );
			if ( ! $sender ) {
				Coordinator_Request::mark( $request->id, [
					'checked_at'  => current_time( 'mysql' ),
					'check_error' => __( 'The Storyteller who sent this no longer has an account here.', 'beyond-elysium' ),
				] );
				continue;
			}
			if ( $read > 0 && $pause > 0 ) {
				sleep( $pause );
			}
			$result = self::read( $request, $sender );
			++$read;
			if ( $result['stop'] ) {
				$stopped = true;
				break;
			}
		}

		return [ 'read' => $read, 'stopped' => $stopped ];
	}

	/**
	 * Reads one request as a person, and acts on what it finds. `stop` is true when the Toolkit could not be read, so a
	 * run goes no further.
	 *
	 * @return array{stop:bool,state:string,message:?string}
	 */
	public static function read( object $request, \WP_User $as ): array {
		if ( $request->state !== 'sent' || $request->purpose !== Coordinator_Request::APPROVAL || ! $request->oat_entry_id ) {
			return [ 'stop' => false, 'state' => (string) $request->state, 'message' => null ];
		}

		$now    = current_time( 'mysql' );
		$answer = Oat_Client::call( 'entry/' . $request->oat_entry_id, [], $as );
		if ( ! $answer['ok'] ) {
			Coordinator_Request::mark( $request->id, [ 'checked_at' => $now, 'check_error' => mb_substr( $answer['message'], 0, 255 ) ] );
			return [ 'stop' => true, 'state' => 'sent', 'message' => $answer['message'] ];
		}

		$entry   = (array) ( $answer['data']['entry'] ?? [] );
		$status  = (string) ( $entry['status'] ?? '' );
		$step    = (string) ( $entry['current_step'] ?? '' );
		$outcome = self::outcome( $status, $step );

		$fields = [ 'oat_status' => $status, 'oat_step' => $step, 'checked_at' => $now, 'check_error' => null ];
		if ( $outcome === null ) {
			Coordinator_Request::mark( $request->id, $fields );
			return [ 'stop' => false, 'state' => 'sent', 'message' => null ];
		}

		Coordinator_Request::mark( $request->id, $fields + [ 'state' => $outcome, 'answered_at' => $now ] );
		$fresh = Coordinator_Request::find( $request->id );
		if ( $fresh ) {
			self::act( $fresh, $outcome );
		}
		return [ 'stop' => false, 'state' => $outcome, 'message' => null ];
	}

	/**
	 * What the Toolkit's request amounts to: `approved` once it has passed the Coordinator (it is with the Archivist, or
	 * recorded), `denied`, `cancelled`, or null while it is still being decided.
	 */
	private static function outcome( string $status, string $step ): ?string {
		if ( in_array( $status, [ 'denied', 'auto_denied' ], true ) ) {
			return 'denied';
		}
		if ( $status === 'cancelled' ) {
			return 'cancelled';
		}
		if ( in_array( $status, [ 'approved', 'auto_approved' ], true ) || $step === 'archivist' ) {
			return 'approved';
		}
		return null;
	}

	/**
	 * Applies or rejects the change the request was for, as the Storyteller who sent it, and tells them. A change a
	 * Storyteller already decided is left as it is.
	 */
	private static function act( object $request, string $outcome ): void {
		$game   = Game::find( $request->game_id );
		$change = $request->change_id ? Change::find( $request->change_id ) : null;
		$entry  = (int) $request->oat_entry_id;

		if ( $change && (string) $change->status === 'pending' ) {
			$previous = get_current_user_id();
			wp_set_current_user( $request->sent_by );
			try {
				if ( $outcome === 'approved' ) {
					/* translators: %d: the Archivist Toolkit's request number */
					$note = sprintf( __( 'Approved by the Coordinator in the Archivist Toolkit (R&U #%d)', 'beyond-elysium' ), $entry );
					if ( ! Change_Engine::approve( (int) $change->id, $request->sent_by, $note ) ) {
						Coordinator_Request::mark( $request->id, [
							'check_error' => __( 'The Coordinator approved this, but it could not be applied. Approve it by hand.', 'beyond-elysium' ),
						] );
					}
				} else {
					$note = $outcome === 'cancelled'
						? __( 'Cancelled in the Archivist Toolkit', 'beyond-elysium' )
						/* translators: %d: the Archivist Toolkit's request number */
						: sprintf( __( 'Not approved by the Coordinator in the Archivist Toolkit (R&U #%d)', 'beyond-elysium' ), $entry );
					Change_Engine::reject( (int) $change->id, $request->sent_by, $note );
				}
			} finally {
				wp_set_current_user( $previous );
			}
		}

		$character = \BeyondElysium\Models\Character::find( $request->character_id );
		if ( $game && $character ) {
			$line = $change ? Coordinator_Line::of( $change, '' ) : null;
			Notices::to_person( $game, $request->sent_by, 'coordinator_answered', [
				'character_id'   => (int) $character->id,
				'character_name' => (string) $character->name,
				'text'           => $line ? $line['label'] : (string) ( $request->pick['name'] ?? '' ),
				'outcome'        => $outcome,
				'entry_id'       => $entry,
			] );
		}

		do_action( 'be_coordinator_answered', $request, $outcome );
	}
}
