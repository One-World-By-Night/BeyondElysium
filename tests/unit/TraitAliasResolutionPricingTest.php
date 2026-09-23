<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Services\Cost_Engine;
use PHPUnit\Framework\TestCase;

/**
 * 1.3.2's alias-routing item (`1.3.1-design-workflow.md` §11.7 item 1), proven against the
 * REAL declared catalog - `Seeder::get_blocks_to_seed()`, the exact overlay
 * `Point_Audit`/`Cost_Engine` read from in production, not a hand-built fixture - and a
 * fixture built from a real character's held rows: Hitchens, `be_dev` character id 1093,
 * stack `vampire`, chronicle `kony`, `sheet_data` captured 2026-09-22.
 *
 * `Point_Audit` performs zero arithmetic of its own (its own class docblock) - every number
 * it prints comes from exactly the `Cost_Engine::price_held_*()` calls this file exercises,
 * with the identical `$block_slug`/`$blocks` arguments `Point_Audit::lines_for_block()` now
 * passes them. Proving these functions here is proving `Point_Audit`'s own catalog lookup;
 * a duplicate thread-layer test would call the same code through more machinery for the
 * same assertions, at the cost of the shared WP test database this session was told to
 * treat as contended.
 *
 * Pure: no database, no WordPress - `Seeder::get_blocks_to_seed()` builds the whole real
 * catalog (GVM/CSV path plus the declared-file overlay) from files on disk alone.
 */
class TraitAliasResolutionPricingTest extends TestCase {

	/**
	 * Real seeded blocks, keyed by slug: {slug, section_type, definition}, matching
	 * `Schema_Block`'s own decoded shape. Scoped to the vampire stack's own two real
	 * `tiered_power` blocks - `vampire-identity` for the `clan_disciplines`-adjacent
	 * unit tests below - not the whole ~170-block catalog: a real character's own `$blocks`
	 * map (`Point_Audit::for_character()`, `Cost_Engine::in_type_check()`) is always built
	 * from that ONE character's own stack sections, which reference the base blocks only -
	 * no chronicle is wired to select an edition variant yet
	 * (`1.3.2-design-workflow.md`, "What this does NOT decide"). Loading every edition
	 * variant here too would hand `find_moved_power()` several genuinely-identical
	 * `moved_from` copies of `Lure of Flames` (the base plus its `2nded-`/`darkages-`
	 * siblings, each seeded as its own complete base-plus-additions row) and trip its
	 * ambiguity guard on a collision no real character's `$blocks` map can ever produce.
	 */
	private static array $blocks = [];

	public static function setUpBeforeClass(): void {
		$slugs = [ 'vampire-disciplines', 'vampire-blood-magic', 'vampire-identity' ];
		foreach ( Seeder::get_blocks_to_seed() as $block ) {
			if ( ! in_array( $block['slug'], $slugs, true ) ) {
				continue;
			}
			self::$blocks[ $block['slug'] ] = (object) [
				'slug'         => $block['slug'],
				'section_type' => $block['section_type'],
				'definition'   => json_decode( (string) json_encode( $block['definition'] ) ),
			];
		}
	}

	private static function def( string $slug ): object {
		return self::$blocks[ $slug ]->definition;
	}

