<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for a chronicle office (Prince, Sheriff, Grand Elder, ...) -
 * 1.1.0 §3.10, F2. A position may belong to a faction (a court seat) or stand alone (an
 * independent title, `faction_id` null); `holder_public = 0` hides who holds it from a
 * non-Storyteller without hiding that it exists.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.10
 */
class Position {

	const AUDIENCE_VALUES  = [ 'everyone', 'storytellers', 'restricted' ];
	const DEFAULT_AUDIENCE = 'everyone';

	/**
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		return self::decode( Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'positions' ) . ' WHERE id = %d',
			$id
		) );
	}

	/**
	 * Every position in a chronicle, oldest first. `$faction_id` narrows to one faction's
	 * own seats; omitted returns every position in the game, faction seats and independent
	 * titles alike.
	 *
	 * @param int      $game_id
	 * @param int|null $faction_id
	 * @return object[]
	 */
	public static function for_game( int $game_id, ?int $faction_id = null ): array {
		if ( $faction_id !== null ) {
			$rows = Manager::get_results(
				'SELECT * FROM ' . Manager::table( 'positions' ) . ' WHERE game_id = %d AND faction_id = %d ORDER BY created_at ASC',
				$game_id,
				$faction_id
			);
		} else {
			$rows = Manager::get_results(
				'SELECT * FROM ' . Manager::table( 'positions' ) . ' WHERE game_id = %d ORDER BY created_at ASC',
				$game_id
			);
		}
		return array_map( static fn( $row ): object => self::decode( $row ) ?? $row, $rows ?: [] );
	}

	/**
	 * Every position a character currently holds, across every faction and independent
	 * title - `Query_Engine`'s new `position_title` source reads this, and it backs a
	 * character's own Who's Who titles list (filtered by audience/holder_public there,
	 * not here - this returns the raw held rows regardless of who's asking).
	 *
	 * @param int $character_id
	 * @return object[]
	 */
	public static function for_character( int $character_id ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'positions' ) . ' WHERE character_id = %d ORDER BY created_at ASC',
			$character_id
		);
		return array_map( static fn( $row ): object => self::decode( $row ) ?? $row, $rows ?: [] );
	}

	/**
	 * Creates a position. `character_id`/`since` may be set immediately (a position created
	 * with a holder already known) or left null (a vacant title) - either way, a real holder
	 * is only ever recorded in `be_position_history` via `set_holder()`, never here, so a
	 * position's very first holder gets the same history row a later change does.
	 *
	 * @param array $data
	 * @return int|false
	 */
	public static function create( array $data ) {
		$audience = $data['audience'] ?? self::DEFAULT_AUDIENCE;
		if ( ! in_array( $audience, self::AUDIENCE_VALUES, true ) ) {
			return false;
		}
		$audience_rules = self::encode_json_field( $data['audience_rules'] ?? null );
		if ( $audience_rules === false ) {
			return false;
		}
		$title = trim( (string) ( $data['title'] ?? '' ) );
		if ( $title === '' ) {
			return false;
		}

		$id = Manager::insert( 'positions', [
			'game_id'        => (int) $data['game_id'],
			'faction_id'     => ! empty( $data['faction_id'] ) ? (int) $data['faction_id'] : null,
			'title'          => $title,
			'character_id'   => null,
			'since'          => null,
			'holder_public'  => array_key_exists( 'holder_public', $data ) ? ( $data['holder_public'] ? 1 : 0 ) : 1,
			'audience'       => $audience,
			'audience_rules' => $audience_rules,
			'notes'          => $data['notes'] ?? null,
			'created_by'     => $data['created_by'] ?? get_current_user_id(),
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		] );

		if ( $id && ! empty( $data['character_id'] ) ) {
			self::set_holder( (int) $id, (int) $data['character_id'] );
		}

		return $id;
	}

	/**
	 * Updates a position's editable fields, other than its holder - `set_holder()` is the
	 * only path that changes `character_id`, since that write must also record history.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$allowed = [ 'faction_id', 'title', 'holder_public', 'audience', 'audience_rules', 'notes' ];
		$update  = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( isset( $update['audience'] ) && ! in_array( $update['audience'], self::AUDIENCE_VALUES, true ) ) {
			return false;
		}
		if ( array_key_exists( 'audience_rules', $update ) ) {
			$update['audience_rules'] = self::encode_json_field( $update['audience_rules'] );
			if ( $update['audience_rules'] === false ) {
				return false;
			}
		}
		if ( array_key_exists( 'title', $update ) ) {
			$update['title'] = trim( (string) $update['title'] );
			if ( $update['title'] === '' ) {
				return false;
			}
		}
		if ( array_key_exists( 'faction_id', $update ) ) {
			$update['faction_id'] = ! empty( $update['faction_id'] ) ? (int) $update['faction_id'] : null;
		}
		if ( array_key_exists( 'holder_public', $update ) ) {
			$update['holder_public'] = $update['holder_public'] ? 1 : 0;
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql' );
		return Manager::update( 'positions', $update, [ 'id' => $id ] ) !== false;
	}

	/**
	 * Changes a position's holder, writing a `be_position_history` row for the change: the
	 * outgoing holder's own open history row (if any) gets `ended` stamped today, and a new
	 * open row (`ended` null) starts for the incoming holder - `null` vacates the position
	 * entirely, ending the current holder's row with no new one to replace it.
	 *
	 * @param int      $id
	 * @param int|null $character_id
	 * @return bool
	 */
	public static function set_holder( int $id, ?int $character_id ): bool {
		$position = self::find( $id );
		if ( ! $position ) {
			return false;
		}

		$savepoint = Transaction::begin( 'be_position_set_holder' );
		$today     = current_time( 'Y-m-d' );

		Position_History::close_open_row( $id, $today );

		if ( $character_id !== null ) {
			if ( ! Position_History::open_row( $id, $character_id, $today ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
		}

		$written = Manager::update( 'positions', [
			'character_id' => $character_id,
			'since'        => $character_id !== null ? $today : null,
			'updated_at'   => current_time( 'mysql' ),
		], [ 'id' => $id ] ) !== false;

		if ( ! $written ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Transaction::commit( $savepoint );
		return true;
	}

	/**
	 * Deletes a position outright, along with its full history - unlike a faction (which
	 * un-links rather than deletes a position it's removed from), a position has no
	 * meaningful existence once deleted.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$savepoint = Transaction::begin( 'be_position_delete' );

		Position_History::delete_for_position( $id );
		$result = Manager::delete( 'positions', [ 'id' => $id ] );

		if ( $result === false ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Transaction::commit( $savepoint );
		return true;
	}

	/** @param mixed $value @return string|false|null */
	private static function encode_json_field( $value ) {
		if ( $value === null ) {
			return null;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}

	/**
	 * @param object|null $row
	 * @return object|null
	 */
	private static function decode( $row ) {
		if ( $row && property_exists( $row, 'audience_rules' ) && is_string( $row->audience_rules ) ) {
			$decoded = json_decode( $row->audience_rules, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( sprintf(
					'Beyond Elysium: position id %d has corrupt audience_rules JSON; treating as null.',
					(int) ( $row->id ?? 0 )
				) );
				$decoded = null;
			}
			$row->audience_rules = $decoded;
		}
		// D51/D53: a tinyint(1) reaches $wpdb as the string "0", which is truthy in JS.
		if ( $row && property_exists( $row, 'holder_public' ) ) {
			$row->holder_public = (bool) $row->holder_public;
		}
		return $row;
	}
}
