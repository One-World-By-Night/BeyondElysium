<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Services\Field_Registry;
use PHPUnit\Framework\TestCase;

/**
 * The field registry (qkdata.gvd) and the field-storage map that sits on top of it.
 *
 * Both consumers - the template engine here in 0.3, and the query engine in 0.6 - depend
 * on this being exactly right: 231 real keys, the upstream `level` typo normalized, and
 * every *mapped* key pointing at a column or block that actually exists.
 *
 * @see BE_PROCESS/GV-SOURCEMAP.md "qkdata.gvd - the 231-key registry"
 * @see BE_PROCESS/workflow-0.3.md Step 0
 */
class FieldRegistryTest extends TestCase {

	/** @var array<string,array> Ground truth: every block Seeder will actually create. */
	private static array $blocks_by_slug = [];

	/** @var array<string,string[]> Identity field name => block slugs that define it. */
	private static array $identity_fields = [];

	/** @var array<string,string[]> Resource pool name => block slugs that define it. */
	private static array $pool_names = [];

	public static function setUpBeforeClass(): void {
		foreach ( Seeder::get_blocks_to_seed() as $block ) {
			self::$blocks_by_slug[ $block['slug'] ] = $block;

			if ( $block['section_type'] === 'identity_field' ) {
				foreach ( $block['definition']['fields'] as $field ) {
					self::$identity_fields[ $field['name'] ][] = $block['slug'];
				}
			}

			if ( $block['section_type'] === 'resource_pool' ) {
				foreach ( $block['definition']['pools'] as $pool ) {
					self::$pool_names[ $pool['name'] ][] = $block['slug'];
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Registry parsing
	// -------------------------------------------------------------------------

	public function test_parses_all_231_rows(): void {
		$this->assertCount( 231, Field_Registry::all() );
	}

	public function test_type_counts_match_the_source_map(): void {
		$counts = array_count_values( array_column( Field_Registry::all(), 'type' ) );

		// 90 'num' + the one normalized 'number' typo below.
		$this->assertSame( 91, $counts['num'] );
		$this->assertSame( 89, $counts['field'] );
		$this->assertSame( 47, $counts['list'] );
		$this->assertSame( 2, $counts['date'] );
		$this->assertSame( 2, $counts['bool'] );
		$this->assertArrayNotHasKey( 'number', $counts, 'the "number" typo must be normalized away' );
	}

	public function test_level_typo_normalizes_to_num(): void {
		$row = Field_Registry::get( 'level' );
		$this->assertNotNull( $row );
		$this->assertSame( 'num', $row['type'] );
	}

	public function test_inventory_membership_matches_the_source_map(): void {
		$this->assertCount( 204, Field_Registry::for_inventory( 'char' ) );
		$this->assertCount( 18, Field_Registry::for_inventory( 'loc' ) );
		$this->assertCount( 16, Field_Registry::for_inventory( 'item' ) );
		$this->assertCount( 13, Field_Registry::for_inventory( 'player' ) );
		$this->assertCount( 8, Field_Registry::for_inventory( 'rote' ) );

		// qkdata.gvd declares no plot, rumor, or action keys, even though the engine
		// parses for them - those entities expose keys through their own GetValue methods.
		$this->assertCount( 0, Field_Registry::for_inventory( 'plot' ) );
		$this->assertCount( 0, Field_Registry::for_inventory( 'rumor' ) );
		$this->assertCount( 0, Field_Registry::for_inventory( 'action' ) );
	}

	public function test_unknown_key_returns_null(): void {
		$this->assertNull( Field_Registry::get( 'notarealkey' ) );
	}

	// -------------------------------------------------------------------------
	// Field-storage map: completeness
	// -------------------------------------------------------------------------

	public function test_every_registry_key_has_a_map_entry(): void {
		$missing = array_diff( array_keys( Field_Registry::all() ), array_keys( Field_Registry::map() ) );
		$this->assertSame( [], $missing, 'registry keys with no field-map entry: ' . implode( ', ', $missing ) );
	}

	public function test_map_has_no_keys_outside_the_registry(): void {
		$orphans = array_diff( array_keys( Field_Registry::map() ), array_keys( Field_Registry::all() ) );
		$this->assertSame( [], $orphans, 'field-map keys not in the 231-key registry: ' . implode( ', ', $orphans ) );
	}

	// -------------------------------------------------------------------------
	// Field-storage map: every mapped key resolves to something real
	// -------------------------------------------------------------------------

	/** @dataProvider map_entries */
	public function test_mapped_key_resolves_to_a_real_column_or_block( string $key, array $entry ): void {
		switch ( $entry['source'] ) {
			case 'column':
				$this->assertContains(
					$entry['column'],
					self::known_character_columns(),
					"{$key}: column '{$entry['column']}' is not a real be_characters column"
				);
				break;

			case 'json':
				$this->assert_json_entry_resolves( $key, $entry );
				break;

			case 'derived':
			case 'unmapped':
				$this->assertNotEmpty( $entry['note'] ?? '', "{$key}: {$entry['source']} entry must carry an explanatory note" );
				break;

			case 'stack_relative_list':
				// Resolved per-character by Query_Engine (Decision 034) using the
				// character's own stack_slug - there is no single fixed block to check
				// against a static ground truth the way 'json' entries have.
				$this->assertNotEmpty( $entry['block_pattern'] ?? '', "{$key}: stack_relative_list entry must carry a block_pattern" );
				$this->assertStringContainsString( '{stack}', $entry['block_pattern'], "{$key}: block_pattern must be stack-relative" );
				$this->assertNotEmpty( $entry['filter_source'] ?? '', "{$key}: stack_relative_list entry must carry a filter_source" );
				break;

			default:
				$this->fail( "{$key}: unrecognized source '{$entry['source']}'" );
		}
	}

	/**
	 * @return array<string,array{0:string,1:array}>
	 */
	public function map_entries(): array {
		// Bootstrap and the block ground truth are loaded outside the constructor's usual
		// path because a data provider runs before setUpBeforeClass(). Field_Registry::map()
		// is pure file I/O and safe to call here.
		$out = [];
		foreach ( Field_Registry::map() as $key => $entry ) {
			$out[ $key ] = [ $key, $entry ];
		}
		return $out;
	}

	private function assert_json_entry_resolves( string $key, array $entry ): void {
		if ( isset( $entry['block'] ) ) {
			$this->assertArrayHasKey(
				$entry['block'],
				self::$blocks_by_slug,
				"{$key}: block '{$entry['block']}' does not exist"
			);
			$block = self::$blocks_by_slug[ $entry['block'] ];

			if ( isset( $entry['field'] ) ) {
				$names = array_column( $block['definition']['fields'] ?? [], 'name' );
				$this->assertContains(
					$entry['field'],
					$names,
					"{$key}: field '{$entry['field']}' not defined on block '{$entry['block']}'"
				);
				return;
			}

			if ( isset( $entry['pool'] ) ) {
				$names = array_column( $block['definition']['pools'] ?? [], 'name' );
				$this->assertContains(
					$entry['pool'],
					$names,
					"{$key}: pool '{$entry['pool']}' not defined on block '{$entry['block']}'"
				);
				$this->assertContains( $entry['part'] ?? null, [ 'permanent', 'temporary' ], "{$key}: pool entry needs a valid 'part'" );
				return;
			}

			// Whole-list reference: only trait_list and tiered_power blocks are rendered
			// that way (Step 4a/4b).
			$this->assertContains(
				$block['section_type'],
				[ 'trait_list', 'tiered_power' ],
				"{$key}: block '{$entry['block']}' referenced as a whole list but is a {$block['section_type']}"
			);
			return;
		}

		// No fixed block: the name must exist on at least one stack's block of the
		// matching kind, since resolution happens against the character's own stack.
		if ( isset( $entry['field'] ) ) {
			$this->assertArrayHasKey(
				$entry['field'],
				self::$identity_fields,
				"{$key}: field '{$entry['field']}' not defined on any identity block"
			);
			return;
		}

		if ( isset( $entry['pool'] ) ) {
			$this->assertArrayHasKey(
				$entry['pool'],
				self::$pool_names,
				"{$key}: pool '{$entry['pool']}' not defined on any resource block"
			);
			$this->assertContains( $entry['part'] ?? null, [ 'permanent', 'temporary' ], "{$key}: pool entry needs a valid 'part'" );
			return;
		}

		$this->fail( "{$key}: json entry has neither 'block', 'field', nor 'pool'" );
	}

	/**
	 * @return string[]
	 */
	private static function known_character_columns(): array {
		// Mirrors the be_characters CREATE TABLE in Database\Schema::install().
		return [
			'id', 'uuid', 'name', 'stack_slug', 'owner_type', 'owner_slug', 'wp_user_id',
			'player_name', 'status', 'is_npc', 'narrator', 'start_date', 'xp_earned',
			'xp_unspent', 'biography', 'notes', 'rp_notes', 'sheet_data', 'created_by',
			'created_at', 'updated_at',
		];
	}
}
