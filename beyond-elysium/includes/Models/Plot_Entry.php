<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for individual posts within a plot's thread.
 *
 * Plot_Entry is a Database\Manager CRUD model backed by the plot_entries
 * table. Each row is one chronological post on a plot's thread, distinguished
 * by its entry_type - action, response, note, or resolution - rather than by
 * separate tables per type. Creating an entry also touches its parent plot's
 * updated_at so activity feeds stay current.
 *
 * @see BE_PROCESS/releases/workflow-0.5.md Step 1.2
 */
class Plot_Entry {

	/**
	 * Entry types. `note` is ST-only, enforced by the REST controller rather
	 * than here, since permission checks belong at the request boundary.
	 */
	const ENTRY_TYPES = [ 'action', 'response', 'note', 'resolution', 'rumor_level' ];

	/** Visible to whoever can already see the parent plot (1.1.0 §2.4). The schema default. */
	const AUDIENCE_PLOT = 'plot';
	/** Visible to Storytellers, Narrators, and the entry's own author. */
	const AUDIENCE_STORYTELLERS = 'storytellers';
	/** A Storyteller post aimed at specific characters' players - see `audience_character_ids`. */
	const AUDIENCE_CHARACTERS = 'characters';

	/**
	 * Valid stored `audience` values (1.1.0 §2.4) - duplicated as a literal list rather than
	 * importing `Services\Audience` here, matching this codebase's existing
	 * Models-doesn't-depend-on-Services precedent (see `Plot::AUDIENCE_VALUES`). Distinct from
	 * `Services\Audience::VALUES`: an entry's audience is a property of one authored post, not
	 * of a whole entity, and `characters` (a literal id list) has no equivalent there.
	 */
	const AUDIENCE_VALUES = [ self::AUDIENCE_PLOT, self::AUDIENCE_STORYTELLERS, self::AUDIENCE_CHARACTERS ];

	const DEFAULT_AUDIENCE = self::AUDIENCE_PLOT;

	/**
	 * Look up a single plot entry by its primary key, with `audience_character_ids`
	 * decoded, or null when no entry with that ID exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ) {
		return self::decode_json_columns( Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'plot_entries' ) . ' WHERE id = %d',
			$id
		) );
	}

	/**
	 * Return the entries belonging to one plot, ordered oldest first - the
	 * order a thread reads in. Supports filtering by entry_type, with no
	 * pagination.
	 *
	 * @param int   $plot_id
	 * @param array $args Filters: entry_type.
	 * @return array
	 */
	public static function for_plot( int $plot_id, array $args = [] ): array {
		global $wpdb;
		$table  = Manager::table( 'plot_entries' );
		$where  = [ 'plot_id = %d' ];
		$values = [ $plot_id ];

		if ( ! empty( $args['entry_type'] ) ) {
			$where[]  = 'entry_type = %s';
			$values[] = $args['entry_type'];
		}

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY created_at ASC, id ASC';
		$sql = $wpdb->prepare( $sql, $values );
		return array_map( [ self::class, 'decode_row' ], $wpdb->get_results( $sql ) ?: [] );
	}

	/**
	 * Insert a new plot entry. Validates entry_type against ENTRY_TYPES and
	 * audience against AUDIENCE_VALUES, and on success also updates the parent
	 * plot's updated_at timestamp so a plot with a new entry sorts as recently
	 * changed.
	 *
	 * @param array $data
	 * @return int|false Insert ID, or false if the entry type or audience is not recognized.
	 */
	public static function create( array $data ) {
		$entry_type = $data['entry_type'] ?? '';
		if ( ! in_array( $entry_type, self::ENTRY_TYPES, true ) ) {
			return false;
		}
		$audience = $data['audience'] ?? self::DEFAULT_AUDIENCE;
		if ( ! in_array( $audience, self::AUDIENCE_VALUES, true ) ) {
			return false;
		}

		$insert = [
			'plot_id'    => (int) $data['plot_id'],
			'author_id'  => $data['author_id'] ?? get_current_user_id(),
			'entry_type' => $entry_type,
			'content'    => $data['content'] ?? '',
			'event_date' => $data['event_date'] ?? null,
			'audience'   => $audience,
			'created_at' => current_time( 'mysql' ),
		];
		if ( array_key_exists( 'audience_character_ids', $data ) ) {
			$insert['audience_character_ids'] = self::encode_json_field( $data['audience_character_ids'] );
			if ( $insert['audience_character_ids'] === false ) {
				return false;
			}
		}
		// held/release_batch_id (1.1.0 §3.2/§3.3): a downtime answer is held by default, the
		// caller (Entries_Controller) decides when - this model only writes what it's given.
		if ( array_key_exists( 'held', $data ) ) {
			$insert['held'] = $data['held'] ? 1 : 0;
		}
		if ( array_key_exists( 'release_batch_id', $data ) ) {
			$insert['release_batch_id'] = ! empty( $data['release_batch_id'] ) ? (int) $data['release_batch_id'] : null;
		}
		// level (1.1.0 §3.4): a rumor_level entry's own 1-10 rung. Never set on any other
		// entry type - a rumor_level entry carries no held/release_batch_id of its own either,
		// since it follows its plot's release state rather than having one.
		if ( array_key_exists( 'level', $data ) ) {
			$level = $data['level'] !== null ? (int) $data['level'] : null;
			if ( $level !== null && ( $level < 1 || $level > 10 ) ) {
				return false;
			}
			$insert['level'] = $level;
		}

		$id = Manager::insert( 'plot_entries', $insert );

		if ( $id ) {
			Manager::update( 'plots', [ 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $insert['plot_id'] ] );
		}

		return $id;
	}

