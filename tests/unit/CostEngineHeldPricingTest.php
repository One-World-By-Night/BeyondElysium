<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Services\Cost_Engine;
use PHPUnit\Framework\TestCase;

/**
 * The three held-state pricing functions PC-1 adds to `Cost_Engine`
 * (point-calculator-design.md §4, §5.1) - `Services\Point_Audit`'s only
 * source of numbers. Asserted against the real seeded catalog, not
 * synthetic fixtures, per the design doc's own instruction (§7 PC-1).
 *
 * @see BE_PROCESS/design/point-calculator-design.md
 */
class CostEngineHeldPricingTest extends TestCase {

	/** @var array<string,object> Real seeded block definitions, decoded to objects like Schema_Block rows. */
	private static array $definitions = [];

	public static function setUpBeforeClass(): void {
		foreach ( Seeder::get_blocks_to_seed() as $block ) {
			self::$definitions[ $block['slug'] ] = json_decode( json_encode( $block['definition'] ) );
		}
	}

	private static function def( string $slug ): object {
		return self::$definitions[ $slug ];
	}

	// -------------------------------------------------------------------------
	// trait_list
	// -------------------------------------------------------------------------

	public function test_a_negative_block_prices_as_earned_not_spent(): void {
		$result = Cost_Engine::price_held_trait_list_item( self::def( 'met-flaws' ), [ 'name' => 'Curiosity', 'count' => 2 ] );

		$this->assertSame( -4, $result['xp'], 'Curiosity is 2/dot on a negative block, x2 held' );
		$this->assertNull( $result['unpriced_reason'] );
	}

	public function test_atomic_block_holding_the_same_name_twice_prices_each_line_independently(): void {
		$definition = self::def( 'met-merits' );
		$first      = Cost_Engine::price_held_trait_list_item( $definition, [ 'name' => 'Iron Will', 'count' => 3 ] );
		$second     = Cost_Engine::price_held_trait_list_item( $definition, [ 'name' => 'Iron Will', 'count' => 5 ] );

		$this->assertNotNull( $first['xp'] );
		$this->assertNotNull( $second['xp'] );
		$this->assertNotSame( $first['xp'], $second['xp'], 'two independently-priced lines, never one merged holding' );
	}

	public function test_a_variable_range_cost_with_no_chosen_cost_prices_at_the_floor(): void {
		$definition = self::def( 'met-merits' );
		$item       = self::find_item( $definition, 'Iron Will' );
		$this->assertNotNull( $item, 'Iron Will is the design doc\'s own example of a real range-cost item' );
		$this->assertSame( 'range', Cost_Engine::parse_cost_rule( (string) $item->cost )['type'] );

		$result = Cost_Engine::price_held_trait_list_item( $definition, [ 'name' => 'Iron Will', 'count' => 1 ] );

		$rule = Cost_Engine::parse_cost_rule( (string) $item->cost );
		$this->assertSame( min( $rule['values'] ), $result['xp'] );
		$this->assertSame( 'rule_floor', $result['basis'] );
	}

	public function test_a_catalog_item_with_no_cost_is_unpriced_never_zero(): void {
		$definition = self::def( 'met-physical-traits' );
		$item       = self::$definitions['met-physical-traits']->items[0];
		$this->assertObjectNotHasProperty( 'cost', $item, 'every met-physical-traits item has no cost field (point-calculator-design.md §0)' );

		$result = Cost_Engine::price_held_trait_list_item( $definition, [ 'name' => $item->name, 'count' => 3 ] );

		$this->assertNull( $result['xp'], 'a 0 here would be a false claim of "free"' );
		$this->assertSame( 'catalog_item_has_no_cost', $result['unpriced_reason'] );
	}

	public function test_a_held_name_not_in_the_catalog_is_unpriced_with_its_own_reason(): void {
		$result = Cost_Engine::price_held_trait_list_item( self::def( 'met-merits' ), [ 'name' => 'Not A Real Merit', 'count' => 1 ] );

		$this->assertNull( $result['xp'] );
		$this->assertSame( 'name_not_in_catalog', $result['unpriced_reason'] );
	}

