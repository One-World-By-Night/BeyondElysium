<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for uploaded files on a plot, item, or location.
 */
class Attachment {

	/**
	 * Entity types an attachment may belong to.
	 */
	const ENTITY_TYPES = [ 'plot', 'item', 'location' ];

	/**
	 * Per-entity attachment caps.
	 */
	const LIMITS = [ 'plot' => 20, 'item' => 1, 'location' => 20 ];

	/**
	 * Look up a single attachment by its primary key, or null when no row with that id exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		return Manager::get_row( 'SELECT * FROM ' . Manager::table( 'attachments' ) . ' WHERE id = %d', $id );
	}

	/**
	 * Every attachment on one entity, oldest first.
	 *
	 * @param string $entity_type One of ENTITY_TYPES.
	 * @param int    $entity_id
	 * @return object[]
	 */
	public static function for_entity( string $entity_type, int $entity_id ): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'attachments' ) . ' WHERE entity_type = %s AND entity_id = %d ORDER BY created_at ASC, id ASC',
			$entity_type,
			$entity_id
		);
	}

	/**
	 * How many attachments an entity already holds.
	 *
	 * @param string $entity_type
	 * @param int    $entity_id
	 * @return int
	 */
	public static function count_for_entity( string $entity_type, int $entity_id ): int {
		return (int) Manager::get_var(
			'SELECT COUNT(*) FROM ' . Manager::table( 'attachments' ) . ' WHERE entity_type = %s AND entity_id = %d',
			$entity_type,
			$entity_id
		);
	}

	/**
	 * Insert a new attachment row.
	 *
	 * @param array $data game_id, entity_type, entity_id, original_name, stored_name, mime, bytes, created_by.
	 * @return int|false Insert ID, or false when entity_type is not recognized.
	 */
	public static function create( array $data ) {
		if ( ! in_array( $data['entity_type'] ?? '', self::ENTITY_TYPES, true ) ) {
			return false;
		}

		return Manager::insert( 'attachments', [
			'game_id'       => (int) $data['game_id'],
			'entity_type'   => $data['entity_type'],
			'entity_id'     => (int) $data['entity_id'],
			'original_name' => (string) $data['original_name'],
			'stored_name'   => (string) $data['stored_name'],
			'mime'          => (string) $data['mime'],
			'bytes'         => (int) $data['bytes'],
			'created_by'    => $data['created_by'] ?? get_current_user_id(),
			'created_at'    => current_time( 'mysql' ),
		] );
	}

	/**
	 * Delete a single attachment row by its primary key.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$result = Manager::delete( 'attachments', [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Delete every attachment row for one entity.
	 *
	 * @param string $entity_type
	 * @param int    $entity_id
	 * @return void
	 */
	public static function delete_for_entity( string $entity_type, int $entity_id ): void {
		Manager::delete( 'attachments', [ 'entity_type' => $entity_type, 'entity_id' => $entity_id ] );
	}

	/**
	 * The metadata a client is ever given for one attachment.
	 *
	 * @param object $attachment A decoded row from `find()`/`for_entity()`.
	 * @return array<string,mixed>
	 */
	public static function public_shape( object $attachment ): array {
		return [
			'id'            => (int) $attachment->id,
			'entity_type'   => $attachment->entity_type,
			'entity_id'     => (int) $attachment->entity_id,
			'original_name' => $attachment->original_name,
			'mime'          => $attachment->mime,
			'bytes'         => (int) $attachment->bytes,
			'created_by'    => (int) $attachment->created_by,
			'created_at'    => $attachment->created_at,
		];
	}
}
