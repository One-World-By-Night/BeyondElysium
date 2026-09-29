<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Creation_Tally;
use PHPUnit\Framework\TestCase;

/**
 * The creation tally against a synthetic creature type covering every step kind, decoupled from any real genre's data.
 */
class CreationTallyTest extends TestCase {

	private static function obj( array $data ): object {
		return json_decode( (string) json_encode( $data ) );
	}

	/**
	 * A stack whose sections are: identity (Clan), Attributes x3 (prioritized), Abilities (budget), Disciplines
	 * (tiered_power budget with an in-type filter and quotas), Merits (atomic, budget-free), Flaws (negative, earned),
	 * Willpower (resource_pool, start+limit), Torment (resource_pool, buy_down).
	 */
	private static function stack( array $creation_rules ): object {
		return self::obj( [
			'slug'             => 'test-stack',
			'stack_definition' => [
				'sections' => [
					[ 'block_slug' => 'test-identity', 'label' => 'Identity', 'display_order' => 1 ],
					[ 'block_slug' => 'test-physical', 'label' => 'Physical', 'display_order' => 10 ],
					[ 'block_slug' => 'test-social', 'label' => 'Social', 'display_order' => 11 ],
					[ 'block_slug' => 'test-mental', 'label' => 'Mental', 'display_order' => 12 ],
					[ 'block_slug' => 'test-abilities', 'label' => 'Abilities', 'display_order' => 20 ],
					[
						'block_slug' => 'test-disciplines', 'label' => 'Disciplines', 'display_order' => 30,
						'in_type'    => [ [ 'kind' => 'names', 'values' => [ 'map' => 'test-identity.clan_disciplines', 'by' => [ 'Clan' ] ] ] ],
					],
					[ 'block_slug' => 'test-merits', 'label' => 'Merits', 'display_order' => 40 ],
					[ 'block_slug' => 'test-flaws', 'label' => 'Flaws', 'display_order' => 41 ],
					[ 'block_slug' => 'test-pools', 'label' => 'Resources', 'display_order' => 50 ],
				],
			],
			'creation_rules'   => $creation_rules,
		] );
	}

