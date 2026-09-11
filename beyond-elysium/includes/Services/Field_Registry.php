<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Grapevine's 231-key field registry (qkdata.gvd) and where each key lives
 * in Beyond Elysium.
 *
 * Template tokens such as [Name], [Clan], [XPUnspent], and [Disciplines]
 * are query keys, resolved the same way the query engine resolves them:
 * against this registry. Each row carries the key's title, value type, and
 * which inventories (character, player, item, and so on) it applies to.
 *
 * @see BE_PROCESS/GV-SOURCEMAP.md "qkdata.gvd - the 231-key registry"
 * @see BE_PROCESS/workflow-0.3.md Step 0
 * @see BE_PROCESS/workflow-0.6.md Step 1
 */
class Field_Registry {

	/**
	 * Runtime copy of qkdata.gvd, read from the data/ directory rather
	 * than the excluded GV301Source/ archive.
	 */
	const SOURCE_FILE = __DIR__ . '/../../data/qkdata.gvd';

	/** Field-storage map: where each key lives in BE. */
	const MAP_FILE = __DIR__ . '/field-map.php';

	/** @var array<string,array{key:string,title:string,type:string,inventories:string[]}>|null */
	private static ?array $rows = null;

	/** @var array<string,array>|null */
	private static ?array $map = null;

	/**
	 * Returns every registry row, keyed by Grapevine field key. Parses the
	 * source file on first call and caches the result for subsequent
	 * calls within the same request.
	 *
	 * @return array<string,array{key:string,title:string,type:string,inventories:string[]}>
	 */
	public static function all(): array {
		if ( self::$rows === null ) {
			self::$rows = self::parse();
		}
		return self::$rows;
	}

	/**
	 * Returns the subset of registry rows that apply to a given inventory.
	 * Filters the full registry down to rows whose `inventories` list
	 * contains the requested inventory name.
	 *
	 * @param string $inventory One of: char, player, item, loc, rote, plot, rumor, action.
	 * @return array<string,array>
	 */
	public static function for_inventory( string $inventory ): array {
		return array_filter(
			self::all(),
			static function ( array $row ) use ( $inventory ): bool {
				return in_array( $inventory, $row['inventories'], true );
			}
		);
	}

	/**
	 * Returns a single registry row for the given field key. Looks the
	 * key up in the full registry, returning its title, type, and
	 * inventory list, or null when no row exists for the key.
	 *
	 * @param string $key
	 * @return array{key:string,title:string,type:string,inventories:string[]}|null
	 */
	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Returns the field-storage map: where each Grapevine key lives in
	 * Beyond Elysium. Loads and caches the map from its source file on
	 * first call.
	 *
	 * Each entry has one of four source kinds: `column` (a be_characters
	 * column), `json` (inside sheet_data), `derived` (computed, not
	 * stored), or `unmapped` (no Beyond Elysium equivalent for the key).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function map(): array {
		if ( self::$map === null ) {
			self::$map = require self::MAP_FILE;
		}
		return self::$map;
	}

	/**
	 * Returns the field-storage entry for one key. Looks the key up in
	 * the field-storage map and returns null when the key is not present
	 * in the registry.
	 *
	 * @param string $key
	 * @return array<string,mixed>|null
	 */
	public static function map_for( string $key ): ?array {
		return self::map()[ $key ] ?? null;
	}

	/**
	 * Returns whether a key resolves to a real Beyond Elysium storage
	 * location. True when the key has a field-storage entry and that
	 * entry's source is not `unmapped`.
	 *
	 * @param string $key
	 * @return bool
	 */
	public static function is_mapped( string $key ): bool {
		$entry = self::map_for( $key );
		return $entry !== null && $entry['source'] !== 'unmapped';
	}

	/**
	 * Parses the qkdata.gvd source file into registry rows. Reads the
	 * file as CSV with CRLF line endings and four quoted columns per row,
	 * normalizing the type and inventory columns along the way.
	 *
	 * @return array<string,array{key:string,title:string,type:string,inventories:string[]}>
	 * @throws \RuntimeException If the source file is missing.
	 */
	private static function parse(): array {
		if ( ! file_exists( self::SOURCE_FILE ) ) {
			throw new \RuntimeException( 'Field registry source not found: ' . self::SOURCE_FILE );
		}

		$contents = file_get_contents( self::SOURCE_FILE );

		// Split on literal CRLF rather than relying on fgetcsv() line-ending autodetection.
		$lines = explode( "\r\n", rtrim( $contents, "\r\n" ) );

		$rows = [];

		foreach ( $lines as $line ) {
			if ( $line === '' ) {
				continue;
			}

			$fields = str_getcsv( $line, ',', '"' );
			if ( count( $fields ) < 4 ) {
				continue;
			}

			[ $key, $title, $type, $inventories ] = $fields;

			$rows[ $key ] = [
				'key'         => $key,
				'title'       => $title,
				'type'        => self::normalize_type( $type ),
				'inventories' => self::parse_inventories( $inventories ),
			];
		}

		return $rows;
	}

	/**
	 * Normalizes a raw type column value into one of the registry's
	 * canonical types. Maps the "number" value to "num"; every other
	 * value passes through unchanged.
	 *
	 * @param string $type Raw type column value.
	 * @return string Normalized type: num, field, list, date, or bool.
	 */
	private static function normalize_type( string $type ): string {
		return $type === 'number' ? 'num' : $type;
	}

	/**
	 * Parses the inventory column into canonical inventory names. Tests
	 * the raw value for each inventory's substring rather than splitting
	 * on commas, so "play" matches "player" and "act" matches "action".
	 *
	 * @param string $raw Raw inventories column, e.g. "char,player,item".
	 * @return string[] Canonical inventory names present, in a fixed order.
	 */
	private static function parse_inventories( string $raw ): array {
		$substrings = [
			'char'   => 'char',
			'player' => 'play',
			'item'   => 'item',
			'loc'    => 'loc',
			'rote'   => 'rote',
			'plot'   => 'plot',
			'rumor'  => 'rumor',
			'action' => 'act',
		];

		$found = [];
		foreach ( $substrings as $canonical => $needle ) {
			if ( strpos( $raw, $needle ) !== false ) {
				$found[] = $canonical;
			}
		}

		return $found;
	}
}
