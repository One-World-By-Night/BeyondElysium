<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Cost_Engine;
use PHPUnit\Framework\TestCase;

/**
 * Pure pricing logic.
 */
class CostEngineTest extends TestCase {

	/**
	 * json_decode(json_encode()) guarantees real nested-stdClass shape, matching how Schema_Block::decode_definition()
	 * actually decodes.
	 */
	private static function definition( array $data ) {
		return json_decode( json_encode( $data ) );
	}

	// -----------------------------------------------------------------------
	// price_item_cost / parse_cost_rule
	// -----------------------------------------------------------------------

	public function test_plain_integer_cost_ignores_chosen_cost(): void {
		$this->assertSame( 1, Cost_Engine::price_item_cost( '1', 3 ) );
	}

	public function test_or_set_cost_accepts_a_valid_choice(): void {
		$this->assertSame( 3, Cost_Engine::price_item_cost( '1 or 3', 3 ) );
	}

	public function test_or_set_cost_defaults_to_lowest_when_choice_missing(): void {
		$this->assertSame( 1, Cost_Engine::price_item_cost( '1 or 3', null ) );
	}

	public function test_or_set_cost_defaults_to_lowest_when_choice_invalid(): void {
		$this->assertSame( 1, Cost_Engine::price_item_cost( '1 or 3', 7 ) );
	}

	public function test_range_cost_accepts_a_value_within_range(): void {
		$this->assertSame( 5, Cost_Engine::price_item_cost( '1-7', 5 ) );
	}

	public function test_range_cost_defaults_to_low_end_when_out_of_range(): void {
		$this->assertSame( 1, Cost_Engine::price_item_cost( '1-7', 99 ) );
	}

	public function test_unrecognized_cost_format_falls_back_to_leading_integer(): void {
		$this->assertSame( 2, Cost_Engine::price_item_cost( '2 dots', null ) );
	}

	// -----------------------------------------------------------------------
	// price_trait_list_change
	// -----------------------------------------------------------------------

	private function trait_list_block( array $overrides = [] ) {
		return self::definition(
			array_merge(
				[
					'items' => [
						[ 'name' => 'Contacts', 'cost' => '1 or 3' ],
						[ 'name' => 'Boon', 'cost' => '1-7' ],
						[ 'name' => 'Flaw of Note', 'cost' => '2' ],
					],
				],
				$overrides
			)
		);
	}

	public function test_add_trait_prices_from_the_catalog(): void {
		$definition = $this->trait_list_block();
		$change_data = [ 'block_slug' => 'merits', 'trait' => [ 'name' => 'Boon', 'count' => 1, 'chosen_cost' => 3 ] ];

		$cost = Cost_Engine::price_trait_list_change( [], $definition, 'merits', 'add_trait', $change_data );

		$this->assertSame( 3, $cost );
	}

	public function test_add_trait_prices_count_greater_than_one(): void {
		$definition = $this->trait_list_block();
		$change_data = [ 'block_slug' => 'flaws', 'trait' => [ 'name' => 'Flaw of Note', 'count' => 2 ] ];

		$this->assertSame( 4, Cost_Engine::price_trait_list_change( [], $definition, 'flaws', 'add_trait', $change_data ) );
	}

	public function test_remove_trait_is_a_full_refund_of_the_held_state(): void {
		$definition = $this->trait_list_block();
		$sheet = [ 'merits' => [ [ 'name' => 'Boon', 'count' => 1, 'chosen_cost' => 5 ] ] ];
		$change_data = [ 'block_slug' => 'merits', 'trait' => [ 'name' => 'Boon' ] ];

		$this->assertSame(
			-5,
			Cost_Engine::price_trait_list_change( $sheet, $definition, 'merits', 'remove_trait', $change_data )
		);
	}

	public function test_modify_trait_charges_only_the_count_delta(): void {
		$definition = $this->trait_list_block();
		$sheet = [ 'merits' => [ [ 'name' => 'Flaw of Note', 'count' => 1 ] ] ];
		// Flaw of Note is priced at 2.
		$change_data = [ 'block_slug' => 'merits', 'trait' => [ 'name' => 'Flaw of Note', 'count' => 3 ] ];

		$this->assertSame(
			4,
			Cost_Engine::price_trait_list_change( $sheet, $definition, 'merits', 'modify_trait', $change_data )
		);
	}

	public function test_negative_list_inverts_the_sign(): void {
		$definition = $this->trait_list_block( [ 'negative' => true ] );
		$change_data = [ 'block_slug' => 'flaws', 'trait' => [ 'name' => 'Flaw of Note', 'count' => 1 ] ];

		// Adding a flaw GRANTS points.
		$this->assertSame(
			-2,
			Cost_Engine::price_trait_list_change( [], $definition, 'flaws', 'add_trait', $change_data )
		);
	}

