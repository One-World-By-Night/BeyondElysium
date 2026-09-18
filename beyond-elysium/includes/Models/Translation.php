<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for one locale's translated text for one catalog
 * term. `context` is NULL for the default translation and a block slug for
 * a homograph override (§4).
 *
 * MySQL does not treat two NULLs as equal in a UNIQUE key, so
 * `UNIQUE(string_id, locale, context)` alone does not stop two default
 * (context-NULL) rows for the same term+locale - confirmed live in
 * `tests/thread/TranslationTablesSchemaThreadTest.php`, pinned there rather
 * than fixed at the schema layer. `upsert()` below is the fix: it always
 * looks a row up with `find_one()`'s explicit NULL-safe branch before
 * deciding to insert or update, so it can never create a second
 * context-NULL row for a term+locale already holding one. Never call
 * `create()` directly for a term that might already have a translation -
 * that is exactly the bypass the pinned test documents.
 *
 * @see BE_PROCESS/releases/1.2.0-design-workflow.md §4
 */
class Translation {

	private const TABLE = 'translations';

	/** Statuses `resolve_approval_level()`-style code may need to compare against. */
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
	 * A plain `context = %s` silently matches nothing when `$context` is
	 * null (SQL's `NULL = NULL` is unknown, not true) - branches explicitly
	 * instead of relying on MySQL's `<=>` operator, matching this
	 * codebase's existing style of explicit `isset()`/ternary filter
	 * branches over compact-but-implicit SQL.
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
	 * @param array $data string_id, locale, translation, status (default
	 *                    'draft'), context (default null), updated_by
	 *                    (default the current user, or null outside a
	 *                    request - e.g. the B8 migration).
	 * @return int|false
	 */
	public static function create( array $data ) {
		if ( empty( $data['string_id'] ) || empty( $data['locale'] ) ) {
			return false;
		}
		// Resolved once, not inline: `in_array($data['status'] ?? 'draft', ...) ? $data['status']
		// : 'draft'` reads $data['status'] a second time in its true-branch without the
		// coalesce, which is an undefined-key access on the common, default-status path - a
		// bug caught live by this file's own thread test, not by inspection.
		$status = $data['status'] ?? 'draft';
		$status = in_array( $status, self::STATUSES, true ) ? $status : 'draft';

		return Manager::insert( self::TABLE, [
			'string_id'   => (int) $data['string_id'],
			'locale'      => $data['locale'],
			'context'     => $data['context'] ?? null,
			'translation' => $data['translation'] ?? '',
			'status'      => $status,
			// Only the §8 migration ever sets this, recording a conflict's losing value.
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
	 * Create-or-replace on `(string_id, locale, context)` (§6 `POST
	 * /translations`) - `find_one()`'s NULL-safe lookup first, then update
	 * that row or insert a new one. The one method the schema comment and
	 * class docblock both point at as the fix for the NULL-context gap;
	 * never bypass it with a direct `create()` call for a term that might
	 * already be translated.
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
	 * The dictionary `Services\Catalog_Translator::map()` (§5.1) caches in a
	 * transient - one query, joined to the string index for `source_key`,
	 * default (context-NULL) rows only, non-empty translations only (an
	 * emptied-out translation is "no translation," never a blank render -
	 * §4's status-vocabulary rule).
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
