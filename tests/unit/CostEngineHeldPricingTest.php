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
 * @see BE_PROCESS/point-calculator-design.md
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

		$power = $this->find_power( $definition, 'Animalism' );
		$sum   = 0;
		foreach ( $power->levels as $level ) {
			$n = (int) ( $level->level ?? 0 );
			if ( $n >= 1 && $n <= 5 && isset( $level->cost ) ) {
				$sum += (int) $level->cost;
			}
		}

		$result = Cost_Engine::price_held_tiered_power( $definition, [ 'name' => 'Animalism', 'level' => 5 ], true );

		$this->assertSame( $sum, $result['xp'] );
		$this->assertSame( 'sequential_sum', $result['basis'] );
	}

	public function test_a_sequential_power_sums_every_step_from_zero(): void {
		$definition = self::def( 'mage-spheres' );
		$this->assertNotEmpty( $definition->sequential ?? false, 'mage-spheres must be sequential for this test to mean anything' );

		$power = $this->find_power( $definition, 'Correspondence' );
		$sum   = 0;
		foreach ( $power->levels as $level ) {
			if ( (int) ( $level->level ?? 0 ) <= 3 && isset( $level->cost ) ) {
				$sum += (int) $level->cost;
			}
		}

		$result = Cost_Engine::price_held_tiered_power( $definition, [ 'name' => 'Correspondence', 'level' => 3 ], true );

		$this->assertSame( $sum, $result['xp'] );
		$this->assertSame( 'sequential_sum', $result['basis'] );
	}

	public function test_two_distinct_elder_picks_in_one_family_each_price_flat_and_independently(): void {
		$definition = self::def( 'vampire-disciplines' );
		$elder_names = [];
		foreach ( $definition->powers as $power ) {
			foreach ( $power->levels as $level ) {
				if ( ! empty( $level->power_name ?? null ) ) {
					$elder_names[ $power->name ][] = $level->power_name;
				}
			}
			if ( count( $elder_names[ $power->name ] ?? [] ) >= 2 ) {
				break;
			}
		}
		$family = null;
		foreach ( $elder_names as $name => $picks ) {
			if ( count( $picks ) >= 2 ) {
				$family = $name;
				break;
			}
		}
		$this->assertNotNull( $family, 'at least one real seeded discipline must offer two or more Elder+ named picks' );

		[ $pick_a, $pick_b ] = array_slice( $elder_names[ $family ], 0, 2 );

		$result_a = Cost_Engine::price_held_tiered_power( $definition, [ 'name' => $family, 'power_name' => $pick_a ], true );
		$result_b = Cost_Engine::price_held_tiered_power( $definition, [ 'name' => $family, 'power_name' => $pick_b ], true );

		$this->assertContains( $result_a['basis'], [ 'elder_pick', 'tier_fallback', 'innate_free' ] );
		$this->assertContains( $result_b['basis'], [ 'elder_pick', 'tier_fallback', 'innate_free' ] );
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
}
