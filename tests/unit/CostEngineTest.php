<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Cost_Engine;
use PHPUnit\Framework\TestCase;

/**
 * Pure pricing logic (Step 3f). `cost_for_change()`/`is_in_type()` themselves are thin
 * `$wpdb`-touching wrappers around the functions tested here directly - matching this
 * project's established fetch/decision split (see `TemplateResolveTest` for
 * `Template::resolve_from_rows()`).
 *
 * @see BE_PROCESS/workflow-0.4.md Step 3
 * @see BE_PROCESS/DECISIONLOG.md Decision 025 (range cost pricing)
 */
class CostEngineTest extends TestCase {

	/** json_decode(json_encode()) guarantees real nested-stdClass shape, matching how
	 * Schema_Block::decode_definition() actually decodes - not a hand-rolled shortcut. */
	private static function definition( array $data ) {
		return json_decode( json_encode( $data ) );
	}

	// -----------------------------------------------------------------------
	// price_item_cost / parse_cost_rule (Decision 025)
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
		// Flaw of Note is priced at 2; going from count 1 to count 3 should charge for
		// the 2 NEW units, not re-charge for the one already held.
		$change_data = [ 'block_slug' => 'merits', 'trait' => [ 'name' => 'Flaw of Note', 'count' => 3 ] ];

		$this->assertSame(
			4,
			Cost_Engine::price_trait_list_change( $sheet, $definition, 'merits', 'modify_trait', $change_data )
		);
	}

	public function test_negative_list_inverts_the_sign(): void {
		$definition = $this->trait_list_block( [ 'negative' => true ] );
		$change_data = [ 'block_slug' => 'flaws', 'trait' => [ 'name' => 'Flaw of Note', 'count' => 1 ] ];

		// Adding a flaw GRANTS points - a negative cost even though the catalog price is
		// positive 2 (Decision 012 / GV-SOURCEMAP.md negative-list arithmetic).
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
		// Decision 014: a zero-cost change (e.g. a free item) must still flow through
		// normal submit/approval - Cost_Engine's job is only to price it correctly as 0,
		// never to signal "no cost" in a way that could be mistaken for "no change" and
		// skip approval. Approval-level gating itself is Change_Engine's responsibility,
		// verified independently of cost.
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
					'out_of_type_cost_modifier' => 1,
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

		// Level 2 -> 4: the level-3 step (5) plus the level-4 step (6) = 11, NOT one flat
		// level-4 price.
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

	/**
	 * Regression guard for defect D16: reads the field the seeder actually writes
	 * (`cost`, free text), not a `base_cost` number that nothing has ever produced. 3 of
	 * 468 real vampire-disciplines levels genuinely have no cost at all - must default to
	 * 0, not crash.
	 */
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
	// price_tiered_power_change - Elder-and-above picks (Decision 037,
	// BE_PROCESS/0.99.2-workflow.md "Cost_Engine cannot price an Elder-tier
	// purchase"). Matched by power_name within the tier, not by numbered level -
	// real met-mechanics.csv data confirms multiple distinct named powers can
	// share one tier (e.g. Celerity's own Basic tier: Alacrity and Swiftness
	// both cost 3), the same is true above the numbered ladder.
	// -----------------------------------------------------------------------

	private function elder_power_block( array $levels ): object {
		return self::definition( [
			'sequential' => true,
			'out_of_type_cost_modifier' => 2,
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

	/**
	 * Real met-mechanics.csv rows: lNum 6 ("Elder") prices at 12 XP with no
	 * per-power cost of its own on many entries (most Elder+ catalog rows are
	 * NPC-only or otherwise uncosted in the source) - confirmed 2026-09-12.
	 */
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
	 * innate/basic/intermediate/advanced/elder/master are each confirmed directly
	 * against a real priced met-mechanics.csv row. ascended/methuselah continue the
	 * same +3-per-tier progression but have no priced catalog example to confirm
	 * independently - both real MET convention and the project owner's own
	 * clarification (2026-09-12) place them there.
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
		// A removal must name which specific pick is leaving - a family can hold several
		// at once, so `{name}` alone (correct for the numbered-ladder path) is ambiguous here.
		$change_data = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Precision' ] ];

		$this->assertSame(
			-12,
			Cost_Engine::price_tiered_power_change( $sheet, $definition, 'remove_trait', $change_data, true )
		);
	}

	/**
	 * A family can hold several distinct Elder-and-above picks at once
	 * (0.99.2-workflow.md: "you can have multiple powers at those levels") - removing
	 * one refunds only that one, leaving a sibling pick under the same family untouched.
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

		// Not 3 (a "swap" delta) - Precision is untouched and still held, so this is a
		// genuinely new, independent purchase at its own full 15 XP.
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

		// The pick itself isn't being bought or sold - only a tradition tag is changing.
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

	// -----------------------------------------------------------------------
	// is_in_type_pure
	// -----------------------------------------------------------------------

	public function test_in_clan_discipline_resolves_true(): void {
		$identity_definition = self::definition(
			[ 'clan_disciplines' => [ 'Brujah' => [ 'Celerity', 'Potence', 'Presence' ] ] ]
		);
		$sheet = [ 'identity' => [ 'Clan' => 'Brujah' ] ];

		$this->assertTrue(
			Cost_Engine::is_in_type_pure( $sheet, 'identity', 'Clan', 'Celerity', $identity_definition )
		);
	}

	public function test_out_of_clan_discipline_resolves_false(): void {
		$identity_definition = self::definition(
			[ 'clan_disciplines' => [ 'Brujah' => [ 'Celerity', 'Potence', 'Presence' ] ] ]
		);
		$sheet = [ 'identity' => [ 'Clan' => 'Brujah' ] ];

		$this->assertFalse(
			Cost_Engine::is_in_type_pure( $sheet, 'identity', 'Clan', 'Dominate', $identity_definition )
		);
	}

	public function test_caitiff_prices_against_chosen_in_clan_not_a_predefined_list(): void {
		$identity_definition = self::definition(
			[ 'clan_disciplines' => [ 'Brujah' => [ 'Celerity', 'Potence', 'Presence' ] ] ]
		);
		$sheet = [
			'identity' => [
				'Clan'           => 'Caitiff',
				'chosen_in_clan' => [ 'Auspex', 'Fortitude', 'Obfuscate' ],
			],
		];

		$this->assertTrue( Cost_Engine::is_in_type_pure( $sheet, 'identity', 'Clan', 'Auspex', $identity_definition ) );
		$this->assertFalse( Cost_Engine::is_in_type_pure( $sheet, 'identity', 'Clan', 'Celerity', $identity_definition ) );
	}

	public function test_missing_identity_value_defaults_to_in_type(): void {
		$identity_definition = self::definition( [ 'clan_disciplines' => [] ] );
		$this->assertTrue( Cost_Engine::is_in_type_pure( [], 'identity', 'Clan', 'Celerity', $identity_definition ) );
	}
}
