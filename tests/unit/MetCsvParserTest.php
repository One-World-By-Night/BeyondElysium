<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\MET_CSV_Parser;
use PHPUnit\Framework\TestCase;

/**
 * MET-Mechanics CSV parsing, against both the real shipped file and small hand-built
 * fixtures for the edge cases the real file doesn't reliably exercise.
 *
 * @see BE_PROCESS/workflow-0.10.md
 */
class MetCsvParserTest extends TestCase {

	private function real_csv(): string {
		return BE_PLUGIN_PATH . '/data/met-mechanics.csv';
	}

	/**
	 * Measured against the real shipped file. A change here means the source file changed -
	 * re-measure, don't just widen the assertion.
	 */
	public function test_real_file_type_counts(): void {
		$parsed = MET_CSV_Parser::parse_file( $this->real_csv() );

		$counts = array_map( 'count', $parsed['by_type'] );

		$this->assertSame( 1562, $counts['Discipline'] ?? 0 );
		$this->assertSame( 1379, $counts['Ritual'] ?? 0 );
		$this->assertSame( 1153, $counts['Archetype'] ?? 0 );
		$this->assertSame( 420, $counts['Merit'] ?? 0 );
		$this->assertSame( 392, $counts['Flaw'] ?? 0 );
		$this->assertSame( 100, $counts['Paths'] ?? 0 );
		$this->assertSame( 81, $counts['Background'] ?? 0 );
		$this->assertSame( 75, $counts['Clan / Bloodline'] ?? 0 );
		$this->assertSame( 45, $counts['Ability'] ?? 0 );
		$this->assertSame( 20, $counts['Revenant'] ?? 0 );

		$this->assertSame( array_sum( $counts ), count( $parsed['rows'] ) );
	}

	/**
	 * PT (Portuguese) columns and the source file's one literally-blank-named column exist
	 * in the real header but are out of scope until a future i18n phase - confirm they never
	 * reach a parsed row. Description is excluded for a different reason (see KEPT_COLUMNS'
	 * own doc comment) - confirmed separately below.
	 */
	public function test_translation_and_blank_columns_are_not_carried(): void {
		$parsed = MET_CSV_Parser::parse_file( $this->real_csv() );
		$row    = $parsed['rows'][0];

		foreach ( array_keys( $row ) as $column ) {
			$this->assertStringEndsNotWith( '-PT', $column );
		}
		$this->assertArrayNotHasKey( '', $row );
	}

	/**
	 * Description is full paragraph-length rules text transcribed from published sourcebooks
	 * (see MET_CSV_Parser::KEPT_COLUMNS' own doc comment) - never shipped in the real CSV
	 * (blanked at the source) and never surfaced by this parser even if a future re-export
	 * of the source file forgot to blank it. A regression guard against ever silently
	 * reintroducing it.
	 */
	public function test_description_is_never_surfaced(): void {
		$parsed = MET_CSV_Parser::parse_file( $this->real_csv() );

		foreach ( $parsed['rows'] as $row ) {
			$this->assertArrayNotHasKey( 'Description', $row );
			$this->assertArrayNotHasKey( 'Description-PT', $row );
		}
	}

	/**
	 * Real, load-bearing case: several free-text cells in the shipped file (House Rules
	 * among them) contain an embedded newline inside a quoted value. A naive
	 * explode("\r\n", ...)-then-parse approach (the shape Field_Registry::parse() uses for a
	 * different, simpler file) would tear one logical row into two. fgetcsv() must not.
	 */
	public function test_embedded_newline_inside_a_quoted_field_stays_one_row(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'be-met-csv-test' );
		file_put_contents(
			$tmp,
			"Name,Type,Subtype,Group,Control,Rtg,lNum,lName,,Cost,Source,Prerequsites,Description,House Rules,OrgRef\r\n"
			. "\"Grasp the Ghostly\",Ritual,Necromancy,,,-,5,Advanced,,6,Laws of the Night Revised,,,\"Line one.\r\nLine two.\",\r\n"
			. "\"Second Row\",Ritual,Necromancy,,,-,1,Basic,,2,Laws of the Night Revised,,,,\r\n"
		);

		try {
			$parsed = MET_CSV_Parser::parse_file( $tmp );

			$this->assertCount( 2, $parsed['rows'], 'the embedded newline must not split one row into two' );
			$this->assertSame( 'Grasp the Ghostly', $parsed['rows'][0]['Name'] );
			$this->assertSame( "Line one.\r\nLine two.", $parsed['rows'][0]['House Rules'] );
			$this->assertSame( 'Second Row', $parsed['rows'][1]['Name'] );
		} finally {
			unlink( $tmp );
		}
	}

	public function test_a_type_less_row_is_dropped(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'be-met-csv-test' );
		file_put_contents(
			$tmp,
			"Name,Type,Subtype,Group,Control,Rtg,lNum,lName,,Cost,Source,Prerequsites,Description,House Rules,OrgRef\n"
			. ",,,,,,,,,,,,,,\n"
			. "Humanity,Paths,Vampire,,,,,,,,Laws of the Night Revised,,,,\n"
		);

		try {
			$parsed = MET_CSV_Parser::parse_file( $tmp );

			$this->assertCount( 1, $parsed['rows'] );
			$this->assertSame( 'Humanity', $parsed['rows'][0]['Name'] );
		} finally {
			unlink( $tmp );
		}
	}

	public function test_missing_file_throws(): void {
		$this->expectException( \RuntimeException::class );

		MET_CSV_Parser::parse_file( '/no/such/file.csv' );
	}

	public function test_a_header_missing_expected_columns_throws(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'be-met-csv-test' );
		file_put_contents( $tmp, "Foo,Bar\nbaz,qux\n" );

		try {
			$this->expectException( \RuntimeException::class );
			MET_CSV_Parser::parse_file( $tmp );
		} finally {
			unlink( $tmp );
		}
	}
}