	/**
	 * Hitchens' real `vampire-disciplines` holdings (`be_dev` character 1093, captured
	 * 2026-09-22 via a direct read of `wp_be_characters.sheet_data`), unmodified, PLUS one
	 * row this test adds for the proof. His real file holds no family the catalog records
	 * as moved or renamed - every family below other than the last is copied verbatim, tier
	 * and level exactly as stored - so the last row is "a fixture built from his real held
	 * rows" per the brief's own allowance, not a literal line of his file: a Thaumaturgy
	 * path bought under its pre-1.3.1 Latin Discipline name, exactly the worked trace
	 * `1.3.1-design-workflow.md` §11.5 names ("a character holding Creo Ignem 3").
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function hitchens_vampire_disciplines(): array {
		return [
			[ 'name' => 'Animalism', 'level' => 5 ],
			[ 'name' => 'Auspex', 'level' => 5 ],
			[ 'name' => 'Celerity', 'level' => 5 ],
			[ 'name' => 'Chimerstry', 'level' => 5 ],
			[ 'name' => 'Dominate', 'level' => 5 ],
			[ 'name' => 'Fortitude', 'level' => 5 ],
			[ 'name' => 'Obfuscate', 'level' => 5 ],
			[ 'name' => 'Obtenebration', 'level' => 5 ],
			[ 'name' => 'Potence', 'level' => 5 ],
			[ 'name' => 'Presence', 'level' => 5 ],
			[ 'name' => 'Protean', 'level' => 5 ],
			[ 'name' => 'Quietus', 'level' => 4 ],
			[ 'name' => 'Serpentis', 'level' => 4 ],
			[ 'name' => 'Thanatosis', 'level' => 4 ],
			[ 'name' => 'Vicissitude', 'level' => 4 ],
			// Constructed for this test - see the docblock above.
			[ 'name' => 'Creo Ignem', 'level' => 3 ],
		];
	}

	// ---------------------------------------------------------------------------------
	// The fix: a family moved to a different block resolves and prices there.
	// ---------------------------------------------------------------------------------

	/**
	 * `vampire-blood-magic`'s real `Lure of Flames` family carries
	 * `moved_from: [{block: "vampire-disciplines", name: "Creo Ignem"}]` - confirmed by
	 * reading the shipped file, not assumed. Held at 3 under its old block/name, it must
	 * price from `Lure of Flames`'s own declared costs (3+3+6 = 12), not read as
	 * `family_not_in_catalog` for having been renamed and moved out from under it.
	 */
	public function test_a_family_moved_into_blood_magic_prices_correctly_when_held_under_its_old_block(): void {
		$moved_power = self::find_power( self::def( 'vampire-blood-magic' ), 'Lure of Flames' );
		$this->assertNotNull( $moved_power, 'sanity: the real catalog must actually declare Lure of Flames' );
		$this->assertSame(
			[ 'vampire-disciplines' ],
			array_column( (array) $moved_power->moved_from, 'block' ),
			'sanity: Lure of Flames must actually carry the moved_from this test relies on'
		);

		$held = [ 'name' => 'Creo Ignem', 'level' => 3 ];

		$result = Cost_Engine::price_held_tiered_power(
			self::def( 'vampire-disciplines' ),
			$held,
			true,
			'vampire-disciplines',
			self::$blocks
		);

		$this->assertSame( 12, $result['xp'], 'Lure of Flames 1-3: 3 + 3 + 6, its own declared _meta.costs' );
		$this->assertSame( 'sequential_sum', $result['basis'] );
		$this->assertNull( $result['unpriced_reason'] );
	}

	/**
	 * Revert-test, watched failing first per this project's own standing discipline: calling
	 * `price_held_tiered_power()` the way every call site did before this release - no
	 * `$block_slug`/`$blocks`, 1.3.2's own default - reproduces the pre-fix defect the brief
	 * names on the identical held row: `family_not_in_catalog`, unpriced.
	 */
	public function test_reverting_to_the_pre_resolver_call_shape_reproduces_family_not_in_catalog(): void {
		$held = [ 'name' => 'Creo Ignem', 'level' => 3 ];

		$result = Cost_Engine::price_held_tiered_power( self::def( 'vampire-disciplines' ), $held, true );

		$this->assertNull( $result['xp'] );
		$this->assertSame( 'family_not_in_catalog', $result['unpriced_reason'] );
	}

	// ---------------------------------------------------------------------------------
	// Over-correction guard: every ordinary, unmoved/unrenamed holding is unaffected.
	// ---------------------------------------------------------------------------------

	/**
	 * Hitchens' own real, unmoved Discipline holdings must price exactly the same whether
	 * or not the resolver's cross-block step ever runs - the alias/`moved_from` fallback
	 * inside `find_power()`/`price_held_tiered_power()` must never change an answer that was
	 * already correct.
	 */
	public function test_hitchens_own_unmoved_holdings_price_identically_with_or_without_cross_block_resolution(): void {
		foreach ( self::hitchens_vampire_disciplines() as $held ) {
			if ( $held['name'] === 'Creo Ignem' ) {
				continue; // the one constructed row - its own resolution is asserted above.
			}

			$without_blocks = Cost_Engine::price_held_tiered_power( self::def( 'vampire-disciplines' ), $held, true );
			$with_blocks     = Cost_Engine::price_held_tiered_power(
				self::def( 'vampire-disciplines' ),
				$held,
				true,
				'vampire-disciplines',
				self::$blocks
			);

			$this->assertSame(
				$without_blocks,
				$with_blocks,
				"{$held['name']} must price identically whether or not the resolver's cross-block step runs"
			);
			$this->assertNotNull( $with_blocks['xp'], "{$held['name']} is real and unmoved, and must still price" );
		}
	}

