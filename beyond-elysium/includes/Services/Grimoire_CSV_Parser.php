<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Parses `data/grimoire-rotes.csv` - a one-time, offline extraction from a
 * Storytellers Vault compendium (`tools/grimoire/`, repo root, unshippable by
 * construction; see BE_PROCESS/mage-rotes-grimoire-design.md §8). Exactly five
 * columns, no description column at all - not blank, absent, since the
 * Grimoire's prose is the commercial product being extracted, never OWBN's own
 * writing (Decision 043's same rule, applied with more force).
 *
 * Parsing only - this class returns plain rows and knows nothing about schema
 * blocks or the merge against the existing 201 GEX-sourced rotes. See
 * BeyondElysium\Database\Seeder::merge_grimoire_rotes() for that.
 */
class Grimoire_CSV_Parser {

	const HEADER = [ 'name', 'note', 'source', 'group', 'subgroup' ];

	/**
	 * Parses the Grimoire rotes CSV into plain rows. Streams the file with
	 * fgetcsv() so a quoted cell's embedded newline (none expected here, but
	 * the same discipline as MET_CSV_Parser) parses correctly. Skips blank
	 * lines and any row with an empty `name`.
	 *
	 * @param string $path Absolute path.
	 * @return array<int,array{name:string,note:string,source:string,group:string,subgroup:string}>
	 * @throws \RuntimeException When the file cannot be opened or its header is not exactly the five expected columns.
	 */
	public static function parse_file( string $path ): array {
		$handle = @fopen( $path, 'r' );

		if ( ! $handle ) {
			throw new \RuntimeException( 'Cannot open Grimoire rotes CSV: ' . $path );
		}

		try {
			$header = fgetcsv( $handle, 0, ',', '"', '\\' );

			if ( $header !== self::HEADER ) {
				throw new \RuntimeException( 'Grimoire rotes CSV header is not the expected five columns: ' . $path );
			}

			$rows = [];

			while ( ( $raw = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
				// A wholly blank line parses as [null]; skip it.
				if ( $raw === [ null ] ) {
					continue;
				}

				$row = [
					'name'     => trim( (string) ( $raw[0] ?? '' ) ),
					'note'     => trim( (string) ( $raw[1] ?? '' ) ),
					'source'   => trim( (string) ( $raw[2] ?? '' ) ),
					'group'    => trim( (string) ( $raw[3] ?? '' ) ),
					'subgroup' => trim( (string) ( $raw[4] ?? '' ) ),
				];

				if ( $row['name'] === '' ) {
					continue;
				}

				$rows[] = $row;
			}

			return $rows;
		} finally {
			fclose( $handle );
		}
	}
}
