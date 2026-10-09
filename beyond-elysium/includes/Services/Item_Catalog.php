<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the declared item catalog at `data/catalog/items/**` - one file per book, each a flat list of items read
 * straight from that book, never written to a table.
 */
class Item_Catalog {

	/**
	 * Default catalog root, relative to this file.
	 */
	const DEFAULT_ROOT = __DIR__ . '/../../data/catalog/items';

	/**
	 * Per-request cache, keyed by root path.
	 *
	 * @var array<string,array{items:array<int,array<string,mixed>>,books:array<int,array{slug:string,name:string}>,errors:array<int,string>}>
	 */
	private static array $cache = [];

	/**
	 * Every `.json` file directly under $root, sorted for deterministic order.
	 *
	 * @return string[]
	 */
	private static function scan_files( string $root ): array {
		if ( ! is_dir( $root ) ) {
			return [];
		}
		$files = [];
		foreach ( glob( $root . '/*.json' ) ?: [] as $path ) {
			$files[] = $path;
		}
		sort( $files );
		return $files;
	}

	/**
	 * Every valid book file's items, flattened into one list with `book`/`book_slug` carried onto each entry, plus
	 * a `book_ref` of `{book_slug}:{key}` for the create form's `duplicateFrom` to resolve back to.
	 *
	 * @return array{items:array<int,array<string,mixed>>,books:array<int,array{slug:string,name:string}>,errors:array<int,string>}
	 */
	public static function load( string $root = self::DEFAULT_ROOT ): array {
		if ( isset( self::$cache[ $root ] ) ) {
			return self::$cache[ $root ];
		}

		$result = [ 'items' => [], 'books' => [], 'errors' => [] ];

		foreach ( self::scan_files( $root ) as $path ) {
			$raw  = (string) file_get_contents( $path );
			$data = json_decode( $raw, true );
			$stem = pathinfo( $path, PATHINFO_FILENAME );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
				$result['errors'][] = sprintf( '%s: not valid JSON', $path );
				continue;
			}

			$errors = Catalog_Validator::validate_file( $data, $stem );
			if ( $errors !== [] ) {
				$result['errors'][] = sprintf( '%s: %s', $path, implode( '; ', $errors ) );
				continue;
			}

			$book_slug          = (string) ( $data['slug'] ?? $stem );
			$book_name          = (string) ( $data['name'] ?? $book_slug );
			$result['books'][] = [ 'slug' => $book_slug, 'name' => $book_name ];

			foreach ( (array) $data['items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$item['book']      = $book_name;
				$item['book_slug'] = $book_slug;
				$item['book_ref']  = $book_slug . ':' . (string) ( $item['key'] ?? '' );
				$result['items'][] = $item;
			}
		}

		if ( $result['errors'] !== [] ) {
			foreach ( $result['errors'] as $error ) {
				error_log( 'Beyond Elysium: declared item catalog file skipped - ' . $error );
			}
		}

		self::$cache[ $root ] = $result;
		return $result;
	}

	/**
	 * Clears the per-root cache.
	 */
	public static function reset_cache(): void {
		self::$cache = [];
	}

	/**
	 * Searches the loaded catalog by name (substring, case-insensitive), book slug, and `properties.item_type`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function search( string $query = '', string $book = '', string $item_type = '', string $root = self::DEFAULT_ROOT ): array {
		$items = self::load( $root )['items'];

		if ( $query !== '' ) {
			$items = array_values( array_filter( $items, static function ( array $item ) use ( $query ): bool {
				return stripos( (string) ( $item['name'] ?? '' ), $query ) !== false;
			} ) );
		}
		if ( $book !== '' ) {
			$items = array_values( array_filter( $items, static function ( array $item ) use ( $book ): bool {
				return ( $item['book_slug'] ?? '' ) === $book;
			} ) );
		}
		if ( $item_type !== '' ) {
			$items = array_values( array_filter( $items, static function ( array $item ) use ( $item_type ): bool {
				return strcasecmp( (string) ( $item['properties']['item_type'] ?? '' ), $item_type ) === 0;
			} ) );
		}

		return $items;
	}

	/**
	 * One entry by its `book_ref` (`{book_slug}:{key}`), or null if no book or no matching key exists.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function find( string $book_ref, string $root = self::DEFAULT_ROOT ): ?array {
		foreach ( self::load( $root )['items'] as $item ) {
			if ( ( $item['book_ref'] ?? '' ) === $book_ref ) {
				return $item;
			}
		}
		return null;
	}
}