	public function test_a_custom_entry_with_no_chosen_cost_is_unpriced(): void {
		$result = Cost_Engine::price_held_trait_list_item( self::def( 'met-merits' ), [ 'name' => 'Homebrew Thing', 'custom' => true ] );

		$this->assertNull( $result['xp'] );
		$this->assertSame( 'custom_no_catalog_entry', $result['unpriced_reason'] );
	}

	public function test_a_custom_entry_with_a_chosen_cost_uses_it(): void {
		$result = Cost_Engine::price_held_trait_list_item( self::def( 'met-merits' ), [ 'name' => 'Homebrew Thing', 'custom' => true, 'chosen_cost' => 2, 'count' => 3 ] );

		$this->assertSame( 6, $result['xp'] );
		$this->assertSame( 'chosen_cost', $result['basis'] );
	}

	// -------------------------------------------------------------------------
	// tiered_power
	// -------------------------------------------------------------------------

	/**
	 * Owner ruling, 1.0.0-review F-040: levels add up. A held Discipline at level five is priced as
	 * every level up to it, not level five's own price.
	 */
	public function test_a_discipline_held_at_level_five_prices_every_level_up_to_it(): void {
		$definition = self::def( 'vampire-disciplines' );
		$this->assertNotEmpty( $definition->sequential ?? false, 'the seeded Disciplines ladder adds up' );

		// 1.2.10 S6. This asserted **45** before the declared ladder existed, by summing
		// `3+6+9+12+15` - one tier per rank, which charged **elder (12) and master (15)
		// rates for ladder rungs**. No genre works that way. A 2/2/1 ladder is basic,
		// basic, intermediate, intermediate, advanced, so five rungs are `3+3+6+6+9 = 27`,
		// exactly as `1.2.10-design-workflow.md` specifies for a full ladder.
		//
		// The rung prices from its **rank**, not its own item, which is also what keeps
		// D66's one miskeyed Animalism "advanced" row (cost 3 against every sibling's 9)
		// from charging a real player 21 instead of 27.
		$result = Cost_Engine::price_held_tiered_power( $definition, [ 'name' => 'Animalism', 'level' => 5 ], true );

		$this->assertSame( 27, $result['xp'] );
		$this->assertSame( 'sequential_sum', $result['basis'] );
	}

	public function test_a_sequential_power_sums_every_step_from_zero(): void {
		$definition = self::def( 'mage-spheres' );
		$this->assertNotEmpty( $definition->sequential ?? false, 'mage-spheres must be sequential for this test to mean anything' );

		// Three rungs of mage-spheres' declared 2/2/1 ladder are basic, basic,
		// intermediate - `5+5+10 = 20`, against the 30 this asserted when every rank
		// mapped to its own tier. The scale still comes from the block's own seeded data
		// (5/10/15, not vampire-disciplines' 3/6/9), which is the point this test has
		// always made and still makes.
		//
		// D70 says that seeded 5/10/15 is itself the non-specialty price and the base
		// should be 4/8/12. That is a **catalog** correction and belongs to 1.3.0 - this
		// release changes the ladder arithmetic, never a price.
		$result = Cost_Engine::price_held_tiered_power( $definition, [ 'name' => 'Correspondence', 'level' => 3 ], true );

		$this->assertSame( 20, $result['xp'] );
		$this->assertSame( 'sequential_sum', $result['basis'] );
	}

