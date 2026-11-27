<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * One send to the Archivist Toolkit: which change or new-character pick it carries, who sent it, the Toolkit request
 * it became, and where that request last stood. A row is made before the call so a lost answer can be sent again under
 * the same key.
 */
class Coordinator_Request {

	/**
	 * `sending`: the call is out. `unconfirmed`: no answer came, so the key may or may not have filed a request. `sent`:
	 * the Toolkit has it and is read hourly. `approved`, `denied` and `cancelled` are the Toolkit's answer. `filed`: a
	 * notification the Toolkit holds and nothing waits on.
	 */
	public const STATES = [ 'sending', 'unconfirmed', 'sent', 'approved', 'denied', 'cancelled', 'filed' ];

	public const APPROVAL = 'approval';
	public const NOTIFY   = 'notify';

	/**
	 * Minutes an approval waits after being read before it is read again.
	 */
	public const READ_EVERY = 55;

	private const COLUMNS = [
		'uuid', 'game_id', 'character_id', 'change_id', 'pick', 'purpose', 'state', 'oat_entry_id', 'oat_status',
		'oat_step', 'sent_by', 'sent_at', 'checked_at', 'check_error', 'answered_at', 'payload',
	];

	/**
	 * @param array<string,mixed> $data `uuid`, `game_id`, `character_id` and `sent_by` are required; `pick` and
	 *                                  `payload` are arrays.
	 * @return int The new row's id, or 0 when the key is already kept or the write failed.
	 */
	public static function create( array $data ): int {
		$row = [
			'uuid'         => (string) $data['uuid'],
			'game_id'      => (int) $data['game_id'],
			'character_id' => (int) $data['character_id'],
			'change_id'    => isset( $data['change_id'] ) ? (int) $data['change_id'] : null,
			'pick'         => isset( $data['pick'] ) ? wp_json_encode( $data['pick'] ) : null,
			'purpose'      => ( $data['purpose'] ?? self::APPROVAL ) === self::NOTIFY ? self::NOTIFY : self::APPROVAL,
			'state'        => 'sending',
			'sent_by'      => (int) $data['sent_by'],
			'payload'      => isset( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : null,
			'created_at'   => current_time( 'mysql' ),
		];
		if ( self::find_by_uuid( $row['uuid'] ) !== null ) {
			return 0;
		}
		return (int) Manager::insert( 'coordinator_requests', $row );
	}

	public static function find( int $id ): ?object {
		$row = Manager::get_row( 'SELECT * FROM ' . Manager::table( 'coordinator_requests' ) . ' WHERE id = %d', $id );
		return $row ? self::decode( $row ) : null;
	}

	public static function find_by_uuid( string $uuid ): ?object {
		$row = Manager::get_row( 'SELECT * FROM ' . Manager::table( 'coordinator_requests' ) . ' WHERE uuid = %s', $uuid );
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * A change's newest request, or null.
	 */
	public static function for_change( int $change_id ): ?object {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'coordinator_requests' ) . ' WHERE change_id = %d ORDER BY id DESC LIMIT 1',
			$change_id
		);
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * The newest request of each of several changes, by change id; a change with none is left out.
	 *
	 * @param int[] $change_ids
	 * @return array<int,object>
	 */
	public static function for_changes( array $change_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $change_ids ) ) ) );
		if ( $ids === [] ) {
			return [];
		}
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows  = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'coordinator_requests' ) . " WHERE change_id IN ( {$marks} ) ORDER BY id ASC",
			...$ids
		);
		$by_change = [];
		foreach ( $rows as $row ) {
			$by_change[ (int) $row->change_id ] = self::decode( $row );
		}
		ksort( $by_change );
		return $by_change;
	}

	/**
	 * Every request about a character, newest first.
	 *
	 * @return array<int,object>
	 */
	public static function for_character( int $character_id ): array {
		return array_map(
			[ self::class, 'decode' ],
			Manager::get_results(
				'SELECT * FROM ' . Manager::table( 'coordinator_requests' ) . ' WHERE character_id = %d ORDER BY id DESC',
				$character_id
			)
		);
	}

	/**
	 * Sent approvals due to be read again: never read, or last read over READ_EVERY minutes ago. Never read first, then
	 * the longest unread.
	 *
	 * @return array<int,object>
	 */
	public static function due( int $limit ): array {
		$cutoff = wp_date( 'Y-m-d H:i:s', time() - self::READ_EVERY * MINUTE_IN_SECONDS );
		return array_map(
			[ self::class, 'decode' ],
			Manager::get_results(
				'SELECT * FROM ' . Manager::table( 'coordinator_requests' ) . " WHERE state = 'sent' AND purpose = 'approval' AND ( checked_at IS NULL OR checked_at < %s ) ORDER BY ( checked_at IS NULL ) DESC, checked_at ASC, id ASC LIMIT %d",
				(string) $cutoff,
				max( 1, $limit )
			)
		);
	}

	/**
	 * How many approvals a chronicle has sent that the Toolkit has not answered.
	 */
	public static function with_coordinator_count( int $game_id ): int {
		return (int) Manager::get_var(
			'SELECT COUNT(*) FROM ' . Manager::table( 'coordinator_requests' ) . " WHERE game_id = %d AND state = 'sent' AND purpose = 'approval'",
			$game_id
		);
	}

	/**
	 * Changes columns of a request. A state that is not one of STATES, or a column that does not exist, refuses the
	 * whole write.
	 *
	 * @param array<string,mixed> $fields
	 */
	public static function mark( int $id, array $fields ): bool {
		if ( isset( $fields['state'] ) && ! in_array( $fields['state'], self::STATES, true ) ) {
			return false;
		}
		if ( array_diff( array_keys( $fields ), self::COLUMNS ) !== [] ) {
			return false;
		}
		foreach ( [ 'pick', 'payload' ] as $json ) {
			if ( array_key_exists( $json, $fields ) && $fields[ $json ] !== null ) {
				$fields[ $json ] = wp_json_encode( $fields[ $json ] );
			}
		}
		return $fields === [] || Manager::update( 'coordinator_requests', $fields, [ 'id' => $id ] ) !== false;
	}

	/**
	 * Takes the lock that keeps two sends of one change from running at once; false when another holds it. The lock is
	 * the connection's and ends with it.
	 */
	public static function lock( int $change_id ): bool {
		return (int) Manager::get_var( 'SELECT GET_LOCK( %s, 0 )', 'be_coordinator_send_' . $change_id ) === 1;
	}

	public static function unlock( int $change_id ): void {
		Manager::get_var( 'SELECT RELEASE_LOCK( %s )', 'be_coordinator_send_' . $change_id );
	}

	/**
	 * Whether a request can be sent again under its own key: no answer came, or the call has been out too long to
	 * still be running.
	 */
	public static function is_resendable( object $row ): bool {
		if ( $row->state === 'unconfirmed' ) {
			return true;
		}
		return $row->state === 'sending' && time() - (int) mysql2date( 'U', (string) $row->created_at, false ) > 120;
	}

	/**
	 * Where a Toolkit request is, in words, from its step.
	 */
	public static function step_label( ?string $step ): string {
		switch ( (string) $step ) {
			case 'submit':
				return __( 'Sent back to the Storyteller', 'beyond-elysium' );
			case 'staff_review':
				return __( 'With the chronicle’s Storyteller', 'beyond-elysium' );
			case 'gaining_staff_review':
			case 'gaining_coord_review':
			case 'teacher_review':
				return __( 'In review', 'beyond-elysium' );
			case 'coord_review':
				return __( 'With the Coordinator', 'beyond-elysium' );
			case 'archivist':
				return __( 'Approved by the Coordinator, with the Archivist', 'beyond-elysium' );
			default:
				return '';
		}
	}

	/**
	 * A request as a route returns it. A person who is not staff gets where it stands and its number, and nothing of
	 * what was sent.
	 *
	 * @return array<string,mixed>
	 */
	public static function view( object $row, bool $manager ): array {
		$view = [
			'id'           => $row->id,
			'change_id'    => $row->change_id,
			'character_id' => $row->character_id,
			'purpose'      => $row->purpose,
			'state'        => $row->state === 'sending' && self::is_resendable( $row ) ? 'unconfirmed' : $row->state,
			'oat_entry_id' => $row->oat_entry_id,
			'step'         => $row->oat_step,
			'step_label'   => self::step_label( $row->oat_step ),
			'sent_at'      => $row->sent_at,
			'checked_at'   => $row->checked_at,
			'answered_at'  => $row->answered_at,
		];
		if ( $manager ) {
			$view['check_error'] = $row->check_error;
			$view['entry_url']   = $row->oat_entry_id ? \BeyondElysium\Services\Oat_Connection::entry_url( (int) $row->oat_entry_id ) : null;
			$view['payload']     = $row->payload;
			$view['sent_by']     = $row->sent_by;
		}
		return $view;
	}

	/**
	 * Removes a request whose call the Toolkit refused: nothing was filed, so nothing is kept.
	 */
	public static function discard( int $id ): bool {
		return Manager::delete( 'coordinator_requests', [ 'id' => $id ] ) !== false;
	}

	private static function decode( object $row ): object {
		$row->id           = (int) $row->id;
		$row->game_id      = (int) $row->game_id;
		$row->character_id = (int) $row->character_id;
		$row->change_id    = $row->change_id === null ? null : (int) $row->change_id;
		$row->oat_entry_id = $row->oat_entry_id === null ? null : (int) $row->oat_entry_id;
		$row->sent_by      = (int) $row->sent_by;
		foreach ( [ 'pick', 'payload' ] as $json ) {
			$decoded       = $row->$json === null ? null : json_decode( (string) $row->$json, true );
			$row->$json    = is_array( $decoded ) ? $decoded : null;
		}
		return $row;
	}
}