	/**
	 * Update a plot entry's content, event_date, audience, and
	 * audience_character_ids fields. Writes only the fields present in $data;
	 * no other fields on the entry are editable through this method.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		if ( isset( $data['audience'] ) && ! in_array( $data['audience'], self::AUDIENCE_VALUES, true ) ) {
			return false;
		}

		$allowed = [ 'content', 'event_date', 'audience', 'held', 'release_batch_id', 'level' ];
		$update  = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}
		if ( array_key_exists( 'audience_character_ids', $data ) ) {
			$update['audience_character_ids'] = self::encode_json_field( $data['audience_character_ids'] );
			if ( $update['audience_character_ids'] === false ) {
				return false;
			}
		}
		if ( array_key_exists( 'held', $update ) ) {
			$update['held'] = $update['held'] ? 1 : 0;
		}
		if ( array_key_exists( 'release_batch_id', $update ) ) {
			$update['release_batch_id'] = ! empty( $update['release_batch_id'] ) ? (int) $update['release_batch_id'] : null;
		}
		if ( array_key_exists( 'level', $update ) ) {
			$level = $update['level'] !== null ? (int) $update['level'] : null;
			if ( $level !== null && ( $level < 1 || $level > 10 ) ) {
				return false;
			}
			$update['level'] = $level;
		}

		if ( empty( $update ) ) {
			return false;
		}

		$result = Manager::update( 'plot_entries', $update, [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Every entry held for one release batch (§3.2) - a batch's own "Downtime answers" list,
	 * and Release_Engine::release()'s recipient collection for the entry half of a batch.
	 *
	 * @param int $release_batch_id
	 * @return object[]
	 */
	public static function for_release_batch( int $release_batch_id ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'plot_entries' ) . ' WHERE release_batch_id = %d ORDER BY created_at DESC',
			$release_batch_id
		);
		return array_map( [ self::class, 'decode_row' ], $rows );
	}

	/**
	 * Delete a single plot entry by its primary key. Does not touch the parent
	 * plot or any other entry on its thread, and does not renumber or reorder
	 * the entries that remain.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$result = Manager::delete( 'plot_entries', [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Delete every entry belonging to a plot. Used to cascade a plot deletion
	 * so no orphaned entries remain once the plot itself is gone, removing the
	 * whole thread in one query.
	 *
	 * @param int $plot_id
	 * @return void
	 */
	public static function delete_for_plot( int $plot_id ): void {
		Manager::delete( 'plot_entries', [ 'plot_id' => $plot_id ] );
	}

	/**
	 * Encode a value for a JSON column, matching `Plot::encode_json_field()`'s contract:
	 * null stays null, an array or object is JSON-encoded, anything else is cast to string.
	 *
	 * @param mixed $value
	 * @return string|null|false False when `wp_json_encode()` itself cannot encode the value.
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
	 * Decode the audience_character_ids JSON column on a row object in place. A NULL
	 * column value is left as null (a legitimate value, not corruption); a non-NULL
	 * value that fails to decode is logged and replaced with null rather than the row
	 * being dropped - matching `Plot::decode_row()`'s own contract exactly.
	 *
	 * @param object|null $row
	 * @return object|null
	 */
	private static function decode_json_columns( $row ) {
		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * decode_json_columns() for a row already known to exist.
	 *
	 * @param object $row
	 * @return object
	 */
	private static function decode_row( object $row ): object {
		// property_exists() guards $row->id the same way it already guards every other
		// field here - PHPStan has no declared shape for a plain object to check against.
		$id = property_exists( $row, 'id' ) ? (int) $row->id : 0;

		// A single-element loop, matching Plot::decode_row()'s own shape exactly - a future
		// second JSON column on this table extends the array, not the method.
		foreach ( [ 'audience_character_ids' ] as $field ) {
			if ( ! property_exists( $row, $field ) || $row->$field === null ) {
				continue;
			}

			$decoded = json_decode( $row->$field, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( sprintf(
					'Beyond Elysium: plot entry id %d has corrupt %s JSON; treating as null.',
					$id,
					$field
				) );
				$decoded = null;
			}

			$row->$field = $decoded;
		}

		// D51/D53's own bug class: $wpdb returns tinyint(1) as the string "0", which is
		// truthy in JavaScript. Cast to a real bool per the 1.0.1 owner ruling.
		if ( property_exists( $row, 'held' ) ) {
			$row->held = (bool) $row->held;
		}

		return $row;
	}
}
