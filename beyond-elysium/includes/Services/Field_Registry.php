<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Grapevine's 231-key field registry (qkdata.gvd) and where each key lives in Beyond Elysium.
 */
class Field_Registry {

	/**
	 * Runtime copy of qkdata.gvd, read from the data/ directory.
	 */
	const SOURCE_FILE = __DIR__ . '/../../data/qkdata.gvd';

	/**
	 * Field-storage map: where each key lives in BE.
	 */
	const MAP_FILE = __DIR__ . '/field-map.php';

	/**
	 * Per-inventory storage descriptors and field maps for everything beyond `char`.
	 */
	const INVENTORIES_FILE = __DIR__ . '/query-inventories.php';

	/**
	 * The inventories the query builder actually offers.
	 */
	const QUERYABLE_INVENTORIES = [ 'char', 'item', 'loc', 'rote' ];

	/** @var array<string,array{key:string,title:string,type:string,inventories:string[]}>|null */
	private static ?array $rows = null;

	/** @var array<string,array>|null */
	private static ?array $map = null;

	/** @var array<string,array>|null */
	private static ?array $inventories = null;

	/**
	 * Returns every registry row, keyed by Grapevine field key.
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
	 * Returns a single registry row for the given field key.
	 *
	 * @param string $key
	 * @return array{key:string,title:string,type:string,inventories:string[]}|null
	 */
	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Returns the field-storage map: where each Grapevine key lives in Beyond Elysium.
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
	 * Returns the field-storage entry for one key, for the given inventory.
	 *
	 * @param string $key
	 * @param string $inventory One of Field_Registry::QUERYABLE_INVENTORIES.
	 * @return array<string,mixed>|null
	 */
	public static function map_for( string $key, string $inventory = 'char' ): ?array {
		if ( $inventory === 'char' ) {
			return self::map()[ $key ] ?? null;
		}
		return self::inventory( $inventory )['fields'][ $key ] ?? null;
	}

	/**
	 * Returns whether a key resolves to a real Beyond Elysium storage location for the given inventory.
	 *
	 * @param string $key
	 * @param string $inventory One of Field_Registry::QUERYABLE_INVENTORIES.
	 * @return bool
	 */
	public static function is_mapped( string $key, string $inventory = 'char' ): bool {
		$entry = self::map_for( $key, $inventory );
		return $entry !== null && $entry['source'] !== 'unmapped';
	}

	/**
	 * Returns the value type to validate and resolve a key against for a given inventory: the map entry's own `type`
	 * override when it declares one (only `item.powers` does today).
	 *
	 * @param string $key
	 * @param string $inventory One of Field_Registry::QUERYABLE_INVENTORIES.
	 * @return string
	 */
	public static function type_for( string $key, string $inventory = 'char' ): string {
		$override = self::map_for( $key, $inventory )['type'] ?? null;
		return $override ?? ( self::get( $key )['type'] ?? 'field' );
	}

	/**
	 * Returns one inventory's storage descriptor: which table it queries (`storage`), the object_type to filter on when
	 * it is world-object-backed, the columns a result list displays, and its field map (null for `char`, meaning
	 * "field-map.php").
	 *
	 * @param string $inventory
	 * @return array{storage:string,object_type?:string,result_columns:string[],fields:array|null}|null
	 */
	public static function inventory( string $inventory ): ?array {
		if ( self::$inventories === null ) {
			self::$inventories = require self::INVENTORIES_FILE;
		}
		return self::$inventories[ $inventory ] ?? null;
	}

	/**
	 * Parses the qkdata.gvd source file into registry rows.
	 *
	 * @return array<string,array{key:string,title:string,type:string,inventories:string[]}>
	 * @throws \RuntimeException If the source file is missing or unreadable.
	 */
	private static function parse(): array {
		if ( ! file_exists( self::SOURCE_FILE ) ) {
			throw new \RuntimeException( 'Field registry source not found: ' . self::SOURCE_FILE );
		}

		$contents = file_get_contents( self::SOURCE_FILE );
		if ( $contents === false ) {
			throw new \RuntimeException( 'Field registry source could not be read: ' . self::SOURCE_FILE );
		}

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

			[ $key, $title, $type, $inventories ] = array_map( 'strval', $fields );

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
	 * Normalizes a raw type column value into one of the registry's canonical types.
	 *
	 * @param string $type Raw type column value.
	 * @return string Normalized type: num, field, list, date, or bool.
	 */
	private static function normalize_type( string $type ): string {
		return $type === 'number' ? 'num' : $type;
	}

	/**
	 * Parses the inventory column into canonical inventory names.
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
