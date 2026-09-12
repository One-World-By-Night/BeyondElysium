<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\GEX_Parser;
use PHPUnit\Framework\TestCase;

/**
 * GX-2: proves `GEX_Parser`'s twelve `parse_character_*` methods, now driven
 * by `gv-exchange-shape.php`'s shared `trait_lists` loop instead of a bare
 * `$add()` sequence, still read every race's trait-list run at the correct
 * position and count. Builds a synthetic, version-3.0 binary buffer for each
 * race directly from the shape table itself (scalars at their real byte
 * widths, an empty experience block, zero-count trait lists, zero boons,
 * empty tail strings) and confirms `parse_character()` reads it back with
 * zero desync - the class most likely to break silently if a shared-loop
 * slice boundary or version gate were transcribed one row off.
 *
 * This is a synthetic round-trip against this table, not a claim about real
 * Grapevine bytes - `GexParserTest`'s own real fixtures (`Sabbat.gex` for
 * vampire, `New Game Items.gex`/`Fetishes and Talens.gex` for items) remain
 * the authority on real-file shape; this suite exists because 11 of the 12
 * character races have no real character-bearing fixture in this repo at
 * all (gex-export-transfer-design.md §2a).
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-2
 */
class GexParserShapeRoundTripTest extends TestCase {

	/** @var array<string,array<string,mixed>> */
	private static array $shape;

	public static function setUpBeforeClass(): void {
		self::$shape = require BE_PLUGIN_ROOT . '/beyond-elysium/includes/Services/gv-exchange-shape.php';
	}

	private function encode_string( string $s ): string {
		$latin1 = mb_convert_encoding( $s, 'ISO-8859-1', 'UTF-8' );
		return pack( 'v', strlen( $latin1 ) ) . $latin1;
	}

	private function encode_scalar( string $type ): string {
		switch ( $type ) {
			case 'string':
				return $this->encode_string( 'x' );
			case 'int16':
				return pack( 'v', 1 );
			case 'int32':
				return pack( 'V', 1 );
			case 'bool':
				return pack( 'v', 0 );
			case 'date':
				return pack( 'e', 0.0 );
			case 'single':
				return pack( 'g', 0.0 );
			default:
				$this->fail( "unrecognized scalar type '{$type}'" );
		}
	}

	private function encode_empty_trait_list( string $name ): string {
		return $this->encode_string( $name )
			. pack( 'v', 0 ) // alphabetized (bool)
			. pack( 'v', 0 ) // atomic (bool)
			. pack( 'v', 0 ) // negative (bool)
			. pack( 'V', 1 ) // display (int32)
			. pack( 'v', 0 ); // trait count
	}

	/**
	 * Builds a complete, version-3.0 binary character record for one race,
	 * driven entirely by that race's own shape entry: every scalar at its
	 * real byte width, every trait list present (3.0 satisfies every
	 * `min_version` gate) with zero traits, zero boons, and every tail
	 * field as an empty string.
	 */
	private function build_character_buffer( string $race ): string {
		$def = self::$shape[ $race ];
		$buf = pack( 'v', $def['race_code'] );

		foreach ( $def['scalars'] as $scalar ) {
			$buf .= $this->encode_scalar( $scalar['type'] );
		}

		if ( $def['experience'] ) {
			$buf .= pack( 'g', 0.0 ) . pack( 'g', 0.0 ) . pack( 'v', 0 );
		}

		if ( $race === 'wraith' ) {
			// The one race whose real byte order genuinely interleaves trait lists
			// with free-text fields - build in that true order (GEX_Parser.php's own
			// parse_character_wraith), matching read_trait_lists()'s 0/10, 10/5, 15/1
			// slice points.
			$lists = $def['trait_lists'];
			foreach ( array_slice( $lists, 0, 10 ) as $spec ) {
				$buf .= $this->encode_empty_trait_list( $spec['name'] );
			}
			foreach ( [ 'passions', 'fetters', 'life', 'death', 'haunt', 'regret' ] as $unused ) {
				$buf .= $this->encode_string( '' );
			}
			foreach ( array_slice( $lists, 10, 5 ) as $spec ) {
				$buf .= $this->encode_empty_trait_list( $spec['name'] );
			}
			$buf .= $this->encode_string( '' ); // dark_passions
			foreach ( array_slice( $lists, 15, 1 ) as $spec ) {
				$buf .= $this->encode_empty_trait_list( $spec['name'] );
			}
			$buf .= $this->encode_string( '' ); // notes
			return $buf;
		}

		foreach ( $def['trait_lists'] as $spec ) {
			$buf .= $this->encode_empty_trait_list( $spec['name'] );
		}

		if ( $def['boons'] ) {
			$buf .= pack( 'v', 0 );
		}

		for ( $i = count( $def['tail'] ); $i > 0; $i-- ) {
			$buf .= $this->encode_string( '' );
		}

		return $buf;
	}

	public static function race_provider(): array {
		return array_map( static fn( $race ) => [ $race ], array_values( GEX_Parser::RACE_TYPE_MAP ) );
	}

	/**
	 * @dataProvider race_provider
	 */
	public function test_every_race_reads_its_full_trait_list_run_with_zero_desync( string $race ): void {
		$reader    = new \BeyondElysium\Services\GV_Binary_Reader( $this->build_character_buffer( $race ), $race );
		$character = GEX_Parser::parse_character( $reader, 3.0 );

		$this->assertSame( $race, $character['race'] );

		$expected_names = array_column( self::$shape[ $race ]['trait_lists'], 'name' );
		$this->assertSame(
			$expected_names,
			array_keys( $character['trait_lists'] ),
			"{$race}: trait list names/order out of the reader don't match the shape table"
		);

		$this->assertTrue( $reader->eof(), "{$race}: buffer not fully consumed - a byte-count mismatch desynced the stream" );
	}
}
