<?php

namespace BeyondElysium\Models;

defined( 'ABSPATH' ) || exit;

/**
 * A thin, fixed-vocabulary wrapper over `Connection` for a location's four named links
 * (1.1.0 §3.9 item 2): who owns it, whose domain it is, whose haven it is, and who's based
 * there. Deliberately separate from the generic, freeform-labeled connections
 * `ConnectionManager.tsx`/`World_Objects_Controller::get_item()` already expose for every
 * world object - a location link always has one of these four exact labels, and its source
 * is always a character today (a faction, once §3.10 exists, per the design's own note).
 *
 * Not a new table: every link is a plain `connections` row (`source_type` = `character`,
 * `target_type` = `world_object`, `target_id` = the location), distinguished purely by
 * `label`. This class exists to keep that fixed vocabulary and its validation in one place
 * rather than duplicated at every call site.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.9
 */
class Location_Link {

	const OWNER    = 'owner';
	const DOMAIN   = 'domain';
	const HAVEN    = 'haven';
	const BASED_AT = 'based_at';

	/** @var string[] */
	const LABELS = [ self::OWNER, self::DOMAIN, self::HAVEN, self::BASED_AT ];

	/**
	 * Source entity types a location link may come from today. Only `character` until F1/F2
	 * (§3.10) ship a real `Faction`/`Faction_Member` model for `Connection::$valid_entity_types`
	 * to grow a `'faction'` entry - the design's own "a faction owner makes its members
	 * connected" half of item 5 is a real, logged gap until then, not silently dropped.
	 *
	 * @var string[]
	 */
	const SOURCE_TYPES = [ 'character' ];

	/**
	 * Every link on one location, any label - the Links panel's own read.
	 *
	 * @param int $location_id
	 * @return object[] Connection rows.
	 */
	public static function for_location( int $location_id ): array {
		return array_values( array_filter(
			Connection::for_target( 'world_object', $location_id ),
			static fn( $c ) => in_array( $c->label, self::LABELS, true )
		) );
	}

	/**
	 * The single character connected to a location under one label - `owner`, `domain`, or
	 * `haven` (never `based_at`, which is a roster, not a single holder). Null when no such
	 * link exists. Used by the display-over-Grapevine-text substitution (item 3): the first
	 * matching link, oldest first, since `Connection::for_target()` orders its own rows that
	 * way already.
	 *
	 * @param int    $location_id
	 * @param string $label One of OWNER, DOMAIN, HAVEN.
	 * @return object|null Connection row.
	 */
	public static function holder( int $location_id, string $label ): ?object {
		foreach ( self::for_location( $location_id ) as $connection ) {
			if ( $connection->label === $label ) {
				return $connection;
			}
		}
		return null;
	}

	/**
	 * The display-over-Grapevine-text substitution (item 3): the linked owner's name and the
	 * parent location's name, wherever a real link exists, falling back to the location's own
	 * typed `owner`/`where` text properties otherwise. The underlying text is never changed by
	 * this - it is read here purely for the fallback, and keeps round-tripping through import/
	 * export exactly as Grapevine wrote it. Shared by the location detail page, the Location
	 * Cards report, and the `.gex` export, so the three can never drift out of step on which
	 * one prefers a link.
	 *
	 * @param object $location A decoded world_objects row (`properties` a real array).
	 * @return array{owner:string,where:string}
	 */
	public static function resolve_owner_and_where( object $location ): array {
		$properties = is_array( $location->properties ?? null ) ? $location->properties : [];

		$owner_link = self::holder( (int) $location->id, self::OWNER );
		$owner      = (string) ( $properties['owner'] ?? '' );
		if ( $owner_link && $owner_link->source_type === 'character' ) {
			$character = Character::find( (int) $owner_link->source_id );
			if ( $character ) {
				$owner = ! empty( $character->public_name ) ? (string) $character->public_name : (string) $character->name;
			}
		}

		$where = (string) ( $properties['where'] ?? '' );
		if ( ! empty( $location->parent_id ) ) {
			$parent = World_Object::find( (int) $location->parent_id );
			if ( $parent ) {
				$where = (string) $parent->name;
			}
		}

		return [ 'owner' => $owner, 'where' => $where ];
	}

	/**
	 * Creates a location link. Validates the label is one of the four fixed values, the
	 * source is a recognized type (character only today), the source exists, and the target
	 * is a real location in the same game - the same "check before Connection::create()"
	 * discipline every other typed-connection wrapper in this codebase already follows.
	 *
	 * @param int    $game_id
	 * @param string $label
	 * @param string $source_type
	 * @param int    $source_id
	 * @param int    $location_id
	 * @param int    $created_by
	 * @return int|false Connection id, or false on any validation failure.
	 */
	public static function create( int $game_id, string $label, string $source_type, int $source_id, int $location_id, int $created_by ) {
		if ( ! in_array( $label, self::LABELS, true ) ) {
			return false;
		}
		if ( ! in_array( $source_type, self::SOURCE_TYPES, true ) ) {
			return false;
		}
		if ( $source_type === 'character' && ! Character::find( $source_id ) ) {
			return false;
		}
		$location = World_Object::find( $location_id );
		if ( ! $location || $location->object_type !== 'location' || (int) $location->game_id !== $game_id ) {
			return false;
		}

		return Connection::create( [
			'game_id'     => $game_id,
			'source_type' => $source_type,
			'source_id'   => $source_id,
			'target_type' => 'world_object',
			'target_id'   => $location_id,
			'label'       => $label,
			'created_by'  => $created_by,
		] );
	}

	/**
	 * Removes one location link by its connection id. Confirms it is really a location link
	 * (one of the four fixed labels, targeting a location) before deleting, so this can never
	 * be used to delete an unrelated connection by guessing its id.
	 *
	 * @param int $connection_id
	 * @param int $location_id
	 * @return bool
	 */
	public static function delete( int $connection_id, int $location_id ): bool {
		$connection = Connection::find( $connection_id );
		if ( ! $connection
			|| ! in_array( $connection->label, self::LABELS, true )
			|| $connection->target_type !== 'world_object'
			|| (int) $connection->target_id !== $location_id
		) {
			return false;
		}
		return Connection::delete( $connection_id );
	}
}
