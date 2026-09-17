<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for uploaded files on a plot, item, or location (1.1.0 §2.6).
 *
 * Attachment is a Database\Manager CRUD model backed by the attachments table. It records
 * what exists - who uploaded it, its original name, its real MIME type, its size, and the
 * random `stored_name` component of its path on disk - never who may see it: visibility is the
 * owning entity's own audience, decided the same way everywhere else (`Services\Audience`),
 * checked by `Attachments_Controller` on every read. `stored_name` is deliberately never
 * returned to a caller outside this model and `Services\Attachment_Storage` - the download
 * route is the only path a file's bytes ever reach a client through, and it resolves the path
 * itself rather than trusting one handed back to it.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §2.6
 */
class Attachment {

	/** Entity types an attachment may belong to. */
	const ENTITY_TYPES = [ 'plot', 'item', 'location' ];

	/** Per-entity attachment caps (owner ruling) - an item takes exactly one, never a gallery. */
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
	 * Every attachment on one entity, oldest first - the order they were added in, which is
	 * also upload order for a gallery-style display.
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
	 * How many attachments an entity already holds - checked against LIMITS before a new
	 * upload is accepted. Not lock-protected: two uploads to the same entity landing in the
	 * same instant could both pass this check and briefly push the count one over its limit,
	 * the same class of gap `Connection::create()`'s own F-090 fix closed for a different table
	 * - left open here since the worst outcome is one entity graze its cap by one file, not a
	 * security or data-integrity failure, and neither `Plot` nor `World_Object` has a `lock()`
	 * of its own yet to hold across the check.
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
	 * Insert a new attachment row. Every field is required - there is no partial or default
	 * attachment - since `Services\Attachment_Storage::store()` always has all of them by the
	 * time a file has actually been written to disk.
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
	 * Delete a single attachment row by its primary key. Never touches the file on disk -
	 * callers that need the file gone too (the normal case) go through
	 * `Attachments_Controller::delete_item()`, which reads the row for its `stored_name` before
	 * calling this, then removes the file separately through `Attachment_Storage::delete()`.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$result = Manager::delete( 'attachments', [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Delete every attachment row for one entity. Mirrors `Connection::delete_for_entity()`'s
	 * own cascade shape. This only ever removes rows - a caller that needs the files gone too
	 * (the entity-deletion case) calls `for_entity()` first and removes each file through
	 * `Attachment_Storage::delete()` before calling this.
	 *
	 * @param string $entity_type
	 * @param int    $entity_id
	 * @return void
	 */
	public static function delete_for_entity( string $entity_type, int $entity_id ): void {
		Manager::delete( 'attachments', [ 'entity_type' => $entity_type, 'entity_id' => $entity_id ] );
	}

	/**
	 * The metadata a client is ever given for one attachment - never `stored_name`. Shared by
	 * `Attachments_Controller`'s own upload response and by every entity controller that embeds
	 * an entity's attachment list on its own response (`Plots_Controller`,
	 * `World_Objects_Controller`), so the shape can never drift between the two.
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
