<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\GV_Binary_Reader;
use BeyondElysium\Services\GVM_Parser;
use PHPUnit\Framework\TestCase;

/**
 * Menu parsing, both serializations, against the real Grapevine files in this repo.
 *
 * Fixture tests, not mocks. The XML and binary menu sets are the same data written two
 * ways, which makes a parity check a free and very strong correctness test.
 *
 * @see BE_PROCESS/workflow-0.8.md Steps 1 and 3
 */
class GvmParserTest extends TestCase {

	private function path( string $relative ): string {
		return BE_PLUGIN_ROOT . '/' . $relative;
	}

	// -------------------------------------------------------------------------
	// Binary primitives
	// -------------------------------------------------------------------------

	public function test_reads_signed_integers(): void {
		// -1, 0, 1, 32767, -32768 as little-endian 16-bit.
		$reader = new GV_Binary_Reader( pack( 'v*', 65535, 0, 1, 32767, 32768 ) );

		$this->assertSame( -1, $reader->int16() );
		$this->assertSame( 0, $reader->int16() );
		$this->assertSame( 1, $reader->int16() );
		$this->assertSame( 32767, $reader->int16() );
		$this->assertSame( -32768, $reader->int16() );
	}

	public function test_reads_signed_longs(): void {
		$reader = new GV_Binary_Reader( pack( 'V*', 4294967295, 0, 2147483647 ) );

		$this->assertSame( -1, $reader->int32() );
		$this->assertSame( 0, $reader->int32() );
		$this->assertSame( 2147483647, $reader->int32() );
	}

	public function test_reads_singles_as_four_bytes(): void {
		// VB6 Single (4 bytes) is not the same width as Double (8 bytes) - Experience,
		// XP history, and several per-stack stat fields use Single (workflow-0.8.md Step 2).
		$reader = new GV_Binary_Reader( pack( 'g*', 0.0, -1.5, 12.25 ) );

		$this->assertSame( 0.0, $reader->single() );
		$this->assertSame( -1.5, $reader->single() );
		$this->assertSame( 12.25, $reader->single() );
		$this->assertSame( 12, $reader->tell(), 'A VB6 Single is 4 bytes, not 8.' );
	}

	public function test_reads_booleans_as_two_bytes(): void {
		$reader = new GV_Binary_Reader( pack( 'v*', 0, 65535 ) );

		$this->assertFalse( $reader->bool() );
		$this->assertTrue( $reader->bool() );
		$this->assertSame( 4, $reader->tell(), 'A VB6 Boolean is 2 bytes, not 1.' );
	}

	public function test_reads_length_prefixed_strings_as_utf8(): void {
		// "Café" in ISO-8859-1: the e-acute is one byte, 0xE9.
		$latin  = "Caf\xE9";
		$reader = new GV_Binary_Reader( pack( 'v', strlen( $latin ) ) . $latin );

		$this->assertSame( 'Café', $reader->string() );
	}

	public function test_ole_date_epoch(): void {
		// 25569.0 is 1970-01-01 in OLE Automation serial days.
		$reader = new GV_Binary_Reader( pack( 'e', 25569.0 ) );
		$this->assertSame( '1970-01-01 00:00:00', $reader->date() );

		// Grapevine writes 0 for "no date".
		$zero = new GV_Binary_Reader( pack( 'e', 0.0 ) );
		$this->assertNull( $zero->date() );
	}

