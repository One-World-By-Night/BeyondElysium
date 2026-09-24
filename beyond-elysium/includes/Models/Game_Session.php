<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for one chronicle's game nights.
 */
class Game_Session {

	/**
	 * Look up a single session by its primary key, or null when no row with that id exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'game_sessions' ) . ' WHERE id = %d',
			$id
		);
		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Look up a session by its chronicle and calendar date, or null when none exists yet for that date.
	 *
	 * @param int    $game_id
	 * @param string $game_date Y-m-d.
	 * @return object|null
	 */
	public static function find_by_date( int $game_id, string $game_date ): ?object {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'game_sessions' ) . ' WHERE game_id = %d AND game_date = %s',
			$game_id,
			$game_date
		);
		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Every session for a chronicle, soonest first, optionally narrowed to a date range.
	 *
	 * @param int         $game_id
	 * @param string|null $from Y-m-d, inclusive.
	 * @param string|null $to   Y-m-d, inclusive.
	 * @return object[]
	 */
	public static function for_game( int $game_id, ?string $from = null, ?string $to = null ): array {
		$query = 'SELECT * FROM ' . Manager::table( 'game_sessions' ) . ' WHERE game_id = %d';
		$args  = [ $game_id ];

		if ( $from !== null ) {
			$query .= ' AND game_date >= %s';
			$args[] = $from;
		}
		if ( $to !== null ) {
			$query .= ' AND game_date <= %s';
			$args[] = $to;
		}
		$query .= ' ORDER BY game_date ASC';

		return array_map( [ self::class, 'decode_row' ], Manager::get_results( $query, ...$args ) );
	}

	/**
	 * Creates a new session. game_id and game_date are required.
	 *
	 * @param array $data
	 * @return int|false
	 */
	public static function create( array $data ) {
		if ( empty( $data['game_date'] ) ) {
			return false;
		}

		$insert = [
			'game_id'    => (int) $data['game_id'],
			'game_date'  => $data['game_date'],
			'start_time' => $data['start_time'] ?? null,
			'place'      => $data['place'] ?? null,
			'notes'      => $data['notes'] ?? null,
			'created_by' => $data['created_by'] ?? get_current_user_id(),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		];

		foreach ( self::datetime_fields() as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$insert[ $field ] = $data[ $field ];
			}
		}
		if ( array_key_exists( 'default_batch_id', $data ) ) {
			$insert['default_batch_id'] = $data['default_batch_id'] !== null ? (int) $data['default_batch_id'] : null;
		}
		if ( array_key_exists( 'downtime_extensions', $data ) ) {
			$insert['downtime_extensions'] = self::encode_json_field( $data['downtime_extensions'] );
			if ( $insert['downtime_extensions'] === false ) {
				return false;
			}
		}

		return Manager::insert( 'game_sessions', $insert );
	}

	/**
	 * Updates a session.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$allowed = array_merge(
			[ 'game_date', 'start_time', 'place', 'notes', 'default_batch_id' ],
			self::datetime_fields()
		);

		$update = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}
		if ( array_key_exists( 'downtime_extensions', $data ) ) {
			$update['downtime_extensions'] = self::encode_json_field( $data['downtime_extensions'] );
			if ( $update['downtime_extensions'] === false ) {
				return false;
			}
		}
		if ( array_key_exists( 'default_batch_id', $update ) && $update['default_batch_id'] !== null ) {
			$update['default_batch_id'] = (int) $update['default_batch_id'];
		}

		if ( empty( $update ) ) {
			return true;
		}
		$update['updated_at'] = current_time( 'mysql' );

		return (bool) Manager::update( 'game_sessions', $update, [ 'id' => $id ] );
	}

	/**
	 * Deletes a session.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		return (bool) Manager::delete( 'game_sessions', [ 'id' => $id ] );
	}

	/**
	 * Whether a session has real data recorded against it and can no longer be deleted: attendance, a casting or an
	 * after-game report.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function is_in_use( int $id ): bool {
		return count( Attendance::for_session( $id ) ) > 0
			|| Npc_Casting::exists_for_session( $id )
			|| After_Game_Report::exists_for_session( $id );
	}

	/**
	 * Every datetime-typed column besides created_at/updated_at, shared by create() and update().
	 */
	private static function datetime_fields(): array {
		return [
			'downtime_opens_at',
			'downtime_deadline_at',
			'reports_due_at',
			'attendance_xp_awarded_at',
			'attendance_xp_awarded_by',
			'report_xp_awarded_at',
			'report_xp_awarded_by',
		];
	}

	/**
	 * JSON-encodes a value for storage, or returns null unchanged.
	 *
	 * @param mixed $value
	 * @return string|false|null
	 */
	private static function encode_json_field( $value ) {
		return $value === null ? null : wp_json_encode( $value );
	}

	/**
	 * Decodes the downtime_extensions JSON column on a row object in place.
	 *
	 * @param object $row
	 * @return object
	 */
	private static function decode_row( object $row ): object {
		if ( property_exists( $row, 'downtime_extensions' ) && $row->downtime_extensions !== null ) {
			$decoded = json_decode( $row->downtime_extensions, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$id = property_exists( $row, 'id' ) ? (int) $row->id : 0;
				error_log( sprintf(
					'Beyond Elysium: game session id %d has corrupt downtime_extensions JSON; treating as null.',
					$id
				) );
				$decoded = null;
			}
			$row->downtime_extensions = $decoded;
		}
		return $row;
	}
}
