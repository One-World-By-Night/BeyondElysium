<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for one character's membership in a faction.
 */
class Faction_Member {

	/**
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		return self::decode( Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'faction_members' ) . ' WHERE id = %d',
			$id
		) );
	}

	/**
	 * One faction's own membership row for one character, or null if they don't belong.
	 *
	 * @param int $faction_id
	 * @param int $character_id
	 * @return object|null
	 */
	public static function find_for( int $faction_id, int $character_id ): ?object {
		return self::decode( Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'faction_members' ) . ' WHERE faction_id = %d AND character_id = %d',
			$faction_id,
			$character_id
		) );
	}

	/**
	 * Every member of one faction, oldest first (so a proposer added first stays first).
	 *
	 * @param int $faction_id
	 * @return object[]
	 */
	public static function for_faction( int $faction_id ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'faction_members' ) . ' WHERE faction_id = %d ORDER BY created_at ASC',
			$faction_id
		) ?: [];
		return array_map( static fn( $row ): object => self::decode( $row ) ?? $row, $rows );
	}

	/**
	 * Every faction a character belongs to, joined to its own `be_factions` row.
	 *
	 * @param int $character_id
	 * @return object[] Each row carries both the membership fields and the faction's own columns.
	 */
	public static function for_character( int $character_id ): array {
		global $wpdb;
		$members  = Manager::table( 'faction_members' );
		$factions = Manager::table( 'factions' );
		$rows     = $wpdb->get_results( $wpdb->prepare(
			"SELECT fm.*, f.name AS faction_name, f.faction_type, f.status AS faction_status,
				f.audience AS faction_audience, f.audience_rules AS faction_audience_rules
			FROM {$members} fm
			INNER JOIN {$factions} f ON f.id = fm.faction_id
			WHERE fm.character_id = %d
			ORDER BY fm.created_at ASC",
			$character_id
		) ) ?: [];
		return array_map( static fn( $row ): object => self::decode( $row ) ?? $row, $rows );
	}

	/**
	 * Every character id belonging to one faction.
	 *
	 * @param int $faction_id
	 * @return int[]
	 */
	public static function character_ids_for_faction( int $faction_id ): array {
		return array_map( 'intval', array_column( self::for_faction( $faction_id ), 'character_id' ) );
	}

	/**
	 * Adds a character to a faction.
	 *
	 * @param int  $faction_id
	 * @param int  $character_id
	 * @param int  $added_by
	 * @param bool $is_leader
	 * @param string|null $rank
	 * @return int|false Insert ID, or false if already a member or the insert failed.
	 */
	public static function add( int $faction_id, int $character_id, int $added_by, bool $is_leader = false, ?string $rank = null ) {
		if ( self::find_for( $faction_id, $character_id ) !== null ) {
			return false;
		}

		return Manager::insert( 'faction_members', [
			'faction_id'   => $faction_id,
			'character_id' => $character_id,
			'member_rank'  => $rank,
			'is_leader'    => $is_leader ? 1 : 0,
			'added_by'     => $added_by,
			'created_at'   => current_time( 'mysql' ),
		] );
	}

	/**
	 * How many leaders a faction currently has.
	 *
	 * @param int $faction_id
	 * @return int
	 */
	public static function leader_count( int $faction_id ): int {
		$count = 0;
		foreach ( self::for_faction( $faction_id ) as $member ) {
			if ( ! empty( $member->is_leader ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Sets or clears a member's leader flag.
	 *
	 * @param int  $faction_id
	 * @param int  $character_id
	 * @param bool $is_leader
	 * @return bool
	 */
	public static function set_leader( int $faction_id, int $character_id, bool $is_leader ): bool {
		if ( ! $is_leader ) {
			$member = self::find_for( $faction_id, $character_id );
			if ( $member && ! empty( $member->is_leader ) && self::leader_count( $faction_id ) <= 1 ) {
				return false;
			}
		}
		return Manager::update( 'faction_members', [ 'is_leader' => $is_leader ? 1 : 0 ], [
			'faction_id'   => $faction_id,
			'character_id' => $character_id,
		] ) !== false;
	}

	/**
	 * Sets a member's rank: a free-text title within the faction, e.g. "Whip".
	 *
	 * @param int         $faction_id
	 * @param int         $character_id
	 * @param string|null $rank
	 * @return bool
	 */
	public static function set_rank( int $faction_id, int $character_id, ?string $rank ): bool {
		return Manager::update( 'faction_members', [ 'member_rank' => $rank ], [
			'faction_id'   => $faction_id,
			'character_id' => $character_id,
		] ) !== false;
	}

	/**
	 * Removes a character from a faction.
	 *
	 * @param int $faction_id
	 * @param int $character_id
	 * @return bool
	 */
	public static function remove( int $faction_id, int $character_id ): bool {
		$member = self::find_for( $faction_id, $character_id );
		if ( $member && ! empty( $member->is_leader ) && self::leader_count( $faction_id ) <= 1 ) {
			return false;
		}
		return Manager::delete( 'faction_members', [
			'faction_id'   => $faction_id,
			'character_id' => $character_id,
		] ) !== false;
	}

	/**
	 * Removes every member of a faction being deleted outright.
	 *
	 * @param int $faction_id
	 * @return bool
	 */
	public static function delete_for_faction( int $faction_id ): bool {
		return Manager::delete( 'faction_members', [ 'faction_id' => $faction_id ] ) !== false;
	}

	/**
	 * Casts a row's `is_leader` flag to a real boolean.
	 *
	 * @param object|null $row
	 * @return object|null
	 */
	private static function decode( $row ) {
		if ( $row && property_exists( $row, 'is_leader' ) ) {
			$row->is_leader = (bool) $row->is_leader;
		}
		return $row;
	}
}
