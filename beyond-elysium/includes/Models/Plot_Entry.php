<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for individual posts within a plot's thread.
 */
class Plot_Entry {

	/**
	 * Entry types.
	 */
	const ENTRY_TYPES = [ 'action', 'response', 'note', 'resolution', 'rumor_level' ];

	/**
	 * Visible to whoever can already see the parent plot.
	 */
	const AUDIENCE_PLOT = 'plot';
	/**
	 * Visible to Storytellers, Narrators, and the entry's own author.
	 */
	const AUDIENCE_STORYTELLERS = 'storytellers';
	/**
	 * A Storyteller post aimed at specific characters' players.
	 */
	const AUDIENCE_CHARACTERS = 'characters';

	/**
	 * Valid stored `audience` values.
	 */
	const AUDIENCE_VALUES = [ self::AUDIENCE_PLOT, self::AUDIENCE_STORYTELLERS, self::AUDIENCE_CHARACTERS ];

	const DEFAULT_AUDIENCE = self::AUDIENCE_PLOT;

	/**
	 * Look up a single plot entry by its primary key, with `audience_character_ids` decoded, or null when no entry with
	 * that ID exists.
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
	 * Return the entries belonging to one plot, ordered oldest first.
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
	 * Insert a new plot entry.
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
		if ( array_key_exists( 'held', $data ) ) {
			$insert['held'] = $data['held'] ? 1 : 0;
		}
		if ( array_key_exists( 'release_batch_id', $data ) ) {
			$insert['release_batch_id'] = ! empty( $data['release_batch_id'] ) ? (int) $data['release_batch_id'] : null;
		}
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
	 * Update a plot entry's content, event_date, audience, and audience_character_ids fields.
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
	 * Every entry held for one release batch.
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
	 * Delete a single plot entry by its primary key.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$result = Manager::delete( 'plot_entries', [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Delete every entry belonging to a plot.
	 *
	 * @param int $plot_id
	 * @return void
	 */
	public static function delete_for_plot( int $plot_id ): void {
		Manager::delete( 'plot_entries', [ 'plot_id' => $plot_id ] );
	}

	/**
	 * Encode a value for a JSON column, matching `Plot::encode_json_field()`'s contract: null stays null, an array or
	 * object is JSON-encoded, anything else is cast to string.
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
	 * Decode the audience_character_ids JSON column on a row object in place.
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
		$id = property_exists( $row, 'id' ) ? (int) $row->id : 0;

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

		if ( property_exists( $row, 'held' ) ) {
			$row->held = (bool) $row->held;
		}

		return $row;
	}
}
