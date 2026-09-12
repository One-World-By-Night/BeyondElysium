<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\GEX_Parser;
use PHPUnit\Framework\TestCase;

/**
 * GX-1's shared field-order authority (`gv-exchange-shape.php`) - the data
 * table a future writer (GX-3/GX-5) trusts instead of re-deriving Grapevine's
 * container shape independently. This suite proves the table's own internal
 * consistency; `QueryWorldObjectsTest`-style round-trip proof that the table
 * matches the real readers is GX-2's job once they're re-pointed at it.
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-1, GX-10
 */
class GvExchangeShapeTest extends TestCase {

	/** @var array<string,array<string,mixed>> */
	private static array $shape;

	public static function setUpBeforeClass(): void {
		self::$shape = require BE_PLUGIN_ROOT . '/beyond-elysium/includes/Services/gv-exchange-shape.php';
	}

	public function test_all_twelve_race_type_map_races_are_present(): void {
		$this->assertSame(
			array_values( GEX_Parser::RACE_TYPE_MAP ),
			array_keys( self::$shape )
		);
	}

	public function test_race_codes_match_race_type_map_and_are_unique(): void {
		foreach ( GEX_Parser::RACE_TYPE_MAP as $code => $race ) {
			$this->assertSame( $code, self::$shape[ $race ]['race_code'], "race_code mismatch for {$race}" );
		}
		$codes = array_column( self::$shape, 'race_code' );
		$this->assertSame( $codes, array_unique( $codes ) );
	}

	public function test_every_race_has_the_five_required_top_level_keys(): void {
		foreach ( self::$shape as $race => $def ) {
			foreach ( [ 'race_code', 'xml_tag', 'scalars', 'experience', 'trait_lists', 'boons', 'tail' ] as $key ) {
				$this->assertArrayHasKey( $key, $def, "{$race} is missing '{$key}'" );
			}
		}
	}

	public function test_total_trait_list_rows_is_211(): void {
		$total = array_sum( array_map( static fn( $def ) => count( $def['trait_lists'] ), self::$shape ) );
		$this->assertSame( 211, $total );
	}

	public function test_no_duplicate_trait_list_name_within_a_race(): void {
		foreach ( self::$shape as $race => $def ) {
			$names = array_column( $def['trait_lists'], 'name' );
			$this->assertSame( $names, array_unique( $names ), "{$race} has a duplicate trait list name" );
		}
	}

	/**
	 * ListDisplayType (PublicTypes.bas:52-62): ldDefault=-1 .. ldSimpleDots=8.
	 */
	public function test_every_display_value_is_inside_list_display_types_real_range(): void {
		foreach ( self::$shape as $race => $def ) {
			foreach ( $def['trait_lists'] as $tl ) {
				$this->assertGreaterThanOrEqual( -1, $tl['display'], "{$race}/{$tl['name']}" );
				$this->assertLessThanOrEqual( 8, $tl['display'], "{$race}/{$tl['name']}" );
			}
		}
	}

	public function test_every_trait_list_row_has_the_five_required_flags(): void {
		foreach ( self::$shape as $race => $def ) {
			foreach ( $def['trait_lists'] as $tl ) {
				foreach ( [ 'name', 'abc', 'neg', 'atomic', 'display' ] as $key ) {
					$this->assertArrayHasKey( $key, $tl, "{$race} trait list missing '{$key}'" );
				}
			}
		}
	}

	public function test_every_xml_omit_if_names_a_real_sibling_scalar_key(): void {
		foreach ( self::$shape as $race => $def ) {
			$keys = array_column( $def['scalars'], 'key' );
			foreach ( $def['scalars'] as $scalar ) {
				if ( isset( $scalar['xml_omit_if'] ) ) {
					$this->assertContains(
						$scalar['xml_omit_if'],
						$keys,
						"{$race}/{$scalar['key']}'s xml_omit_if names a key that doesn't exist"
					);
				}
			}
		}
	}

	public function test_every_scalar_type_is_one_of_the_recognized_primitives(): void {
		$valid = [ 'string', 'int16', 'int32', 'bool', 'date', 'single' ];
		foreach ( self::$shape as $race => $def ) {
			foreach ( $def['scalars'] as $scalar ) {
				$this->assertContains( $scalar['type'], $valid, "{$race}/{$scalar['key']} has an unrecognized type" );
			}
		}
	}

	public function test_only_vampire_reads_boons(): void {
		foreach ( self::$shape as $race => $def ) {
			$this->assertSame( $race === 'vampire', $def['boons'], "{$race}'s boons flag should be " . ( $race === 'vampire' ? 'true' : 'false' ) );
		}
	}

	public function test_every_tail_row_has_a_key_and_an_xml_cdata_name(): void {
		foreach ( self::$shape as $race => $def ) {
			foreach ( $def['tail'] as $row ) {
				$this->assertArrayHasKey( 'key', $row, $race );
				$this->assertArrayHasKey( 'xml_cdata', $row, $race );
			}
		}
	}

	public function test_wraiths_ethnos_carries_a_three_value_xml_enum(): void {
		$ethnos = current( array_filter( self::$shape['wraith']['scalars'], static fn( $s ) => $s['key'] === 'ethnos' ) );
		$this->assertNotFalse( $ethnos );
		$this->assertSame( [ 0 => 'Wraith', 1 => 'Risen', 2 => 'Spectre' ], $ethnos['xml_enum'] );
	}

	public function test_no_shared_field_map_or_query_inventory_concepts_leak_into_this_table(): void {
		// GX-1's own rule: this table is exclusively facts about the file format - no
		// BeyondElysium block slugs, no field labels, no per-chronicle anything.
		$json = json_encode( self::$shape );
		foreach ( [ 'block_pattern', 'filter_source', 'stack', 'game_id', 'game_slug' ] as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $json );
		}
	}
}
