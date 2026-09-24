<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\GEX_Parser;
use PHPUnit\Framework\TestCase;

/**
 * Proves `GEX_Parser`'s twelve `parse_character_*` methods, now driven by `gv-exchange-shape.php`'s shared
 * `trait_lists` loop.
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
			// The one race whose real byte order genuinely interleaves trait lists with free-text fields.
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