	/**
	 * D77/S6b regression, watched failing first. Before the fix this scanned `$power->levels`
	 * for a truthy `power_name` - and once a block declares `_meta`, every *rung* carries
	 * `power_name` too (Celerity's own "Alacrity"/"Swiftness"), so the test found two rungs,
	 * called them Elder picks, priced them successfully, and asserted a `basis` string
	 * rather than a real number - passing green while testing the opposite of its own name.
	 * Reads the real `elder` container instead and asserts actual XP.
	 */
	public function test_two_distinct_elder_picks_in_one_family_each_price_flat_and_independently(): void {
		$definition = self::def( 'vampire-disciplines' );
		$family     = null;
		$picks      = [];

		foreach ( $definition->powers as $power ) {
			foreach ( (array) ( $power->elder ?? [] ) as $rank => $rank_picks ) {
				if ( $rank === 'innate' ) {
					continue;
				}
				$named = array_values( array_filter( array_map( static fn( $level ) => $level->power_name ?? null, (array) $rank_picks ) ) );
				if ( count( $named ) >= 2 ) {
					$family = $power->name;
					$picks  = array_slice( $named, 0, 2 );
					break 2;
				}
			}
		}
		$this->assertNotNull( $family, 'at least one real seeded discipline must offer two or more Elder+ named picks' );

		[ $pick_a, $pick_b ] = $picks;

		$result_a = Cost_Engine::price_held_tiered_power( $definition, [ 'name' => $family, 'power_name' => $pick_a ], true );
		$result_b = Cost_Engine::price_held_tiered_power( $definition, [ 'name' => $family, 'power_name' => $pick_b ], true );

		$this->assertIsInt( $result_a['xp'], "{$family}: {$pick_a} must price to a real number, not stay unpriced" );
		$this->assertIsInt( $result_b['xp'], "{$family}: {$pick_b} must price to a real number, not stay unpriced" );
		$this->assertGreaterThan( 0, $result_a['xp'] );
		$this->assertGreaterThan( 0, $result_b['xp'] );
		$this->assertContains( $result_a['basis'], [ 'elder_pick', 'tier_fallback' ] );
		$this->assertContains( $result_b['basis'], [ 'elder_pick', 'tier_fallback' ] );
	}

	/**
	 * D77/S6b regression, watched failing first: before the fix, buying a named pick
	 * (elder-and-above) priced at 0 XP the moment the block declared `_meta` and its
	 * `levels` narrowed to the ladder alone, since `elder_tier_cost()`/
	 * `find_power_level_by_name()` searched only the ladder and never found the pick.
	 */
	public function test_a_named_pick_survives_the_declared_ladder_split(): void {
		$result = Cost_Engine::price_held_tiered_power( self::def( 'vampire-disciplines' ), [ 'name' => 'Celerity', 'power_name' => 'Precision' ], true );

		$this->assertSame( 12, $result['xp'], 'Precision is a real elder pick, priced from the block\'s declared elder cost' );
		$this->assertSame( 'elder_pick', $result['basis'] );
	}

	public function test_buying_a_named_pick_is_never_free_after_the_split(): void {
		$definition = self::def( 'vampire-disciplines' );
		$change_data = [ 'block_slug' => 'vampire-disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Precision' ] ];

