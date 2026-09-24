<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Services\Name_Key;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for the locale-independent catalog term index.
 */
class Translation_String {

	private const TABLE = 'translation_strings';

	/**
	 * A `first_seen`/`last_seen`-ready timestamp with microsecond precision.
	 *
	 * @return string
	 */
	public static function now_micro(): string {
		return ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s.u' );
	}

	/**
	 * Column => value pairs for one row, or null if none matches.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		$row = Manager::get_row( 'SELECT * FROM ' . Manager::table( self::TABLE ) . ' WHERE id = %d', $id );
		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * @param string $source_key Already-normalized via Name_Key::for().
	 * @return object|null
	 */
	public static function find_by_source_key( string $source_key ): ?object {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( self::TABLE ) . ' WHERE source_key = %s',
			$source_key
		);
		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Convenience for REST callers that supply the raw English string.
	 *
	 * @param string $source_text
	 * @return object|null
	 */
	public static function find_by_source_text( string $source_text ): ?object {
		return self::find_by_source_key( Name_Key::for( $source_text ) );
	}

	/**
	 * Creates a translation string row and returns its id.
	 *
	 * @param array $data source_key (optional - computed from source_text if absent),
	 *                     source_text (required), used_in (optional array), first_seen
	 *                     (optional), last_seen (optional - pass null explicitly
	 *                     step-4 "CSV-only, never confirmed in the catalog" case; array_key_exists
	 *                     is used rather than `??` specifically so an explicit null is honored
	 *                     instead of being silently replaced with "now").
	 * @return int|false
	 */
	public static function create( array $data ) {
		if ( empty( $data['source_text'] ) ) {
			return false;
		}
		$now = self::now_micro();
		return Manager::insert( self::TABLE, [
			'source_key'  => $data['source_key'] ?? Name_Key::for( $data['source_text'] ),
			'source_text' => $data['source_text'],
			'used_in'     => wp_json_encode( $data['used_in'] ?? [] ),
			'first_seen'  => $data['first_seen'] ?? $now,
			'last_seen'   => array_key_exists( 'last_seen', $data ) ? $data['last_seen'] : $now,
		] );
	}

	/**
	 * @param int   $id
	 * @param array $data Any of source_text, used_in, last_seen.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$update = [];
		if ( isset( $data['source_text'] ) ) {
			$update['source_text'] = $data['source_text'];
		}
		if ( array_key_exists( 'used_in', $data ) ) {
			$update['used_in'] = wp_json_encode( $data['used_in'] ?? [] );
		}
		if ( isset( $data['last_seen'] ) ) {
			$update['last_seen'] = $data['last_seen'];
		}
		if ( ! $update ) {
			return false;
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
	 * The write side of a catalog rescan.
	 *
	 * @param string $source_text
	 * @param array  $used_in
	 * @return int|false
	 */
	public static function upsert_from_scan( string $source_text, array $used_in ) {
		$key      = Name_Key::for( $source_text );
		$existing = self::find_by_source_key( $key );

		if ( $existing ) {
			$ok = self::update( (int) $existing->id, [
				'used_in'   => $used_in,
				'last_seen' => self::now_micro(),
			] );
			return $ok ? (int) $existing->id : false;
		}

		return self::create( [
			'source_key'  => $key,
			'source_text' => $source_text,
			'used_in'     => $used_in,
		] );
	}

	/**
	 * The review list (`GET /translations`): every string, LEFT JOINed to its translation for one locale.
	 *
	 * @param string $locale
	 * @param array  $filters status, block (searches `used_in`), search
	 *                        (matches source_text), has_translation (bool).
	 * @param int    $per_page
	 * @param int    $offset
	 * @return object[] Each carries source_key/source_text/used_in plus
	 *                   translation_id/translation/status for $locale (all
	 *                   null when untranslated).
	 */
	public static function list_for_review( string $locale, array $filters = [], int $per_page = 100, int $offset = 0 ): array {
		global $wpdb;
		[ $where, $values ] = self::build_review_where( $locale, $filters );

		$strings      = Manager::table( self::TABLE );
		$translations = Manager::table( 'translations' );

		$sql = "SELECT s.*, t.id AS translation_id, t.translation, t.status, t.context
			FROM {$strings} s
			LEFT JOIN {$translations} t ON t.string_id = s.id AND t.locale = %s AND t.context IS NULL
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY s.source_text ASC
			LIMIT %d OFFSET %d';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( [ $locale ], $values, [ $per_page, $offset ] ) ) ) ?: [];
		return array_map( [ self::class, 'decode_row' ], $rows );
	}

	/**
	 * Total matching `list_for_review()`'s filters, unpaginated.
	 *
	 * @param string $locale
	 * @param array  $filters Same shape as list_for_review().
	 * @return int
	 */
	public static function count_for_review( string $locale, array $filters = [] ): int {
		global $wpdb;
		[ $where, $values ] = self::build_review_where( $locale, $filters );

		$strings      = Manager::table( self::TABLE );
		$translations = Manager::table( 'translations' );

		$sql = "SELECT COUNT(*) FROM {$strings} s
			LEFT JOIN {$translations} t ON t.string_id = s.id AND t.locale = %s AND t.context IS NULL
			WHERE " . implode( ' AND ', $where );

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( [ $locale ], $values ) ) );
	}

	/**
	 * Shared WHERE-clause builder behind list_for_review()/count_for_review().
	 *
	 * @param string $locale
	 * @param array  $filters
	 * @return array{0: string[], 1: array<int,mixed>} `[$where_clauses, $bind_values]`.
	 */
	private static function build_review_where( string $locale, array $filters ): array {
		$where  = [ '1=1' ];
		$values = [];

		if ( ! empty( $filters['status'] ) ) {
			if ( 'untranslated' === $filters['status'] ) {
				$where[] = 't.id IS NULL';
			} else {
				$where[]  = 't.status = %s';
				$values[] = $filters['status'];
			}
		}

		if ( ! empty( $filters['block'] ) ) {
			// used_in is a JSON array of {block, section_type, role} objects.
			$where[]  = 'JSON_SEARCH(s.used_in, "one", %s) IS NOT NULL';
			$values[] = $filters['block'];
		}

		if ( ! empty( $filters['search'] ) ) {
			global $wpdb;
			$where[]  = 's.source_text LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
		}

		if ( isset( $filters['has_translation'] ) && $filters['has_translation'] !== '' ) {
			$where[] = $filters['has_translation'] ? 't.id IS NOT NULL' : 't.id IS NULL';
		}

		return [ $where, $values ];
	}

	/**
	 * @param object $row
	 * @return object
	 */
	private static function decode_row( object $row ): object {
		if ( isset( $row->used_in ) && is_string( $row->used_in ) ) {
			$row->used_in = json_decode( $row->used_in ) ?: [];
		}
		return $row;
	}
}
