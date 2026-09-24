<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for one locale's translated text for one catalog term.
 */
class Translation {

	private const TABLE = 'translations';

	/**
	 * Statuses `resolve_approval_level()`-style code may need to compare against.
	 */
	public const STATUSES = [ 'draft', 'needs_review', 'approved', 'conflict' ];

	/**
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		return Manager::get_row( 'SELECT * FROM ' . Manager::table( self::TABLE ) . ' WHERE id = %d', $id );
	}

	/**
	 * The NULL-safe lookup every other method in this class routes through.
	 *
	 * @param int         $string_id
	 * @param string      $locale
	 * @param string|null $context
	 * @return object|null
	 */
	public static function find_one( int $string_id, string $locale, ?string $context = null ): ?object {
		$table = Manager::table( self::TABLE );

		if ( null === $context ) {
			return Manager::get_row(
				"SELECT * FROM {$table} WHERE string_id = %d AND locale = %s AND context IS NULL",
				$string_id,
				$locale
			);
		}

		return Manager::get_row(
			"SELECT * FROM {$table} WHERE string_id = %d AND locale = %s AND context = %s",
			$string_id,
			$locale,
			$context
		);
	}

	/**
	 * Creates a translation row and returns its id, or false when string_id or locale is missing.
	 *
	 * @param array $data string_id, locale, translation, status (default 'draft'), context (default null),
	 *                    updated_by (default the current user, or null outside a request).
	 * @return int|false
	 */
	public static function create( array $data ) {
		if ( empty( $data['string_id'] ) || empty( $data['locale'] ) ) {
			return false;
		}
		$status = $data['status'] ?? 'draft';
		$status = in_array( $status, self::STATUSES, true ) ? $status : 'draft';

		return Manager::insert( self::TABLE, [
			'string_id'   => (int) $data['string_id'],
			'locale'      => $data['locale'],
			'context'     => $data['context'] ?? null,
			'translation' => $data['translation'] ?? '',
			'status'      => $status,
			'note'        => $data['note'] ?? null,
			'updated_by'  => $data['updated_by'] ?? ( get_current_user_id() ?: null ),
			'updated_at'  => current_time( 'mysql' ),
		] );
	}

	/**
	 * @param int   $id
	 * @param array $data Any of translation, status, updated_by.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$update = [ 'updated_at' => current_time( 'mysql' ) ];

		if ( isset( $data['translation'] ) ) {
			$update['translation'] = $data['translation'];
		}
		if ( isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ) {
			$update['status'] = $data['status'];
		}
		if ( isset( $data['note'] ) ) {
			$update['note'] = $data['note'];
		}
		if ( array_key_exists( 'updated_by', $data ) ) {
			$update['updated_by'] = $data['updated_by'];
		}

		$result = Manager::update( self::TABLE, $update, [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$result = Manager::delete( self::TABLE, [ 'id' => $id ] );
		return $result !== false;
	}

	/**
	 * Create-or-replace on `(string_id, locale, context)` (`POST /translations`).
	 *
	 * @param int         $string_id
	 * @param string      $locale
	 * @param string      $translation
	 * @param string|null $context
	 * @param string      $status
	 * @param int|null    $updated_by
	 * @return int|false The row's id, whether it was just created or updated.
	 */
	public static function upsert(
		int $string_id,
		string $locale,
		string $translation,
		?string $context = null,
		string $status = 'draft',
		?int $updated_by = null
	) {
		$existing = self::find_one( $string_id, $locale, $context );

		if ( $existing ) {
			$ok = self::update( (int) $existing->id, [
				'translation' => $translation,
				'status'      => $status,
				'updated_by'  => $updated_by ?? ( get_current_user_id() ?: null ),
			] );
			return $ok ? (int) $existing->id : false;
		}

		return self::create( [
			'string_id'   => $string_id,
			'locale'      => $locale,
			'context'     => $context,
			'translation' => $translation,
			'status'      => $status,
			'updated_by'  => $updated_by,
		] );
	}

	/**
	 * The dictionary `Services\Catalog_Translator::map()` caches in a transient.
	 *
	 * @param string $locale
	 * @return array<string,string> source_key => translation.
	 */
	public static function map_for_locale( string $locale ): array {
		global $wpdb;
		$translations = Manager::table( self::TABLE );
		$strings      = Manager::table( 'translation_strings' );

		$sql = "SELECT s.source_key, t.translation
			FROM {$translations} t
			JOIN {$strings} s ON s.id = t.string_id
			WHERE t.locale = %s AND t.context IS NULL AND t.translation <> ''";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $locale ) ) ?: [];

		$map = [];
		foreach ( $rows as $row ) {
			$map[ $row->source_key ] = $row->translation;
		}
		return $map;
	}
}
