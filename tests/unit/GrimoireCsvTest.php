<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Services\Grimoire_CSV_Parser;
use PHPUnit\Framework\TestCase;

/**
 * Mechanical guards on the real shipped `data/grimoire-rotes.csv` - the
 * "no new admin UI, a build-time verification gate instead" review
 * mage-rotes-grimoire-design.md §8.3 calls for. Unlike the Grapevine trio
 * (`tests/unit/DataFilesTest.php`), this file has no pristine original to
 * diff against - it is generated, so its guard is different and stronger:
 * header shape, row count, closed vocabularies, and the absence of any
 * sixth column or a leaked prose cell (§10 - the one thing that must never
 * ship, a commercial third party's own descriptive text).
 *
 * @see BE_PROCESS/mage-rotes-grimoire-design.md §8.2, §10
 */
class GrimoireCsvTest extends TestCase {

	private const NOTE_MAX   = 200;
	private const SOURCE_MAX = 300;

	private const SPHERE_WORDS = [
		'Correspondence', 'Entropy', 'Forces', 'Life', 'Matter',
		'Mind', 'Prime', 'Spirit', 'Time',
	];

	public function test_source_file_is_present_in_the_repo(): void {
		$this->assertFileExists( Seeder::GRIMOIRE_ROTES_PATH );
	}

	public function test_header_is_exactly_the_five_expected_columns(): void {
		$handle = fopen( Seeder::GRIMOIRE_ROTES_PATH, 'r' );
		$header = fgetcsv( $handle, 0, ',', '"', '\\' );
		fclose( $handle );

		$this->assertSame( [ 'name', 'note', 'source', 'group', 'subgroup' ], $header );
	}

	public function test_no_row_has_a_sixth_column(): void {
		$handle = fopen( Seeder::GRIMOIRE_ROTES_PATH, 'r' );
		fgetcsv( $handle, 0, ',', '"', '\\' ); // header

		$offenders = [];
		while ( ( $raw = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
			if ( $raw === [ null ] ) {
				continue;
			}
			if ( count( $raw ) > 5 ) {
				$offenders[] = $raw[0];
			}
		}
		fclose( $handle );

		$this->assertSame( [], $offenders, 'every row must be exactly five columns' );
	}

	public function test_has_a_substantial_real_row_count(): void {
		$rows = Grimoire_CSV_Parser::parse_file( Seeder::GRIMOIRE_ROTES_PATH );

		// Not a guessed exact figure - a floor confirming this is real bulk
		// extraction output, not an empty or near-empty placeholder file.
		$this->assertGreaterThan( 500, count( $rows ) );
	}

	public function test_every_name_is_unique(): void {
		$rows  = Grimoire_CSV_Parser::parse_file( Seeder::GRIMOIRE_ROTES_PATH );
		$names = array_column( $rows, 'name' );

		$this->assertCount( count( array_unique( $names ) ), $names );
	}

	public function test_no_note_cell_exceeds_the_length_guard(): void {
		$rows      = Grimoire_CSV_Parser::parse_file( Seeder::GRIMOIRE_ROTES_PATH );
		$offenders = array_filter( $rows, static fn( $r ) => strlen( $r['note'] ) > self::NOTE_MAX );

		$this->assertSame( [], array_column( $offenders, 'name' ) );
	}

	public function test_no_source_cell_exceeds_the_length_guard(): void {
		$rows      = Grimoire_CSV_Parser::parse_file( Seeder::GRIMOIRE_ROTES_PATH );
		$offenders = array_filter( $rows, static fn( $r ) => strlen( $r['source'] ) > self::SOURCE_MAX );

		$this->assertSame( [], array_column( $offenders, 'name' ) );
	}

	/**
	 * R3/§10: no description column exists at all - not blank, absent. If a
	 * future edit ever grows one, this is the assertion that catches it
	 * before the file ships.
	 */
	public function test_no_description_column_exists(): void {
		$handle = fopen( Seeder::GRIMOIRE_ROTES_PATH, 'r' );
		$header = fgetcsv( $handle, 0, ',', '"', '\\' );
		fclose( $handle );

		$this->assertNotContains( 'description', $header );
	}

	/**
	 * A leaked sentence of rules prose reads as "word. Word" - lowercase,
	 * period, space, uppercase - which a real sphere note or citation never
	 * produces (a citation's own internal ". " never occurs; multiple
	 * citations are joined with "; ", per Seeder::merge_grimoire_rotes()).
	 */
	public function test_no_note_or_source_cell_contains_a_leaked_sentence(): void {
		$rows      = Grimoire_CSV_Parser::parse_file( Seeder::GRIMOIRE_ROTES_PATH );
		$leak_re   = '/[a-z]\.\s+[A-Z]/';
		$offenders = [];

		foreach ( $rows as $row ) {
			if ( preg_match( $leak_re, $row['note'] ) || preg_match( $leak_re, $row['source'] ) ) {
				$offenders[] = $row['name'];
			}
		}

		$this->assertSame( [], $offenders );
	}

	/**
	 * `note` is a closed nine-sphere vocabulary plus digits, "or", "and",
	 * "optional", and punctuation - never free text. A word outside that set
	 * would mean either a real book-side sphere synonym this extraction
	 * never anchored ("Data" for Correspondence, a known Technocracy-flavor
	 * variant per §6.2) leaking through unresolved, or a parsing bug.
	 */
	public function test_every_note_cell_uses_only_the_closed_sphere_vocabulary(): void {
		$rows     = Grimoire_CSV_Parser::parse_file( Seeder::GRIMOIRE_ROTES_PATH );
		$allowed  = array_map( 'strtolower', self::SPHERE_WORDS );
		$allowed  = array_merge( $allowed, [ 'or', 'and', 'optional' ] );
		$offenders = [];

		foreach ( $rows as $row ) {
			preg_match_all( '/[A-Za-z]+/', $row['note'], $m );
			$stray = array_diff( array_map( 'strtolower', $m[0] ), $allowed );
			if ( $stray !== [] ) {
				$offenders[] = $row['name'] . ': ' . implode( ',', $stray );
			}
		}

		$this->assertSame( [], $offenders );
	}

	public function test_every_source_cell_carries_the_grimoire_page_provenance(): void {
		$rows      = Grimoire_CSV_Parser::parse_file( Seeder::GRIMOIRE_ROTES_PATH );
		$offenders = array_filter(
			$rows,
			static fn( $r ) => strpos( $r['source'], 'Enlightened Grimoire p. ' ) === false
		);

		$this->assertSame( [], array_column( $offenders, 'name' ) );
	}

	public function test_missing_file_parser_throws(): void {
		$this->expectException( \RuntimeException::class );
		Grimoire_CSV_Parser::parse_file( '/no/such/file.csv' );
	}
}
