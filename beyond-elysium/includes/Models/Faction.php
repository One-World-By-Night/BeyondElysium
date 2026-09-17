<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for a chronicle group - a sect, coterie, pack, chantry, court,
 * or similar (1.1.0 §3.10) - with its own real, `Audience`-shaped `audience`/`audience_rules`,
 * the same discipline `Secret` already establishes: visibility is a first-class rule, not
 * "hidden until named."
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.10
 */
class Faction {

	/**
	 * The full vocabulary a Storyteller may set directly. Free text is still accepted
	 * (the schema column has no CHECK constraint) - this list is the picker's suggestions,
	 * not an enforced enum, matching the design's own "suggested... free text allowed."
	 */
	const FACTION_TYPES = [ 'sect', 'clan', 'coterie', 'pack', 'chantry', 'court', 'cabal', 'sept', 'motley', 'house', 'other' ];

	/** The narrower subset a player's own `propose_faction` change may create (§3.10). */
	const PLAYER_PROPOSABLE_TYPES = [ 'coterie', 'pack', 'cabal', 'motley', 'other' ];

	const STATUSES = [ 'active', 'disbanded' ];

	/**
	 * Duplicated from `Services\Audience::VALUES` rather than imported - Models does not
	 * depend on Services in this codebase (`Secret::AUDIENCE_VALUES`'s own precedent).
	 */
	const AUDIENCE_VALUES = [ 'everyone', 'storytellers', 'restricted' ];

	const DEFAULT_AUDIENCE = 'storytellers';

	/**
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		return self::decode( Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'factions' ) . ' WHERE id = %d',
			$id
		) );
	}

	/**
	 * Every faction in a chronicle, newest first. `$status` narrows to one status;
	 * omitted returns every status, including disbanded ones (a Storyteller's own list
	 * view needs to see those too, to reactivate one).
	 *
	 * @param int         $game_id
	 * @param string|null $status
	 * @return object[]
	 */
	public static function for_game( int $game_id, ?string $status = null ): array {
		if ( $status !== null ) {
			$rows = Manager::get_results(
				'SELECT * FROM ' . Manager::table( 'factions' ) . ' WHERE game_id = %d AND status = %s ORDER BY created_at DESC',
				$game_id,
				$status
			);
		} else {
			$rows = Manager::get_results(
				'SELECT * FROM ' . Manager::table( 'factions' ) . ' WHERE game_id = %d ORDER BY created_at DESC',
				$game_id
			);
		}
		return array_map( static fn( $row ): object => self::decode( $row ) ?? $row, $rows ?: [] );
	}

	/**
	 * Creates a faction. Validates `faction_type` (only when `$restrict_type` is true - a
	 * player's own proposal, never a Storyteller's direct create) against
	 * `PLAYER_PROPOSABLE_TYPES`, and `audience` against `AUDIENCE_VALUES` always.
	 *
	 * @param array $data
	 * @param bool  $restrict_type Restricts `faction_type` to `PLAYER_PROPOSABLE_TYPES` (a player proposal).
	 * @return int|false Insert ID, or false on any validation failure or unencodable JSON.
	 */
	public static function create( array $data, bool $restrict_type = false ) {
		$faction_type = (string) ( $data['faction_type'] ?? 'other' );
		if ( $restrict_type && ! in_array( $faction_type, self::PLAYER_PROPOSABLE_TYPES, true ) ) {
			return false;
		}

		$audience = $data['audience'] ?? self::DEFAULT_AUDIENCE;
		if ( ! in_array( $audience, self::AUDIENCE_VALUES, true ) ) {
			return false;
		}

		$audience_rules = self::encode_json_field( $data['audience_rules'] ?? null );
		if ( $audience_rules === false ) {
			return false;
		}

		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( $name === '' ) {
			return false;
		}

		return Manager::insert( 'factions', [
			'game_id'              => (int) $data['game_id'],
			'parent_id'            => ! empty( $data['parent_id'] ) ? (int) $data['parent_id'] : null,
			'name'                 => $name,
			'faction_type'         => $faction_type,
			'description'          => $data['description'] ?? null,
			'goals'                => $data['goals'] ?? null,
			'status'               => 'active',
			'created_via_proposal' => ! empty( $data['created_via_proposal'] ) ? 1 : 0,
			'audience'             => $audience,
			'audience_rules'       => $audience_rules,
			'created_by'           => $data['created_by'] ?? get_current_user_id(),
			'created_at'           => current_time( 'mysql' ),
			'updated_at'           => current_time( 'mysql' ),
		] );
	}

	/**
	 * Updates a faction's editable fields. `created_via_proposal`/`game_id` never change
	 * in place, matching `Secret::update()`'s own "identity fields are delete-and-recreate,
	 * not edited" precedent.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$allowed = [ 'parent_id', 'name', 'faction_type', 'description', 'goals', 'status', 'audience', 'audience_rules' ];
		$update  = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( isset( $update['status'] ) && ! in_array( $update['status'], self::STATUSES, true ) ) {
			return false;
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
		if ( array_key_exists( 'name', $update ) ) {
			$update['name'] = trim( (string) $update['name'] );
			if ( $update['name'] === '' ) {
				return false;
			}
		}
		if ( array_key_exists( 'parent_id', $update ) ) {
			$update['parent_id'] = ! empty( $update['parent_id'] ) ? (int) $update['parent_id'] : null;
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql' );
		return Manager::update( 'factions', $update, [ 'id' => $id ] ) !== false;
	}

	/**
	 * Deletes a faction, cascading to its members and un-linking (never deleting) any
	 * position that named it - a position is an independent title even without a faction,
	 * matching `faction_id NULL` being a real, supported state.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$savepoint = Transaction::begin( 'be_faction_delete' );

		Faction_Member::delete_for_faction( $id );
		Manager::update( 'positions', [ 'faction_id' => null ], [ 'faction_id' => $id ] );
		$result = Manager::delete( 'factions', [ 'id' => $id ] );

		if ( $result === false ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Transaction::commit( $savepoint );
		return true;
	}

	/**
	 * Encode a value for a JSON column - `Secret::encode_json_field()`'s exact contract.
	 *
	 * @param mixed $value
	 * @return string|false|null
	 */
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
	 * Decodes the `audience_rules` JSON column on a row object in place - `Secret::decode()`'s
	 * exact contract, including its "corrupt JSON becomes null, logged, row kept" behavior.
	 *
	 * @param object|null $row
	 * @return object|null
	 */
	private static function decode( $row ) {
		if ( $row && property_exists( $row, 'audience_rules' ) && is_string( $row->audience_rules ) ) {
			$decoded = json_decode( $row->audience_rules, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( sprintf(
					'Beyond Elysium: faction id %d has corrupt audience_rules JSON; treating as null.',
					(int) ( $row->id ?? 0 )
				) );
				$decoded = null;
			}
			$row->audience_rules = $decoded;
		}
		// D51/D53: a tinyint(1) reaches $wpdb as the string "0", which is truthy in JS.
		if ( $row && property_exists( $row, 'created_via_proposal' ) ) {
			$row->created_via_proposal = (bool) $row->created_via_proposal;
		}
		return $row;
	}
}