	public function test_homebrew_custom_trait_prices_as_zero(): void {
		$definition = $this->trait_list_block();
		$change_data = [ 'block_slug' => 'merits', 'trait' => [ 'name' => 'Homebrew Thing', 'custom' => true ] ];

		$this->assertSame( 0, Cost_Engine::price_trait_list_change( [], $definition, 'merits', 'add_trait', $change_data ) );
	}

	public function test_block_with_no_cost_rule_defaults_to_zero_and_does_not_crash(): void {
		$definition = self::definition( [ 'items' => [ [ 'name' => 'No Cost Item' ] ] ] );
		$change_data = [ 'block_slug' => 'x', 'trait' => [ 'name' => 'No Cost Item', 'count' => 1 ] ];

		$this->assertSame( 0, Cost_Engine::price_trait_list_change( [], $definition, 'x', 'add_trait', $change_data ) );
	}

	public function test_a_change_priced_at_zero_still_returns_a_real_zero_not_null(): void {
		// A zero-cost change (e.g. a free item) must still flow through normal submit/approval.
		$definition = self::definition( [ 'items' => [ [ 'name' => 'Free Thing', 'cost' => '0' ] ] ] );
		$change_data = [ 'block_slug' => 'x', 'trait' => [ 'name' => 'Free Thing', 'count' => 1 ] ];

		$this->assertSame( 0, Cost_Engine::price_trait_list_change( [], $definition, 'x', 'add_trait', $change_data ) );
	}

	// -----------------------------------------------------------------------
	// price_tiered_power_change
	// -----------------------------------------------------------------------

	private function sequential_power_block( array $overrides = [] ) {
		return self::definition(
			array_merge(
				[
					'sequential' => true,
					'_meta' => [ 'out_of_type' => [ 'basic' => '+1', 'intermediate' => '+1', 'advanced' => '+1', 'elder' => '+1' ] ],
					'powers' => [
						[
							'name' => 'Celerity',
							'levels' => [
								[ 'level' => 1, 'cost' => '3' ],
								[ 'level' => 2, 'cost' => '4' ],
								[ 'level' => 3, 'cost' => '5' ],
								[ 'level' => 4, 'cost' => '6' ],
							],
						],
					],
				],
				$overrides
			)
		);
	}

