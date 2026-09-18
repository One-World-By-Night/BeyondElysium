<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Parses OWBN's MET-Mechanics CSV compendium: Disciplines, Rituals, Archetypes,
 * Merits, Flaws, Backgrounds, Abilities, Morality Paths, Clans/Bloodlines, and
 * Revenant families in one flat table, including house-rule variants alongside
 * core-book material.
 *
 * Parsing only - this class returns plain rows and knows nothing about schema
 * blocks, labels, or cost signs. See
 * BeyondElysium\Database\Seeder::apply_met_csv_overrides() for how the parsed
 * rows are interpreted.
 *
 * @see BE_PROCESS/releases/workflow-0.10.md
 */
class MET_CSV_Parser {

	/**
	 * Header columns this parser keeps. The source file also carries a
	 * Portuguese translation column for most of these, including `Name-PT`
	 * (kept until 1.2.0 - see 1.2.0-design-workflow.md §8/B9; the file's real
	 * drafted translations are recovered from it once, into the
	 * translations table, by Schema::migrate_catalog_translations_to_table())
	 * and one blank-named column between lName and Cost; every `-PT` column
	 * is read so fgetcsv() stays aligned, then discarded.
	 *
	 * Description is never kept: it holds full sourcebook rules text and is
	 * already blanked in the source file. A block's own `description` field
	 * stays empty and editable for a chronicle admin to fill in.
	 */
	const KEPT_COLUMNS = [
		'Name', 'Type', 'Subtype', 'Group', 'Control', 'Rtg', 'lNum', 'lName',
		'Cost', 'Source', 'Prerequsites', 'House Rules', 'OrgRef',
	];

	/**
	 * Parses the MET-Mechanics CSV file into rows grouped by their Type column.
	 * Streams the file with fgetcsv() so free-text cells containing embedded
	 * newlines inside quoted values parse correctly. Skips blank lines and any
	 * row with no Type value.
	 *
	 * @param string   $path           Absolute path.
	 * @param string[] $extra_columns  Header columns to keep beyond KEPT_COLUMNS, for a caller
	 *                                 with its own narrow, one-off need (Schema::migrate_
	 *                                 catalog_translations_to_table()'s one-time `Name-PT`
	 *                                 recovery, §8/B9 - KEPT_COLUMNS itself stays scoped to what
	 *                                 Seeder needs, so this reuses the one tested streaming
	 *                                 implementation instead of a second parser drifting from it).
	 * @return array{rows: array<int,array<string,string>>, by_type: array<string,array<int,array<string,string>>>}
	 * @throws \RuntimeException When the file cannot be opened or its header is not what this parser expects.
	 */
	public static function parse_file( string $path, array $extra_columns = [] ): array {
		$handle = @fopen( $path, 'r' );

		if ( ! $handle ) {
			throw new \RuntimeException( 'Cannot open MET-Mechanics CSV: ' . $path );
		}

		$kept_columns = $extra_columns === [] ? self::KEPT_COLUMNS : array_merge( self::KEPT_COLUMNS, $extra_columns );

		try {
			// Pass the escape character explicitly to avoid a PHP 8.1+ deprecation warning.
			$header = fgetcsv( $handle, 0, ',', '"', '\\' );

			if ( ! is_array( $header ) || ! in_array( 'Name', $header, true ) || ! in_array( 'Type', $header, true ) ) {
				throw new \RuntimeException( 'MET-Mechanics CSV header is missing expected columns: ' . $path );
			}

			$rows    = [];
			$by_type = [];

			while ( ( $raw = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
				// A wholly blank line parses as [null]; skip it.
				if ( $raw === [ null ] ) {
					continue;
				}

				$row = [];
				foreach ( $header as $i => $column ) {
					if ( in_array( $column, $kept_columns, true ) ) {
						$row[ $column ] = trim( (string) ( $raw[ $i ] ?? '' ) );
					}
				}

				// Skip rows with no Type value.
				if ( ( $row['Type'] ?? '' ) === '' ) {
					continue;
				}

				$rows[]                       = $row;
				$by_type[ $row['Type'] ][]    = $row;
			}

			return [ 'rows' => $rows, 'by_type' => $by_type ];
		} finally {
			fclose( $handle );
		}
	}
}