	// ---------------------------------------------------------------------------------
	// Same-block alias: a renamed family, still in the same block.
	// ---------------------------------------------------------------------------------

	/**
	 * `vampire-blood-magic`'s real `Path of the Dry Nile` carries `aliases: ["Path of Dry
	 * Nile"]` (B-7's spelling pass, `1.3.1-design-workflow.md` §11.3) - a rename that never
	 * left the block, so no `$blocks` map is even needed for this one.
	 */
	public function test_a_renamed_family_prices_identically_under_its_old_name_in_the_same_block(): void {
		$family = self::find_power( self::def( 'vampire-blood-magic' ), 'Path of the Dry Nile' );
		$this->assertNotNull( $family );
		$this->assertContains( 'Path of Dry Nile', (array) ( $family->aliases ?? [] ), 'sanity: this test relies on the real recorded alias' );

		$current = Cost_Engine::price_held_tiered_power( self::def( 'vampire-blood-magic' ), [ 'name' => 'Path of the Dry Nile', 'level' => 4 ], true );
		$aliased = Cost_Engine::price_held_tiered_power( self::def( 'vampire-blood-magic' ), [ 'name' => 'Path of Dry Nile', 'level' => 4 ], true );

		$this->assertNotNull( $aliased['xp'] );
		$this->assertSame( $current, $aliased );
	}

	/**
	 * Same guard as above, restated for a genuinely nonexistent name: alias resolution must
	 * never manufacture a match for a name the catalog has simply never heard of.
	 */
	public function test_a_name_the_catalog_has_never_heard_of_still_reads_family_not_in_catalog(): void {
		$result = Cost_Engine::price_held_tiered_power(
			self::def( 'vampire-disciplines' ),
			[ 'name' => 'Not A Real Discipline At All', 'level' => 3 ],
			true,
			'vampire-disciplines',
			self::$blocks
		);

		$this->assertNull( $result['xp'] );
		$this->assertSame( 'family_not_in_catalog', $result['unpriced_reason'] );
	}

	// ---------------------------------------------------------------------------------
	// trait_list moved_from wiring - real shape, synthetic definitions (the real
	// `vampire-gargoyle-powers` case changes SECTION TYPE, tiered_power to trait_list, which
	// is a held-row-shape change this class never attempts - see the design-workflow note).
	// ---------------------------------------------------------------------------------

	/**
	 * Proves `price_held_trait_list_item()`'s own cross-block step, using the real
	 * object-shaped `moved_from` a trait_list item carries (`vampire-gargoyle-powers`' own
	 * items each carry exactly this shape) against a small synthetic pair of blocks, since
	 * the real Gargoyle Powers case also changes section type family+pick to a flat item,
	 * a held-row reshape this resolver deliberately does not attempt.
	 */
	public function test_price_held_trait_list_item_resolves_a_moved_item_across_blocks(): void {
		$old_block_definition = json_decode( '{"items":[]}' );
		$new_block_definition = json_decode(
			json_encode(
				[
					'items' => [
						[
							'name'       => 'Extra Arms',
							'cost'       => '3',
							'moved_from' => [ 'block' => 'old-block', 'name' => 'Extra Arms (old)' ],
						],
					],
				]
			)
		);
		$blocks = [
			'old-block' => (object) [ 'slug' => 'old-block', 'section_type' => 'trait_list', 'definition' => $old_block_definition ],
			'new-block' => (object) [ 'slug' => 'new-block', 'section_type' => 'trait_list', 'definition' => $new_block_definition ],
		];

		$result = Cost_Engine::price_held_trait_list_item(
			$old_block_definition,
			[ 'name' => 'Extra Arms (old)', 'count' => 1 ],
			'old-block',
			$blocks
		);

		$this->assertSame( 3, $result['xp'] );
		$this->assertNull( $result['unpriced_reason'] );
	}

	/**
	 * @return object|null
	 */
	private static function find_power( object $definition, string $name ) {
		foreach ( $definition->powers as $power ) {
			if ( ( $power->name ?? null ) === $name ) {
				return $power;
			}
		}
		return null;
	}
}