	public function test_sequential_multi_step_sums_every_step_in_between(): void {
		$definition = $this->sequential_power_block();
		$sheet = [ 'disciplines' => [ [ 'name' => 'Celerity', 'level' => 2 ] ] ];
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'level' => 4 ] ];

		// Level 2 -> 4: the level-3 step (5) plus the level-4 step (6) = 11, NOT one flat level-4 price.
		$this->assertSame(
			11,
			Cost_Engine::price_tiered_power_change( $sheet, $definition, 'modify_trait', $change_data, true )
		);
	}

	public function test_sequential_lowering_refunds_the_same_steps_as_a_negative(): void {
		$definition = $this->sequential_power_block();
		$sheet = [ 'disciplines' => [ [ 'name' => 'Celerity', 'level' => 4 ] ] ];
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'level' => 2 ] ];

		$this->assertSame(
			-11,
			Cost_Engine::price_tiered_power_change( $sheet, $definition, 'modify_trait', $change_data, true )
		);
	}

	public function test_in_clan_power_prices_without_the_modifier(): void {
		$definition = $this->sequential_power_block();
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'level' => 1 ] ];

		$this->assertSame(
			3,
			Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $change_data, true )
		);
	}

	public function test_out_of_clan_power_adds_the_modifier_per_step(): void {
		$definition = $this->sequential_power_block();
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'level' => 2 ] ];

		// Levels 1 and 2, each +1 out-of-clan modifier: (3+1) + (4+1) = 9.
		$this->assertSame(
			9,
			Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $change_data, false )
		);
	}

	// -----------------------------------------------------------------------
	// price_tiered_power_change: a `spent_from` block never charges XP
	// -----------------------------------------------------------------------

	private function spent_from_power_block() {
		return self::definition( [
			'_meta'  => [
				'ranks'      => [ 'touched', 'gifted' ],
				'ladder'     => [],
				'spent_from' => [
					'pool_block'      => 'hunter-virtues',
					'by_family_field' => 'virtue',
					'rank_cost'       => [ 'touched' => 1, 'gifted' => 2 ],
				],
			],
			'powers' => [
				[
					'name'   => 'Innocence Path',
					'virtue' => 'Mercy',
					'levels' => [],
					'elder'  => [ 'touched' => [ [ 'level' => null, 'tier' => 'touched', 'power_name' => 'Hide' ] ] ],
				],
			],
		] );
	}

	public function test_buying_a_spent_from_pick_costs_no_xp(): void {
		$definition  = $this->spent_from_power_block();
		$change_data = [ 'block_slug' => 'hunter-edges', 'trait' => [ 'name' => 'Innocence Path', 'power_name' => 'Hide' ] ];

		$this->assertSame( 0, Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $change_data, true ) );
	}

	public function test_removing_a_spent_from_pick_also_costs_no_xp(): void {
		$definition  = $this->spent_from_power_block();
		$sheet       = [ 'hunter-edges' => [ [ 'name' => 'Innocence Path', 'power_name' => 'Hide' ] ] ];
		$change_data = [ 'block_slug' => 'hunter-edges', 'trait' => [ 'name' => 'Innocence Path', 'power_name' => 'Hide' ] ];

		$this->assertSame( 0, Cost_Engine::price_tiered_power_change( $sheet, $definition, 'remove_trait', $change_data, true ) );
	}

	// -----------------------------------------------------------------------
	// price_resource_pool_change: a `raised_by` pool never charges XP to raise
	// -----------------------------------------------------------------------

	public function test_raising_a_raised_by_pool_costs_no_xp(): void {
		$definition = self::definition( [
			'pools' => [
				[
					'name' => 'Mercy', 'value_type' => 'integer', 'default_start' => 0, 'max' => 10,
					'raised_by' => [ 'from' => 'hunter-resources.Conviction', 'temporary' => 10 ],
				],
			],
		] );
		$sheet = [ 'hunter-virtues' => [ 'Mercy' => 2 ] ];

		$cost = Cost_Engine::price_resource_pool_change( $sheet, $definition, 'hunter-virtues', [ 'values' => [ 'Mercy' => 3 ] ] );

		$this->assertSame( 0, $cost );
	}

	public function test_an_ordinary_pool_without_raised_by_still_charges_xp(): void {
		$definition = self::definition( [
			'pools' => [
				[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 2, 'max' => 10, 'cost_per_dot' => 3 ],
			],
		] );
		$sheet = [ 'hunter-resources' => [ 'Willpower' => 2 ] ];

		$cost = Cost_Engine::price_resource_pool_change( $sheet, $definition, 'hunter-resources', [ 'values' => [ 'Willpower' => 3 ] ] );

		$this->assertSame( 3, $cost );
	}

	public function test_non_sequential_power_prices_a_flat_delta(): void {
		$definition = self::definition(
			[
				'sequential' => false,
				'powers' => [
					[
						'name' => 'Fixed Power',
						'levels' => [
							[ 'level' => 1, 'cost' => '5' ],
							[ 'level' => 2, 'cost' => '9' ],
						],
					],
				],
			]
		);
		$sheet = [ 'gifts' => [ [ 'name' => 'Fixed Power', 'level' => 1 ] ] ];
		$change_data = [ 'block_slug' => 'gifts', 'trait' => [ 'name' => 'Fixed Power', 'level' => 2 ] ];

		$this->assertSame(
			4,
			Cost_Engine::price_tiered_power_change( $sheet, $definition, 'modify_trait', $change_data, true )
		);
	}

	public function test_power_not_found_in_block_defaults_to_zero_and_does_not_crash(): void {
		$definition = $this->sequential_power_block();
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Nonexistent Power', 'level' => 1 ] ];

		$this->assertSame(
			0,
			Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $change_data, true )
		);
	}

	public function test_level_with_no_cost_field_at_all_defaults_to_zero(): void {
		$definition = self::definition(
			[
				'sequential' => true,
				'powers'     => [ [ 'name' => 'Gift', 'levels' => [ [ 'level' => 1 ] ] ] ],
			]
		);
		$change_data = [ 'block_slug' => 'gifts', 'trait' => [ 'name' => 'Gift', 'level' => 1 ] ];

		$this->assertSame(
			0,
			Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $change_data, true )
		);
	}

	// -----------------------------------------------------------------------
	// price_tiered_power_change
	// -----------------------------------------------------------------------

	private function elder_power_block( array $levels ): object {
		return self::definition( [
			'sequential' => true,
			'_meta' => [ 'out_of_type' => [ 'elder' => '+2', 'master' => '+2', 'ascended' => '+2', 'methuselah' => '+2' ] ],
			'powers' => [
				[ 'name' => 'Celerity', 'levels' => $levels ],
			],
		] );
	}

	public function test_elder_pick_with_an_explicit_cost_prices_that_cost(): void {
		$definition = $this->elder_power_block( [
			[ 'power_name' => 'Precision', 'tier' => 'elder', 'cost' => '12' ],
		] );
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Precision' ] ];

		$this->assertSame(
			12,
			Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $change_data, true )
		);
	}

	public function test_elder_pick_with_no_explicit_cost_falls_back_to_the_tier_ladder(): void {
		$definition = $this->elder_power_block( [
			[ 'power_name' => 'Projectile', 'tier' => 'elder' ],
		] );
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Projectile' ] ];

		$this->assertSame(
			12,
			Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $change_data, true )
		);
	}

	/** @dataProvider tierLadderProvider */
	public function test_tier_ladder_fallback_prices_each_real_tier( string $tier, int $expected_cost ): void {
		$definition = $this->elder_power_block( [
			[ 'power_name' => 'Untitled Power', 'tier' => $tier ],
		] );
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Untitled Power' ] ];

		$this->assertSame(
			$expected_cost,
			Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $change_data, true )
		);
	}

	/**
	 * Tier costs for innate through master match priced met-mechanics.csv rows; ascended and methuselah continue the
	 * +3-per-tier progression.
	 */
	public function tierLadderProvider(): array {
		return [
			'innate'       => [ 'innate', 0 ],
			'basic'        => [ 'basic', 3 ],
			'intermediate' => [ 'intermediate', 6 ],
			'advanced'     => [ 'advanced', 9 ],
			'elder'        => [ 'elder', 12 ],
			'master'       => [ 'master', 15 ],
			'ascended'     => [ 'ascended', 18 ],
			'methuselah'   => [ 'methuselah', 21 ],
		];
	}

	public function test_removing_an_elder_pick_refunds_its_tier_cost(): void {
		$definition = $this->elder_power_block( [
			[ 'power_name' => 'Precision', 'tier' => 'elder', 'cost' => '12' ],
		] );
		$sheet       = [ 'disciplines' => [ [ 'name' => 'Celerity', 'power_name' => 'Precision' ] ] ];
		// A removal must name which specific pick is leaving.
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Precision' ] ];

		$this->assertSame(
			-12,
			Cost_Engine::price_tiered_power_change( $sheet, $definition, 'remove_trait', $change_data, true )
		);
	}

	/**
	 * A family can hold several distinct Elder-and-above picks at once.
	 */
	public function test_removing_one_elder_pick_does_not_affect_a_sibling_pick_in_the_same_family(): void {
		$definition = $this->elder_power_block( [
			[ 'power_name' => 'Precision', 'tier' => 'elder', 'cost' => '12' ],
			[ 'power_name' => 'Jaws of the Dragon', 'tier' => 'master', 'cost' => '15' ],
		] );
		$sheet = [ 'disciplines' => [
			[ 'name' => 'Celerity', 'power_name' => 'Precision' ],
			[ 'name' => 'Celerity', 'power_name' => 'Jaws of the Dragon' ],
		] ];

		$remove_change = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Precision' ] ];
		$this->assertSame(
			-12,
			Cost_Engine::price_tiered_power_change( $sheet, $definition, 'remove_trait', $remove_change, true )
		);
	}

	public function test_buying_a_second_elder_pick_in_the_same_family_prices_its_own_full_cost(): void {
		$definition = $this->elder_power_block( [
			[ 'power_name' => 'Precision', 'tier' => 'elder', 'cost' => '12' ],
			[ 'power_name' => 'Jaws of the Dragon', 'tier' => 'master', 'cost' => '15' ],
		] );
		$sheet       = [ 'disciplines' => [ [ 'name' => 'Celerity', 'power_name' => 'Precision' ] ] ];
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Jaws of the Dragon' ] ];

		// Not 3 (a "swap" delta).
		$this->assertSame(
			15,
			Cost_Engine::price_tiered_power_change( $sheet, $definition, 'add_trait', $change_data, true )
		);
	}

	public function test_editing_metadata_on_an_already_held_elder_pick_prices_as_zero(): void {
		$definition = $this->elder_power_block( [
			[ 'power_name' => 'Precision', 'tier' => 'elder', 'cost' => '12' ],
		] );
		$sheet       = [ 'disciplines' => [ [ 'name' => 'Celerity', 'power_name' => 'Precision' ] ] ];
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Precision', 'tradition' => 'Necromancy' ] ];

		$this->assertSame(
			0,
			Cost_Engine::price_tiered_power_change( $sheet, $definition, 'modify_trait', $change_data, true )
		);
	}

	public function test_an_elder_pick_out_of_clan_adds_the_modifier_once_not_per_step(): void {
		$definition = $this->elder_power_block( [
			[ 'power_name' => 'Precision', 'tier' => 'elder', 'cost' => '12' ],
		] );
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Precision' ] ];

		// A flat per-pick transaction, unlike a numbered ladder's summed per-step modifier.
		$this->assertSame(
			14,
			Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $change_data, false )
		);
	}

	public function test_a_numbered_pick_is_unaffected_by_the_elder_pricing_path(): void {
		$definition = $this->sequential_power_block();
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'level' => 1 ] ];

		$this->assertSame(
			3,
			Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $change_data, true )
		);
	}
}
