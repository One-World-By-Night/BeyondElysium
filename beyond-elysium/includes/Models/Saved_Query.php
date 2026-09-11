<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for saved search queries.
 *
 * Saved_Query is a Database\Manager CRUD model backed by the queries table.
 * Each row is one stored search - an inventory type, match-all/match-any
 * toggle, a JSON conditions payload, and a sort key/direction - scoped to one
 * game. "Most Recent Search" is a special row per (game, user) with
 * is_recent_search = 1, upserted automatically whenever that user runs a
 * query, letting them re-run their last search without saving it explicitly.
 */
class Saved_Query {

	/**
	 * Look up a single saved query by its primary key. Returns the row with
	 * its conditions field decoded and match_all/is_recent_search cast to
	 * booleans, or null when no query with that ID exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'queries' ) . ' WHERE id = %d',
			$id
		);
		return self::decode( $row );
	}

	/**
	 * Return every saved query belonging to a game, with the recent-search row
	 * first and the rest alphabetical by name. Includes every inventory type,
	 * with no filtering or pagination.
	 *
	 * @param int $game_id
	 * @return array
	 */
	public static function for_game( int $game_id ): array {
		global $wpdb;
		$table = Manager::table( 'queries' );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE game_id = %d ORDER BY is_recent_search DESC, name ASC",
			$game_id
		) ) ?: [];
		return array_map( [ self::class, 'decode' ], $rows );
	}

	/**
	 * Insert a new saved query. JSON-encodes the conditions array, defaults
	 * inventory to 'char' and sort_direction to 'asc', and defaults
	 * created_by to the current user.
	 *
	 * @param array $data
	 * @return int|false
	 */
	public static function create( array $data ) {
		$insert = [
			'game_id'          => (int) $data['game_id'],
			'name'             => $data['name'],
			'inventory'        => $data['inventory'] ?? 'char',
			'match_all'        => ! empty( $data['match_all'] ) ? 1 : 0,
			'conditions'       => wp_json_encode( $data['conditions'] ?? [] ),
			'sort_key'         => $data['sort_key'] ?? null,
			'sort_direction'   => $data['sort_direction'] ?? 'asc',
			'is_recent_search' => ! empty( $data['is_recent_search'] ) ? 1 : 0,
			'created_by'       => $data['created_by'] ?? get_current_user_id(),
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		];
		return Manager::insert( 'queries', $insert );
	}

	/**
	 * Update a saved query. Writes only the fields present in $data,
	 * JSON-encoding an array conditions payload and normalizing match_all to
	 * an integer, then stamps updated_at.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$allowed = [ 'name', 'inventory', 'match_all', 'conditions', 'sort_key', 'sort_direction' ];
		$update  = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}
		if ( isset( $update['conditions'] ) && is_array( $update['conditions'] ) ) {
			$update['conditions'] = wp_json_encode( $update['conditions'] );
		}
		if ( array_key_exists( 'match_all', $update ) ) {
			$update['match_all'] = $update['match_all'] ? 1 : 0;
		}
		if ( empty( $update ) ) {
			return false;
		}
		$update['updated_at'] = current_time( 'mysql' );
		return Manager::update( 'queries', $update, [ 'id' => $id ] ) !== false;
	}

	/**
	 * Delete a single saved query by its primary key, including a
	 * recent-search row if that is what the ID points to. Does not cascade to
	 * anything else, and returns false if no row with that ID exists.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		return Manager::delete( 'queries', [ 'id' => $id ] ) !== false;
	}

	/**
	 * Upsert this user's "Most Recent Search" row for a game. Updates the
	 * existing recent-search row for this (game, user) pair when one exists,
	 * or creates it otherwise, so an ST can re-run their last search without
	 * having explicitly saved it.
	 *
	 * @param int    $game_id
	 * @param int    $wp_user_id
	 * @param string $inventory
	 * @param bool   $match_all
	 * @param array  $conditions
	 * @return int Query ID.
	 */
	public static function save_recent( int $game_id, int $wp_user_id, string $inventory, bool $match_all, array $conditions ): int {
		global $wpdb;
		$table    = Manager::table( 'queries' );
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE game_id = %d AND created_by = %d AND is_recent_search = 1",
			$game_id,
			$wp_user_id
		) );

		$data = [
			'game_id'    => $game_id,
			'name'       => 'Most Recent Search',
			'inventory'  => $inventory,
			'match_all'  => $match_all,
			'conditions' => $conditions,
		];

		if ( $existing ) {
			self::update( (int) $existing, $data );
			return (int) $existing;
		}

		$data['created_by']       = $wp_user_id;
		$data['is_recent_search'] = true;
		return (int) self::create( $data );
	}

	/**
	 * Decode a row's conditions JSON field into an array, and cast its
	 * match_all and is_recent_search fields to booleans, in place. Passes
	 * null rows through unchanged.
	 *
	 * @param object|null $row
	 * @return object|null
	 */
	private static function decode( $row ) {
		if ( $row && isset( $row->conditions ) && is_string( $row->conditions ) ) {
			$row->conditions = json_decode( $row->conditions, true ) ?? [];
		}
		if ( $row && isset( $row->match_all ) ) {
			$row->match_all = (bool) $row->match_all;
		}
		if ( $row && isset( $row->is_recent_search ) ) {
			$row->is_recent_search = (bool) $row->is_recent_search;
		}
		return $row;
	}
}