	public function test_truncation_names_the_offset(): void {
		$reader = new GV_Binary_Reader( pack( 'v', 100 ) . 'short' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/truncated at byte \d+/' );
		$reader->string();
	}

	public function test_rejects_a_non_grapevine_file(): void {
		$reader = new GV_Binary_Reader( pack( 'v', 4 ) . 'NOPE' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Not a Grapevine binary menu file/' );
		GVM_Parser::parse_binary( $reader );
	}

	public function test_a_negative_string_length_throws_rather_than_reading_garbage(): void {
		// int16's own sign handling makes 0xFFFF read back as -1.
		$reader = new GV_Binary_Reader( "\xFF\xFF" );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/negative string length/' );
		$reader->string();
	}

	public function test_truncation_error_names_the_reader_label(): void {
		$reader = new GV_Binary_Reader( '', 'my-file.gex' );

		try {
			$reader->int16();
			$this->fail( 'Expected a RuntimeException.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'my-file.gex', $e->getMessage() );
		}
	}

	public function test_a_second_single_is_not_consumed_as_part_of_a_double(): void {
		// Two Singles back to back must read as two distinct 4-byte values, not one
		// Double plus a truncation - the exact desynchronization this class's own doc
		// comment warns about.
		$reader = new GV_Binary_Reader( pack( 'g', 1.5 ) . pack( 'g', 2.5 ) );

		$this->assertSame( 1.5, $reader->single() );
		$this->assertSame( 2.5, $reader->single() );
	}

	public function test_ole_date_against_an_independently_verifiable_reference(): void {
		// 44562 is 2022-01-01 in the OLE Automation date system, checkable against any
		// online OLE-date converter - not derived from this class's own arithmetic.
		// 44562.5 carries the fractional day as noon.
		$this->assertSame( '2022-01-01 00:00:00', ( new GV_Binary_Reader( pack( 'e', 44562.0 ) ) )->date() );
		$this->assertSame( '2022-01-01 12:00:00', ( new GV_Binary_Reader( pack( 'e', 44562.5 ) ) )->date() );
	}

	public function test_peek_does_not_advance_position(): void {
		$reader = new GV_Binary_Reader( 'GVBE-rest-of-file' );

		$this->assertSame( 'GVBE', $reader->peek( 4 ) );
		$this->assertSame( 0, $reader->tell(), 'peek() must not consume bytes.' );
	}

	public function test_from_file_reads_a_real_file(): void {
		$reader = GV_Binary_Reader::from_file( $this->path( 'GV301Source/Code/New Game Items.gex' ) );
		// The header is itself a length-prefixed string (2-byte length, then "GVBE").
		$this->assertSame( 'GVBE', $reader->string() );
	}

	public function test_from_file_throws_on_a_missing_file(): void {
		$this->expectException( \RuntimeException::class );
		GV_Binary_Reader::from_file( '/no/such/file/here.gex' );
	}

	// -------------------------------------------------------------------------
	// Real files
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider binary_menu_files
	 */
	public function test_binary_menu_files_parse_completely( string $relative, int $menus ): void {
		$path = $this->path( $relative );

		if ( ! file_exists( $path ) ) {
			$this->markTestSkipped( "{$relative} is not present in this checkout." );
		}

		$parsed = GVM_Parser::parse_file( $path );

		$this->assertCount( $menus, $parsed['menus'] );
		$this->assertSame( 3.0, $parsed['version'] );
	}

	/**
	 * @return array<string,array{0:string,1:int}>
	 */
	public function binary_menu_files(): array {
		return [
			'standard'  => [ 'GV301Source/Code/Grapevine Menus.gvm', 756 ],
			'dark ages' => [ 'GV301Source/Code/Dark Ages Menus.gvm', 735 ],
			'community' => [ 'grapevine-samples/Grapevine Menus.gvm', 817 ],
		];
	}

	/**
	 * The binary and XML files are the same data in two serializations.
	 *
	 * This is the strongest correctness check available for the binary reader: a single
	 * mis-sized field desynchronizes the stream and every later value diverges. In
	 * particular it catches reading Category or Display as 2 bytes instead of 4 — they are
	 * VB6 enums, which are Longs.
	 */
	public function test_binary_and_xml_agree(): void {
		$binary_path = $this->path( 'GV301Source/Code/Grapevine Menus.gvm' );
		$xml_path    = BE_PLUGIN_PATH . '/data/Grapevine Menus XML.gvm';

		if ( ! file_exists( $binary_path ) ) {
			$this->markTestSkipped( 'Binary menu file is not present in this checkout.' );
		}

		$binary = GVM_Parser::parse_file( $binary_path )['menus'];
		$xml    = GVM_Parser::parse_file( $xml_path )['menus'];

		$this->assertSame( array_keys( $xml ), array_keys( $binary ), 'Menu names differ.' );

		$mismatches = [];
		foreach ( $binary as $name => $b ) {
			$x = $xml[ $name ];

			foreach ( [ 'category', 'display' ] as $field ) {
				if ( (int) $b[ $field ] !== (int) $x[ $field ] ) {
					$mismatches[] = "{$name}.{$field}: binary {$b[$field]} vs xml {$x[$field]}";
				}
			}
			foreach ( [ 'alphabetized', 'required' ] as $field ) {
				if ( (bool) $b[ $field ] !== (bool) $x[ $field ] ) {
					$mismatches[] = "{$name}.{$field} differs";
				}
			}
			foreach ( [ 'items', 'submenus', 'includes' ] as $field ) {
				if ( count( $b[ $field ] ) !== count( $x[ $field ] ) ) {
					$mismatches[] = sprintf(
						'%s.%s: binary %d vs xml %d',
						$name,
						$field,
						count( $b[ $field ] ),
						count( $x[ $field ] )
					);
				}
			}
		}

		$this->assertSame(
			[],
			array_slice( $mismatches, 0, 20 ),
			sprintf( "%d field mismatches between the two serializations.", count( $mismatches ) )
		);
	}

	/**
	 * The binary format carries flags the XML does not expose.
	 */
	public function test_binary_carries_negative_and_atomic_flags(): void {
		$path = $this->path( 'GV301Source/Code/Grapevine Menus.gvm' );

		if ( ! file_exists( $path ) ) {
			$this->markTestSkipped( 'Binary menu file is not present in this checkout.' );
		}

		$menus = GVM_Parser::parse_file( $path )['menus'];

		$this->assertArrayHasKey( 'negative', $menus['Flaws'] );
		$this->assertArrayHasKey( 'autonote', $menus['Flaws'] );
		$this->assertIsBool( $menus['Flaws']['negative'] );
	}

	/**
	 * Includes and submenus are stored as items in the binary format, discriminated by
	 * the Cost field: '+' for an include, ':' for a submenu.
	 */
	public function test_binary_separates_includes_and_submenus_from_items(): void {
		$path = $this->path( 'GV301Source/Code/Grapevine Menus.gvm' );

		if ( ! file_exists( $path ) ) {
			$this->markTestSkipped( 'Binary menu file is not present in this checkout.' );
		}

		$menus = GVM_Parser::parse_file( $path )['menus'];

		// Disciplines is a pure container: 48 submenus, no items of its own.
		$this->assertCount( 0, $menus['Disciplines']['items'] );
		$this->assertCount( 48, $menus['Disciplines']['submenus'] );

		// Abilities, Changeling includes the base Abilities menu.
		$this->assertContains( 'Abilities', $menus['Abilities, Changeling']['includes'] );
	}
}
