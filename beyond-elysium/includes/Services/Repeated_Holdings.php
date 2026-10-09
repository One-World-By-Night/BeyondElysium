<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Finds a sheet that holds the same path under the same tradition spelled two ways: "Dur An Ki" and "Dur-An-Ki", or
 * "Sadhana" and "Sadhanna". Rows for each level of one path, spelled alike, are not repeats.
 */
class Repeated_Holdings {

	/**
	 * Every repeated holding on one sheet.
	 *
	 * @param array<string,mixed>  $sheet_data
	 * @param string[]             $known   The catalog's tradition names.
	 * @param array<string,string> $aliases Misspelling => catalog name.
	 * @return array<int,array{block_slug:string,name:string,traditions:string[],levels:array<int,int|null>}>
	 */
	public static function in_sheet( array $sheet_data, array $known = [], array $aliases = [] ): array {
		$found = [];
		foreach ( $sheet_data as $block_slug => $rows ) {
			if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) < 2 ) {
				continue;
			}

			$groups = [];
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['name'], $row['tradition'] ) || trim( (string) $row['tradition'] ) === '' ) {
					continue;
				}
				$tradition = Blood_Magic_Refile::canonical_tradition( (string) $row['tradition'], $known, $aliases );
				$key       = Fuzzy_Matcher::normalize( (string) $row['name'] ) . "\0" . Fuzzy_Matcher::normalize( $tradition ) . "\0" . Fuzzy_Matcher::normalize( (string) ( $row['power_name'] ?? '' ) );
				$groups[ $key ][] = $row;
			}

			foreach ( $groups as $group ) {
				$spellings = array_values( array_unique( array_map( static fn( $row ): string => trim( (string) $row['tradition'] ), $group ) ) );
				if ( count( $spellings ) < 2 ) {
					continue;
				}
				$found[] = [
					'block_slug' => (string) $block_slug,
					'name'       => trim( (string) $group[0]['name'] ),
					'traditions' => $spellings,
					'levels'     => array_map( static fn( $row ): ?int => isset( $row['level'] ) && is_numeric( $row['level'] ) ? (int) $row['level'] : null, $group ),
				];
			}
		}
		return $found;
	}

	/**
	 * Writes a log line naming the characters on this site that hold a repeated holding.
	 */
	public static function report(): void {
		try {
			$found = self::in_site();
		} catch ( \Throwable $e ) {
			error_log( 'Beyond Elysium: the repeated-holdings check did not finish: ' . $e->getMessage() );
			return;
		}
		if ( $found === [] ) {
			return;
		}
		error_log( sprintf(
			'Beyond Elysium: %d characters hold a path under two spellings of one tradition (ids %s).',
			count( $found ),
			implode( ', ', array_slice( array_keys( $found ), 0, 25 ) )
		) );
	}

	/**
	 * Every character on this site that holds a repeated holding, with how many.
	 *
	 * @return array<int,int> Character id => number of repeated holdings.
	 */
	public static function in_site(): array {
		$found   = [];
		$last_id = 0;
		do {
			$rows = Manager::get_results(
				'SELECT id, owner_slug, sheet_data FROM ' . Manager::table( 'characters' ) . ' WHERE id > %d ORDER BY id ASC LIMIT 200',
				$last_id
			);
			foreach ( $rows as $row ) {
				$last_id    = (int) $row->id;
				$sheet      = json_decode( (string) $row->sheet_data, true );
				$traditions = Blood_Magic_Refile::catalog_traditions( (string) $row->owner_slug );
				$repeats    = is_array( $sheet ) ? self::in_sheet( $sheet, $traditions['known'], $traditions['aliases'] ) : [];
				if ( $repeats !== [] ) {
					$found[ $last_id ] = count( $repeats );
				}
			}
		} while ( count( $rows ) === 200 );
		return $found;
	}
}