		$cost = Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $change_data, true );

		$this->assertSame( 12, $cost, 'the purchase flow reads the same split containers as the audit path' );
	}

	// -------------------------------------------------------------------------
	// C1 - a stored level is a total, not a rung (1.2.10 §A')
	// -------------------------------------------------------------------------

	/**
	 * The design doc's own acceptance example, against the real seeded catalog: Celerity's
	 * declared ladder is 2/2/1 at 3/6/9 (5 rungs, 3+3+6+6+9 = 27) and its elder rank costs
	 * 12. A stored `level: 9` - one of the seven approved production PCs kept since inbound
	 * migration (D41) - reads as the full ladder plus four unnamed picks at 12 each.
	 */
	public function test_a_stored_level_above_the_ceiling_reads_as_the_full_ladder_plus_picks(): void {
		$result = Cost_Engine::price_held_tiered_power( self::def( 'vampire-disciplines' ), [ 'name' => 'Celerity', 'level' => 9 ], true );

		$this->assertSame( 75, $result['xp'], '27 (5 rungs) + 4 picks at 12' );
		$this->assertSame( 'sequential_sum', $result['basis'] );
		$this->assertNull( $result['unpriced_reason'] );
	}

	public function test_a_stored_level_one_above_the_ceiling_adds_exactly_one_pick(): void {
		$result = Cost_Engine::price_held_tiered_power( self::def( 'vampire-disciplines' ), [ 'name' => 'Celerity', 'level' => 6 ], true );

		$this->assertSame( 39, $result['xp'], '27 (5 rungs) + 1 pick at 12' );
	}

	public function test_a_stored_level_at_the_ceiling_is_unaffected_by_c1(): void {
		$result = Cost_Engine::price_held_tiered_power( self::def( 'vampire-disciplines' ), [ 'name' => 'Celerity', 'level' => 5 ], true );

		$this->assertSame( 27, $result['xp'] );
	}

	public function test_an_above_ceiling_level_out_of_clan_charges_the_modifier_on_every_rung_and_pick(): void {
		$in_clan     = Cost_Engine::price_held_tiered_power( self::def( 'vampire-disciplines' ), [ 'name' => 'Celerity', 'level' => 6 ], true );
		$out_of_clan = Cost_Engine::price_held_tiered_power( self::def( 'vampire-disciplines' ), [ 'name' => 'Celerity', 'level' => 6 ], false );

		// 5 rungs + 1 pick = 6 chargeable units, each +1 out-of-clan.
		$this->assertSame( $in_clan['xp'] + 6, $out_of_clan['xp'] );
	}

	// -------------------------------------------------------------------------
	// C2 - a non-rank tier is always a pick, never a rung (1.2.10 §A2d)
	// -------------------------------------------------------------------------

	/**
	 * The importer's own "***" placeholder (`reference/MET-POWER-ACQUISITION.md`, "the
	 * tier: *** fallback"): 1,637 production holdings carry this shape - `custom: true`,
	 * `power_name` set, no stored cost anywhere. Owner ruling, 2026-09-21: honestly
	 * unpriced, never guessed.
	 */
	public function test_a_placeholder_tier_custom_entry_resolves_to_one_unpriced_pick(): void {
		$result = Cost_Engine::price_held_tiered_power(
			self::def( 'vampire-disciplines' ),
			[ 'name' => 'Sawafi', 'power_name' => 'Combination: Sawafi Form', 'tier' => '***', 'custom' => true ],
			true
		);

		$this->assertNull( $result['xp'], 'nothing is guessed - there is no stored cost to price this from' );
		$this->assertSame( 'custom_no_catalog_entry', $result['unpriced_reason'] );
	}

	public function test_a_placeholder_tier_custom_entry_with_a_chosen_cost_uses_it(): void {
		$result = Cost_Engine::price_held_tiered_power(
			self::def( 'vampire-disciplines' ),
			[ 'name' => 'Sawafi', 'power_name' => 'Combination: Sawafi Form', 'tier' => '***', 'custom' => true, 'chosen_cost' => 8 ],
			true
		);

		$this->assertSame( 8, $result['xp'] );
		$this->assertSame( 'chosen_cost', $result['basis'] );
	}

	/**
	 * A placeholder-tier custom holding is never read as a ladder rung, even carrying a
	 * `level` alongside it (D41's own numbered-total derivation) - `custom: true` always
	 * routes to the pick path, never the numbered-ladder path.
	 */
	public function test_a_placeholder_tier_custom_entry_with_a_level_is_never_read_as_a_rung(): void {
		$result = Cost_Engine::price_held_tiered_power(
			self::def( 'vampire-disciplines' ),
			[ 'name' => 'Sawafi', 'power_name' => 'Combination: Sawafi Form', 'tier' => '***', 'custom' => true, 'level' => 3 ],
			true
		);

		$this->assertNull( $result['xp'] );
		$this->assertNotSame( 'sequential_sum', $result['basis'], 'never priced off the ladder' );
	}

	/**
	 * C2's real-stored-cost case: an ST's own add_to_catalog opt-in (Import_Controller)
	 * can teach the catalog a custom power by name after the fact - once that happens the
	 * holding prices from the real catalog entry, not chosen_cost or unpriced.
	 */
	public function test_a_custom_entry_whose_power_name_now_matches_a_real_catalog_pick_prices_from_it(): void {
		$result = Cost_Engine::price_held_tiered_power(
			self::def( 'vampire-disciplines' ),
			[ 'name' => 'Celerity', 'power_name' => 'Precision', 'tier' => '***', 'custom' => true ],
			true
		);

		$this->assertSame( 12, $result['xp'] );
		$this->assertSame( 'elder_pick', $result['basis'] );
	}

	// -------------------------------------------------------------------------
	// D77/S6b - Decision 090: never a fabricated 0 for an unknown tier with no cost
	// -------------------------------------------------------------------------

	public function test_an_unknown_tier_pick_with_no_cost_anywhere_is_unpriced_never_zero(): void {
		$definition = json_decode( json_encode( [
			'sequential' => true,
			'powers'     => [
				[
					'name'   => 'Weird Power',
					'levels' => [],
					'elder'  => [ 'unknown' => [ [ 'tier' => 'unknown', 'power_name' => 'Odd One', 'level' => null ] ] ],
				],
			],
		] ) );

		$result = Cost_Engine::price_held_tiered_power( $definition, [ 'name' => 'Weird Power', 'power_name' => 'Odd One' ], true );

		$this->assertNull( $result['xp'], 'a 0 here would be a false claim of "free" (Decision 090)' );
		$this->assertSame( 'catalog_item_has_no_cost', $result['unpriced_reason'] );
	}

	public function test_an_out_of_type_pick_carries_the_seeded_modifier(): void {
		$definition = self::def( 'vampire-disciplines' );
		$this->assertSame( 1, $definition->out_of_type_cost_modifier ?? null );

		$in_type     = Cost_Engine::price_held_tiered_power( $definition, [ 'name' => 'Auspex', 'level' => 1 ], true );
		$out_of_type = Cost_Engine::price_held_tiered_power( $definition, [ 'name' => 'Auspex', 'level' => 1 ], false );

		$this->assertSame( $in_type['xp'] + 1, $out_of_type['xp'] );
	}

	public function test_a_held_family_not_in_the_catalog_is_unpriced(): void {
		$result = Cost_Engine::price_held_tiered_power( self::def( 'vampire-disciplines' ), [ 'name' => 'Thaumaturgy', 'level' => 3 ], true );

		$this->assertNull( $result['xp'] );
		$this->assertSame( 'family_not_in_catalog', $result['unpriced_reason'] );
	}

	public function test_a_keep_custom_pick_with_a_chosen_cost_uses_it(): void {
		$result = Cost_Engine::price_held_tiered_power( self::def( 'vampire-disciplines' ), [ 'name' => 'Some Custom Path', 'keep_custom' => true, 'chosen_cost' => 12 ], true );

		$this->assertSame( 12, $result['xp'] );
		$this->assertSame( 'chosen_cost', $result['basis'] );
	}

	// -------------------------------------------------------------------------
	// resource_pool (PC-9)
	// -------------------------------------------------------------------------

	public function test_a_pool_with_no_cost_per_dot_is_unpriced_not_free(): void {
		$definition = self::def( 'vampire-resources' );
		$blood      = $this->find_pool( $definition, 'Blood' );
		$this->assertObjectNotHasProperty( 'cost_per_dot', $blood, 'Blood is never a priced pool - it scales with Generation (PC-10 owner ruling)' );

		$result = Cost_Engine::price_held_resource_pool( $definition, 'Blood', [ 'permanent' => 12, 'temporary' => 8 ] );

		$this->assertNull( $result['xp'] );
		$this->assertSame( 'resource_pool_no_pricing_rule', $result['unpriced_reason'] );
	}

	public function test_a_priced_pool_charges_only_dots_above_its_free_baseline(): void {
		// Real seeded rate (PC-10): Willpower is 3 XP/dot, 2 free, for vampire.
		$result = Cost_Engine::price_held_resource_pool( self::def( 'vampire-resources' ), 'Willpower', [ 'permanent' => 5, 'temporary' => 5 ] );

		$this->assertSame( 9, $result['xp'], '(5 - 2 free) * 3/dot' );
	}

	public function test_a_pool_at_or_below_its_free_baseline_never_refunds(): void {
		// Real seeded free_dots for vampire Willpower is 2 - held at exactly that baseline.
		$result = Cost_Engine::price_held_resource_pool( self::def( 'vampire-resources' ), 'Willpower', [ 'permanent' => 2, 'temporary' => 2 ] );

		$this->assertSame( 0, $result['xp'] );
	}

	/**
	 * PC-10: the real seeded rates match the owner ruling exactly, cited per
	 * line to its own GV301Source method (point-calculator-design.md §8.1).
	 * Blood carries no rate at all - Grapevine's own point estimator never
	 * prices it.
	 */
	public function test_pc10_seeded_rates_match_the_owner_ruling(): void {
		$cases = [
			[ 'vampire-resources',    'Willpower', 3, 2 ],
			[ 'werewolf-resources',   'Rage',      3, 3 ],
			[ 'werewolf-resources',   'Gnosis',    3, 3 ],
			[ 'werewolf-resources',   'Willpower', 3, 3 ],
			[ 'mage-resources',       'Arete',     4, 1 ],
			[ 'mage-resources',       'Willpower', 3, 5 ],
			[ 'changeling-resources', 'Glamour',   3, 4 ],
			[ 'changeling-resources', 'Willpower', 3, 3 ],
		];

		foreach ( $cases as [ $block_slug, $pool_name, $expected_cost, $expected_free ] ) {
			$pool = $this->find_pool( self::def( $block_slug ), $pool_name );
			$this->assertSame( $expected_cost, $pool->cost_per_dot ?? null, "{$block_slug}.{$pool_name} cost_per_dot" );
			$this->assertSame( $expected_free, $pool->free_dots ?? null, "{$block_slug}.{$pool_name} free_dots" );
		}
	}

	public function test_pc10_blood_and_every_non_cited_pool_carries_no_rate(): void {
		$never_priced = [
			[ 'vampire-resources', 'Blood' ],
			[ 'vampire-resources', 'Morality' ],
			[ 'changeling-resources', 'Banality' ],
			[ 'mage-resources', 'Quintessence' ],
			[ 'mage-resources', 'Paradox' ],
		];
		foreach ( $never_priced as [ $block_slug, $pool_name ] ) {
			$pool = $this->find_pool( self::def( $block_slug ), $pool_name );
			$this->assertObjectNotHasProperty( 'cost_per_dot', $pool, "{$block_slug}.{$pool_name} must stay unpriced" );
		}
	}

	public function test_price_resource_pool_change_prices_the_purchase_flow_delta(): void {
		$definition = self::def( 'vampire-resources' );
		$sheet_data = [ 'vampire-resources' => [ 'Willpower' => [ 'permanent' => 4, 'temporary' => 4 ] ] ];

		$cost = Cost_Engine::price_resource_pool_change(
			$sheet_data,
			$definition,
			'vampire-resources',
			[ 'values' => [ 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ] ] ]
		);

		$this->assertSame( 3, $cost, 'one dot above the old chargeable amount, at 3 XP/dot' );
	}

	public function test_price_resource_pool_change_is_free_for_an_unpriced_pool(): void {
		$definition = self::def( 'vampire-resources' );
		$sheet_data = [ 'vampire-resources' => [ 'Blood' => [ 'permanent' => 10, 'temporary' => 10 ] ] ];

		$cost = Cost_Engine::price_resource_pool_change(
			$sheet_data,
			$definition,
			'vampire-resources',
			[ 'values' => [ 'Blood' => [ 'permanent' => 14, 'temporary' => 14 ] ] ]
		);

		$this->assertSame( 0, $cost, 'Blood has no pricing rule - a change to it costs nothing, not an error' );
	}

	// -------------------------------------------------------------------------
	// helpers
	// -------------------------------------------------------------------------

	private function find_item( object $definition, string $name ): ?object {
		foreach ( $definition->items as $item ) {
			if ( $item->name === $name ) {
				return $item;
			}
		}
		return null;
	}

	private function find_power( object $definition, string $name ): object {
		foreach ( $definition->powers as $power ) {
			if ( $power->name === $name ) {
				return $power;
			}
		}
		$this->fail( "Power \"{$name}\" not found in real seeded catalog." );
	}

	private function find_pool( object $definition, string $name ): object {
		foreach ( $definition->pools as $pool ) {
			if ( $pool->name === $name ) {
				return $pool;
			}
		}
		$this->fail( "Pool \"{$name}\" not found in real seeded catalog." );
	}

	// -------------------------------------------------------------------------
	// Key order is not book order (found by 1.2.10's pre-deploy trace, 2026-09-22)
	// -------------------------------------------------------------------------

	/**
	 * A decoded JSON object keeps whatever key order it was written in, and the real seeded
	 * ladder comes back as `{"basic":2,"advanced":1,"intermediate":2}` - **advanced before
	 * intermediate**. Anything that walks that map to derive rung order or to find the rank
	 * above the ladder gets the wrong answer.
	 *
	 * Neither bug was visible in a five-rung total, because addition commutes - which is
	 * exactly why every existing test passed while a single-level purchase charged 9 for an
	 * intermediate rung and every above-ceiling pick priced 9 instead of 12. `_meta.ranks` is
	 * book order and is the only thing entitled to answer "which rank comes next".
	 *
	 * @return object A block whose ladder map is deliberately out of book order.
	 */
	private static function out_of_order_block(): object {
		return json_decode( (string) json_encode( [
			'sequential' => true,
			'_meta'      => [
				'ranks'  => [ 'basic', 'intermediate', 'advanced', 'elder', 'master' ],
				// Not book order - exactly how the seeded block decodes.
				'ladder' => [ 'basic' => 2, 'advanced' => 1, 'intermediate' => 2 ],
				'costs'  => [ 'basic' => 3, 'intermediate' => 6, 'advanced' => 9, 'elder' => 12, 'master' => 15 ],
			],
			'powers'     => [ [ 'name' => 'Animalism', 'levels' => [
				[ 'level' => 1, 'tier' => 'basic', 'cost' => '3', 'power_name' => 'A' ],
				[ 'level' => 2, 'tier' => 'basic', 'cost' => '3', 'power_name' => 'B' ],
				[ 'level' => 3, 'tier' => 'intermediate', 'cost' => '6', 'power_name' => 'C' ],
				[ 'level' => 4, 'tier' => 'intermediate', 'cost' => '6', 'power_name' => 'D' ],
				[ 'level' => 5, 'tier' => 'advanced', 'cost' => '9', 'power_name' => 'E' ],
			] ] ],
		] ) );
	}

	public function test_one_rung_prices_from_book_order_not_ladder_key_order(): void {
		$definition = self::out_of_order_block();
		$sheet      = [ 'd' => [ [ 'name' => 'Animalism', 'level' => 2 ] ] ];
		$change     = [ 'block_slug' => 'd', 'trait' => [ 'name' => 'Animalism', 'level' => 3 ] ];

		$cost = Cost_Engine::price_tiered_power_change( $sheet, $definition, 'modify_trait', $change, true );

		$this->assertSame( 6, $cost, 'rung 3 is intermediate; walking the ladder map made it advanced and charged 9' );
	}

	public function test_an_above_ceiling_pick_prices_from_book_order_not_ladder_key_order(): void {
		$result = Cost_Engine::price_held_tiered_power( self::out_of_order_block(), [ 'name' => 'Animalism', 'level' => 9 ], true );

		// 3+3+6+6+9 = 27, then four picks at the first rank ABOVE the ladder - elder, 12.
		$this->assertSame( 75, $result['xp'], 'picks priced 9 (advanced) when the map order decided which rank came next' );
	}

	public function test_the_full_ladder_total_is_unchanged_by_key_order(): void {
		// The total was always right, which is why this class of bug hid: 3+3+6+6+9 either way.
		$result = Cost_Engine::price_held_tiered_power( self::out_of_order_block(), [ 'name' => 'Animalism', 'level' => 5 ], true );

		$this->assertSame( 27, $result['xp'] );
	}
}