	private static function blocks(): array {
		return [
			'test-identity'    => self::obj( [ 'section_type' => 'identity_field', 'definition' => [
				'fields'           => [ [ 'name' => 'Clan', 'field_type' => 'select', 'options' => [ 'Alpha', 'Beta' ] ] ],
				'clan_disciplines' => [ 'Alpha' => [ 'Might' ], 'Beta' => [ 'Guile' ] ],
			] ] ),
			'test-physical'    => self::obj( [ 'section_type' => 'trait_list', 'definition' => [ 'items' => [ [ 'name' => 'Strong', 'cost' => '1' ] ] ] ] ),
			'test-social'      => self::obj( [ 'section_type' => 'trait_list', 'definition' => [ 'items' => [ [ 'name' => 'Charming', 'cost' => '1' ] ] ] ] ),
			'test-mental'      => self::obj( [ 'section_type' => 'trait_list', 'definition' => [ 'items' => [ [ 'name' => 'Sharp', 'cost' => '1' ] ] ] ] ),
			'test-abilities'   => self::obj( [ 'section_type' => 'trait_list', 'definition' => [ 'items' => [
				[ 'name' => 'Occult', 'cost' => '2' ],
				[ 'name' => 'Melee', 'cost' => '2' ],
			] ] ] ),
			'test-disciplines' => self::obj( [ 'section_type' => 'tiered_power', 'definition' => [
				'_meta'      => [ 'ranks' => [ 'innate', 'basic', 'intermediate' ], 'ladder' => [ 'basic' => 2, 'intermediate' => 2 ], 'costs' => [ 'innate' => 1, 'basic' => 3, 'intermediate' => 6 ], 'out_of_type' => [ 'basic' => '+1', 'intermediate' => '+1' ] ],
				'sequential' => true,
				'powers'     => [
					[ 'name' => 'Might', 'levels' => [ [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Might 1' ], [ 'level' => 2, 'tier' => 'basic', 'power_name' => 'Might 2' ], [ 'level' => 3, 'tier' => 'intermediate', 'power_name' => 'Might 3' ] ], 'elder' => [ 'innate' => [ [ 'level' => null, 'tier' => 'innate', 'power_name' => 'Might Sense', 'cost' => '1' ] ] ] ],
					[ 'name' => 'Guile', 'levels' => [ [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Guile 1' ], [ 'level' => 2, 'tier' => 'basic', 'power_name' => 'Guile 2' ] ] ],
					[ 'name' => 'Craft', 'levels' => [ [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Craft 1' ] ] ],
				],
			] ] ),
			'test-merits'      => self::obj( [ 'section_type' => 'trait_list', 'definition' => [ 'atomic' => true, 'items' => [
				[ 'name' => 'Iron Will', 'cost' => '3' ],
				[ 'name' => 'Contacts', 'cost' => '1-5' ],
			] ] ] ),
			'test-flaws'       => self::obj( [ 'section_type' => 'trait_list', 'definition' => [ 'negative' => true, 'atomic' => true, 'items' => [
				[ 'name' => 'Curiosity', 'cost' => '2' ],
				[ 'name' => 'Vengeful', 'cost' => '2' ],
			] ] ] ),
			'test-pools'       => self::obj( [ 'section_type' => 'resource_pool', 'definition' => [ 'pools' => [
				[ 'name' => 'Willpower', 'default_start' => 3, 'cost_per_dot' => 3 ],
				[ 'name' => 'Torment', 'default_start' => 4, 'cost_per_dot' => 5, 'buy_down' => true ],
			] ] ] ),
		];
	}

	// -------------------------------------------------------------------------
	// prioritized
	// -------------------------------------------------------------------------

	public function test_prioritized_assigns_the_largest_amount_to_the_section_holding_most(): void {
		$stack = self::stack( [ 'steps' => [
			[ 'kind' => 'prioritized', 'label' => 'Attributes', 'sections' => [ 'test-physical', 'test-social', 'test-mental' ], 'amounts' => [ 7, 5, 3 ] ],
		] ] );
		$sheet = [
			'test-physical' => [ [ 'name' => 'Strong', 'count' => 3 ] ],
			'test-social'   => [ [ 'name' => 'Charming', 'count' => 7 ] ],
			'test-mental'   => [ [ 'name' => 'Sharp', 'count' => 5 ] ],
		];

		$tally = Creation_Tally::for_stack( $stack, self::blocks(), $sheet );
		$sections = array_column( $tally['steps'][0]['sections'], null, 'section' );

		$this->assertSame( 7, $sections['test-social']['allowed'], 'most dots gets the largest amount' );
		$this->assertSame( 5, $sections['test-mental']['allowed'] );
		$this->assertSame( 3, $sections['test-physical']['allowed'] );
		$this->assertFalse( $sections['test-social']['over'] );
	}

	public function test_prioritized_flags_a_section_over_its_assigned_amount(): void {
		$stack = self::stack( [ 'steps' => [
			[ 'kind' => 'prioritized', 'sections' => [ 'test-physical', 'test-social' ], 'amounts' => [ 7, 5 ] ],
		] ] );
		$sheet = [ 'test-physical' => [ [ 'name' => 'Strong', 'count' => 10 ] ], 'test-social' => [] ];

		$tally    = Creation_Tally::for_stack( $stack, self::blocks(), $sheet );
		$sections = array_column( $tally['steps'][0]['sections'], null, 'section' );

		$this->assertTrue( $sections['test-physical']['over'] );
		$this->assertSame( 10, $sections['test-physical']['used'] );
	}

	public function test_prioritized_only_covers_dots_up_to_the_allowed_amount(): void {
		$stack = self::stack( [ 'steps' => [
			[ 'kind' => 'prioritized', 'sections' => [ 'test-physical' ], 'amounts' => [ 3 ] ],
		] ] );
		$sheet = [ 'test-physical' => [ [ 'name' => 'Strong', 'count' => 5 ] ] ];

		$tally = Creation_Tally::for_stack( $stack, self::blocks(), $sheet );

		$this->assertSame( 2, $tally['xp']['needed'], 'the 2 dots beyond the allowed 3 still price, at 1 each' );
	}

	// -------------------------------------------------------------------------
	// budget: filter, tier, quotas, in-clan
	// -------------------------------------------------------------------------

	public function test_budget_covers_in_type_basic_units_and_reports_quotas(): void {
		$stack = self::stack( [ 'steps' => [
			[
				'kind' => 'budget', 'label' => 'Disciplines', 'section' => 'test-disciplines', 'count' => 2,
				'filter' => [ 'in_type' => true, 'tier' => 'basic' ],
				'quotas' => [ [ 'label' => 'At least one Might', 'test' => [ 'kind' => 'names', 'values' => [ 'constant' => [ 'Might' ] ] ], 'min' => 1 ] ],
			],
		] ] );
		$sheet = [ 'test-identity' => [ 'Clan' => 'Alpha' ], 'test-disciplines' => [ [ 'name' => 'Might', 'level' => 1 ] ] ];

		$tally = Creation_Tally::for_stack( $stack, self::blocks(), $sheet );
		$report = $tally['steps'][0];

		$this->assertSame( 1, $report['used'] );
		$this->assertSame( 2, $report['allowed'] );
		$this->assertFalse( $report['over'] );
		$this->assertTrue( $report['quotas'][0]['ok'] );
		$this->assertSame( 0, $tally['xp']['needed'], 'the one in-clan basic rung is fully covered by budget' );
	}

	public function test_budget_excludes_out_of_type_and_above_tier_units_from_the_count(): void {
		$stack = self::stack( [ 'steps' => [
			[ 'kind' => 'budget', 'section' => 'test-disciplines', 'count' => 3, 'filter' => [ 'in_type' => true, 'tier' => 'basic' ] ],
		] ] );
		$sheet = [
			'test-identity'    => [ 'Clan' => 'Alpha' ],
			'test-disciplines' => [ [ 'name' => 'Might', 'level' => 3 ], [ 'name' => 'Guile', 'level' => 1 ] ],
		];

		$tally  = Creation_Tally::for_stack( $stack, self::blocks(), $sheet );
		$report = $tally['steps'][0];

		// Might's own rungs 1-2 are in-clan basic (counted); rung 3 is intermediate (excluded by tier).
		// Guile is out-of-clan for Alpha (excluded by in_type).
		$this->assertSame( 2, $report['used'] );
		$this->assertGreaterThan( 0, $tally['xp']['needed'], 'the uncovered rung/family still prices' );
	}

	public function test_budget_min_tier_excludes_a_floor_tier_bought_a_separate_way(): void {
		$stack = self::stack( [ 'steps' => [
			[ 'kind' => 'budget', 'section' => 'test-disciplines', 'count' => 5, 'filter' => [ 'tier' => 'intermediate', 'min_tier' => 'basic' ] ],
		] ] );
		$sheet = [
			'test-identity'    => [ 'Clan' => 'Alpha' ],
			'test-disciplines' => [ [ 'name' => 'Might', 'level' => 1 ], [ 'name' => 'Might', 'power_name' => 'Might Sense' ] ],
		];

		$tally  = Creation_Tally::for_stack( $stack, self::blocks(), $sheet );
		$report = $tally['steps'][0];

		$this->assertSame( 1, $report['used'], 'the basic rung is covered; the innate pick is below the floor' );
		$this->assertGreaterThan( 0, $tally['xp']['needed'], 'the innate pick still prices, uncovered by this budget' );
	}

	public function test_budget_covers_only_up_to_its_count_and_prices_the_rest(): void {
		$stack = self::stack( [ 'steps' => [
			[ 'kind' => 'budget', 'section' => 'test-disciplines', 'count' => 1, 'filter' => [ 'in_type' => true, 'tier' => 'basic' ] ],
		] ] );
		$sheet = [ 'test-identity' => [ 'Clan' => 'Alpha' ], 'test-disciplines' => [ [ 'name' => 'Might', 'level' => 1 ] ] ];

		// Might at level 1 is one eligible unit; asking for count 2 against only 1 held leaves nothing uncovered -
		// so instead hold two separate basic units and cap the budget at 1.
		$sheet['test-disciplines'][] = [ 'name' => 'Craft', 'level' => 1 ];
		$blocks = self::blocks();
		$blocks['test-identity']->definition->clan_disciplines->Alpha = [ 'Might', 'Craft' ];

		$tally = Creation_Tally::for_stack( $stack, $blocks, $sheet );

		$this->assertSame( 2, $tally['steps'][0]['used'] );
		$this->assertTrue( $tally['steps'][0]['over'] );
		$this->assertSame( 3, $tally['xp']['needed'], 'one of the two basic rungs is covered by the budget of 1; the other (cost 3) is not' );
	}

	public function test_a_later_budget_step_does_not_recount_a_unit_an_earlier_one_already_covered(): void {
		$stack = self::stack( [ 'steps' => [
			[ 'kind' => 'budget', 'label' => 'first', 'section' => 'test-disciplines', 'count' => 5, 'filter' => [ 'in_type' => true ] ],
			[ 'kind' => 'budget', 'label' => 'second', 'section' => 'test-disciplines', 'count' => 5, 'filter' => [ 'in_type' => true ] ],
		] ] );
		$sheet = [ 'test-identity' => [ 'Clan' => 'Alpha' ], 'test-disciplines' => [ [ 'name' => 'Might', 'level' => 1 ] ] ];

		$tally = Creation_Tally::for_stack( $stack, self::blocks(), $sheet );

		$this->assertSame( 1, $tally['steps'][0]['used'] );
		$this->assertSame( 0, $tally['steps'][1]['used'], 'already covered by the first step' );
	}

	public function test_a_quota_short_of_its_minimum_is_not_ok(): void {
		$stack = self::stack( [ 'steps' => [
			[
				'kind' => 'budget', 'section' => 'test-disciplines', 'count' => 5, 'filter' => [ 'in_type' => true ],
				'quotas' => [ [ 'label' => 'At least one Guile', 'test' => [ 'kind' => 'names', 'values' => [ 'constant' => [ 'Guile' ] ] ], 'min' => 1 ] ],
			],
		] ] );
		$sheet = [ 'test-identity' => [ 'Clan' => 'Alpha' ], 'test-disciplines' => [ [ 'name' => 'Might', 'level' => 1 ] ] ];

		$tally = Creation_Tally::for_stack( $stack, self::blocks(), $sheet );

		$this->assertSame( 0, $tally['steps'][0]['quotas'][0]['met'] );
		$this->assertFalse( $tally['steps'][0]['quotas'][0]['ok'] );
	}

	public function test_a_when_clause_switches_between_alternate_budget_steps(): void {
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'budget', 'label' => 'in-clan', 'section' => 'test-disciplines', 'count' => 5, 'filter' => [ 'in_type' => true ], 'when' => [ 'field' => 'test-identity.Clan', 'not' => [ 'Gamma' ] ] ],
			[ 'kind' => 'budget', 'label' => 'any', 'section' => 'test-disciplines', 'count' => 5, 'when' => [ 'field' => 'test-identity.Clan', 'is' => [ 'Gamma' ] ] ],
		] ];
		$sheet = [ 'test-identity' => [ 'Clan' => 'Alpha' ] ];

		$tally = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), $sheet );

		$this->assertTrue( $tally['steps'][0]['applies'] );
		$this->assertFalse( $tally['steps'][1]['applies'] );
	}

	public function test_a_when_list_requires_every_test_in_it_to_hold(): void {
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'start', 'target' => 'a.X', 'value' => 1, 'when' => [
				[ 'field' => 'test-identity.Clan', 'is' => [ 'Alpha' ] ],
				[ 'field' => 'test-identity.Clan', 'is' => [ 'Beta' ] ],
			] ],
		] ];
		$sheet = [ 'test-identity' => [ 'Clan' => 'Alpha' ] ];

		$tally = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), $sheet );
		$this->assertFalse( $tally['steps'][0]['applies'], 'Alpha is not Beta, so not every test in the list holds' );
	}

	// -------------------------------------------------------------------------
	// free / earned
	// -------------------------------------------------------------------------

	public function test_free_pays_uncovered_units_at_their_sections_rate_and_earned_tops_it_up(): void {
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'earned', 'pool' => 'Free Traits', 'sources' => [ [ 'section' => 'test-flaws', 'rate' => 'value', 'max' => 7 ] ] ],
			[ 'kind' => 'free', 'pool' => 'Free Traits', 'points' => 2, 'rates' => [ 'test-abilities' => 1 ] ],
		] ];
		$sheet = [
			'test-flaws'     => [ [ 'name' => 'Curiosity', 'count' => 1 ] ],
			'test-abilities' => [ [ 'name' => 'Occult', 'count' => 3 ] ],
		];

		$tally = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), $sheet );

		$this->assertSame( 2, $tally['pools']['Free Traits']['earned'], "Curiosity's own 2 points" );
		$this->assertSame( 2, $tally['pools']['Free Traits']['own'] );
		$this->assertSame( 3, $tally['pools']['Free Traits']['spent'], '3 of the 3 Occult dots, at 1 each, covered by 4 available points' );
		$this->assertSame( 1, $tally['pools']['Free Traits']['left'] );
		$this->assertSame( 0, $tally['xp']['needed'] );
	}

	public function test_earned_source_and_step_caps_apply(): void {
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'earned', 'pool' => 'Free Traits', 'sources' => [
				[ 'section' => 'test-flaws', 'rate' => 'value', 'max' => 2 ],
			], 'max' => 1 ],
		] ];
		$sheet = [ 'test-flaws' => [ [ 'name' => 'Curiosity', 'count' => 1 ], [ 'name' => 'Vengeful', 'count' => 1 ] ] ];

		$tally = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), $sheet );

		$this->assertSame( 1, $tally['pools']['Free Traits']['earned'], "capped by the step's own max of 1, below the source's 4 points and its own max of 2" );
	}

	public function test_earned_caps_each_source_independently_before_summing(): void {
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'earned', 'pool' => 'Free Traits', 'sources' => [
				[ 'section' => 'test-flaws', 'rate' => 'value', 'max' => 2 ],
				[ 'section' => 'test-abilities', 'rate' => 1, 'max' => 10 ],
			] ],
		] ];
		$sheet = [
			'test-flaws'     => [ [ 'name' => 'Curiosity', 'count' => 1 ], [ 'name' => 'Vengeful', 'count' => 1 ] ],
			'test-abilities' => [ [ 'name' => 'Occult', 'count' => 3 ] ],
		];

		$tally = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), $sheet );

		$this->assertSame( 5, $tally['pools']['Free Traits']['earned'], "the flaws' own 4 points capped at 2, plus 3 Occult dots at 1 each" );
	}

	public function test_free_never_spends_twice_on_a_unit_a_budget_already_covered(): void {
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'budget', 'section' => 'test-abilities', 'count' => 5 ],
			[ 'kind' => 'free', 'pool' => 'Free Traits', 'points' => 10, 'rates' => [ 'test-abilities' => 1 ] ],
		] ];
		$sheet = [ 'test-abilities' => [ [ 'name' => 'Occult', 'count' => 2 ] ] ];

		$tally = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), $sheet );

		$this->assertSame( 0, $tally['pools']['Free Traits']['spent'], 'the budget already covered both dots' );
	}

	public function test_a_unit_the_pool_cannot_fully_cover_goes_to_xp_and_the_pool_keeps_trying(): void {
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'free', 'pool' => 'Free Traits', 'points' => 1, 'rates' => [ 'test-merits' => 'value', 'test-abilities' => 1 ] ],
		] ];
		$sheet = [
			'test-merits'    => [ [ 'name' => 'Iron Will', 'count' => 1 ] ], // costs 3, pool only has 1 - left uncovered
			'test-abilities' => [ [ 'name' => 'Occult', 'count' => 1 ] ], // costs 1 - the pool can still cover this
		];

		$tally = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), $sheet );

		$this->assertSame( 1, $tally['pools']['Free Traits']['spent'], 'the pool paid for Occult, not Iron Will' );
		$this->assertSame( 3, $tally['xp']['needed'], "Iron Will's own full price, unpaid by the pool" );
	}

	// -------------------------------------------------------------------------
	// start
	// -------------------------------------------------------------------------

	public function test_start_value_lookup_and_formulas(): void {
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'start', 'target' => 'test-pools.Willpower', 'value' => 5 ],
			[ 'kind' => 'start', 'target' => 'test-pools.Torment', 'lookup' => [ 'map' => 'test-identity.torment_by_clan', 'by' => [ 'Clan' ] ] ],
		] ];
		$blocks = self::blocks();
		$blocks['test-identity']->definition->torment_by_clan = self::obj( [ 'Alpha' => 3, 'Beta' => 4 ] );
		$sheet = [ 'test-identity' => [ 'Clan' => 'Beta' ] ];

		$tally = Creation_Tally::for_stack( self::stack( $creation_rules ), $blocks, $sheet );

		$this->assertSame( 5.0, $tally['steps'][0]['value'] );
		$this->assertSame( 4.0, $tally['steps'][1]['value'], "Beta's own looked-up Torment start" );
	}

	public function test_average_up_and_sum_top_two_and_equal_formulas(): void {
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'start', 'target' => 'a.X', 'value' => 3 ],
			[ 'kind' => 'start', 'target' => 'a.Y', 'value' => 4 ],
			[ 'kind' => 'start', 'target' => 'a.Z', 'value' => 2 ],
			[ 'kind' => 'start', 'target' => 'a.AverageUp', 'formula' => 'average_up', 'of' => [ 'a.X', 'a.Y' ] ],
			[ 'kind' => 'start', 'target' => 'a.SumTopTwo', 'formula' => 'sum_top_two', 'of' => [ 'a.X', 'a.Y', 'a.Z' ] ],
			[ 'kind' => 'start', 'target' => 'a.Equal', 'formula' => 'equal', 'of' => [ 'a.X' ] ],
		] ];

		$tally = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), [] );
		$values = array_column( $tally['steps'], 'value', 'target' );

		$this->assertSame( 4.0, $values['a.AverageUp'], 'ceil(3.5)' );
		$this->assertSame( 7.0, $values['a.SumTopTwo'], '4+3, the two highest of 3/4/2' );
		$this->assertSame( 3.0, $values['a.Equal'] );
	}

	// -------------------------------------------------------------------------
	// grant
	// -------------------------------------------------------------------------

	public function test_grant_reports_a_missing_entry_and_not_one_already_held(): void {
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'grant', 'section' => 'test-disciplines', 'entries' => [ [ 'name' => 'Craft', 'level' => 1 ] ] ],
		] ];

		$missing = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), [] )['grants_missing'];
		$this->assertCount( 1, $missing );
		$this->assertSame( 'Craft', $missing[0]['name'] );

		$held = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), [ 'test-disciplines' => [ [ 'name' => 'Craft', 'level' => 1 ] ] ] )['grants_missing'];
		$this->assertSame( [], $held );
	}

	public function test_grant_from_a_map_resolves_the_named_entry(): void {
		$blocks = self::blocks();
		$blocks['test-identity']->definition->specialty = self::obj( [ 'Alpha' => 'Might' ] );
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'grant', 'section' => 'test-disciplines', 'from' => [ 'map' => 'test-identity.specialty', 'by' => [ 'Clan' ] ], 'level' => 1 ],
		] ];
		$sheet = [ 'test-identity' => [ 'Clan' => 'Alpha' ] ];

		$missing = Creation_Tally::for_stack( self::stack( $creation_rules ), $blocks, $sheet )['grants_missing'];
		$this->assertSame( 'Might', $missing[0]['name'] );
	}

	// -------------------------------------------------------------------------
	// limit
	// -------------------------------------------------------------------------

	public function test_limit_flags_a_section_over_its_max_points(): void {
		$creation_rules = [ 'steps' => [ [ 'kind' => 'limit', 'section' => 'test-merits', 'max_points' => 7 ] ] ];
		$sheet = [ 'test-merits' => [ [ 'name' => 'Iron Will', 'count' => 5 ], [ 'name' => 'Contacts', 'count' => 4 ] ] ];

		$flags = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), $sheet )['limits'];
		$this->assertCount( 1, $flags );
		$this->assertSame( 'max_points', $flags[0]['reason'] );
		$this->assertSame( 9, $flags[0]['value'] );
	}

	public function test_limit_flags_an_entry_over_a_computed_max_rating(): void {
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'limit', 'section' => 'test-physical', 'max_rating' => [ 'base' => 10, 'plus' => 'test-pools.Willpower', 'cap' => 20 ] ],
		] ];
		$sheet = [ 'test-physical' => [ [ 'name' => 'Strong', 'count' => 14 ] ], 'test-pools' => [ 'Willpower' => 3 ] ];

		$flags = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), $sheet )['limits'];
		$this->assertCount( 1, $flags );
		$this->assertSame( 13.0, $flags[0]['max'], '10 base + 3 Willpower' );
	}

	public function test_limit_max_rating_cap_wins_over_the_computed_base_plus_pool(): void {
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'limit', 'section' => 'test-physical', 'max_rating' => [ 'base' => 10, 'plus' => 'test-pools.Willpower', 'cap' => 12 ] ],
		] ];
		$sheet = [ 'test-physical' => [ [ 'name' => 'Strong', 'count' => 13 ] ], 'test-pools' => [ 'Willpower' => 8 ] ];

		$flags = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), $sheet )['limits'];
		$this->assertCount( 1, $flags );
		$this->assertSame( 12.0, $flags[0]['max'], 'the cap of 12 wins over 10+8' );
	}

	public function test_limit_min_rating_flags_a_held_entry_below_it(): void {
		$creation_rules = [ 'steps' => [ [ 'kind' => 'limit', 'section' => 'test-disciplines', 'min_rating' => 1 ] ] ];
		$sheet = [ 'test-disciplines' => [ [ 'name' => 'Might', 'level' => 0 ] ] ];

		$flags = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), $sheet )['limits'];
		$this->assertCount( 1, $flags );
		$this->assertSame( 'min_rating', $flags[0]['reason'] );
	}

	public function test_limit_flags_a_pool_under_its_min_rating(): void {
		$creation_rules = [ 'steps' => [ [ 'kind' => 'limit', 'section' => 'test-pools.Torment', 'min_rating' => 1 ] ] ];

		$flags = Creation_Tally::for_stack( self::stack( $creation_rules ), self::blocks(), [ 'test-pools' => [ 'Torment' => 0 ] ] )['limits'];
		$this->assertCount( 1, $flags );
		$this->assertSame( 'min_rating', $flags[0]['reason'] );
	}

	public function test_limit_ceiling_named_by_a_field_flags_an_entry_above_the_named_one(): void {
		$creation_rules = [ 'steps' => [
			[ 'kind' => 'limit', 'section' => 'test-disciplines', 'ceiling' => [ 'named_by' => 'test-identity.Primary' ] ],
		] ];
		$blocks = self::blocks();
		$blocks['test-identity']->definition->fields[] = self::obj( [ 'name' => 'Primary', 'field_type' => 'text' ] );
		$sheet = [
			'test-identity'    => [ 'Primary' => 'Might' ],
			'test-disciplines' => [ [ 'name' => 'Might', 'level' => 2 ], [ 'name' => 'Guile', 'level' => 3 ] ],
		];

		$flags = Creation_Tally::for_stack( self::stack( $creation_rules ), $blocks, $sheet )['limits'];
		$this->assertCount( 1, $flags );
		$this->assertStringContainsString( 'Guile', $flags[0]['target'] );
	}

	// -------------------------------------------------------------------------
	// buy_down pools and XP
	// -------------------------------------------------------------------------

	public function test_a_buy_down_pool_below_its_start_prices_as_a_purchase(): void {
		$sheet = [ 'test-pools' => [ 'Torment' => 2 ] ];

		$tally = Creation_Tally::for_stack( self::stack( [ 'steps' => [] ] ), self::blocks(), $sheet );
		$this->assertSame( 10, $tally['xp']['needed'], '2 points below the default start of 4, at 5 each' );
	}

	public function test_starting_xp_and_needed_combine_into_left(): void {
		$sheet = [ 'test-abilities' => [ [ 'name' => 'Occult', 'count' => 2 ] ] ];

		$tally = Creation_Tally::for_stack( self::stack( [ 'steps' => [] ] ), self::blocks(), $sheet, '', 10 );
		$this->assertSame( 10, $tally['xp']['starting'] );
		$this->assertSame( 4, $tally['xp']['needed'], '2 Occult dots at 2 each' );
		$this->assertSame( 6, $tally['xp']['left'] );
	}

	public function test_a_creature_type_with_no_creation_rules_document_prices_nothing(): void {
		$sheet = [ 'test-abilities' => [ [ 'name' => 'Occult', 'count' => 2 ] ] ];

		$tally = Creation_Tally::for_stack( self::stack( [] ), self::blocks(), $sheet, '', 10 );
		$this->assertSame( [], $tally['steps'] );
		$this->assertSame( 0, $tally['xp']['needed'], 'no document at all is not the same as an authored empty one' );
		$this->assertSame( 10, $tally['xp']['left'] );
	}
}
