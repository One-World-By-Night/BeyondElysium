<?php

namespace BeyondElysium\Models;

defined( 'ABSPATH' ) || exit;

/**
 * A thin, fixed-vocabulary wrapper over `Connection` for a location's four named links: who owns it, whose domain it
 * is, whose haven it is, and who is based there.
 */
class Location_Link {

	const OWNER    = 'owner';
	const DOMAIN   = 'domain';
	const HAVEN    = 'haven';
	const BASED_AT = 'based_at';

	/** @var string[] */
	const LABELS = [ self::OWNER, self::DOMAIN, self::HAVEN, self::BASED_AT ];

	/**
	 * Source entity types a location link may come from today.
	 *
	 * @var string[]
	 */
	const SOURCE_TYPES = [ 'character' ];

	/**
	 * Every link on one location, any label.
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
	 * The single character connected to a location under one label.
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
	 * Resolves a location's owner and where text: the linked owner's name and the parent location's name where a real
	 * link exists, otherwise the location's own typed `owner` and `where` properties.
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
	 * Creates a location link.
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
	 * Removes one location link by its connection id.
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
