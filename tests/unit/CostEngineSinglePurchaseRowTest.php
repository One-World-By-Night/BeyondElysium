<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Cost_Engine;
use BeyondElysium\Services\Point_Audit;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A row of an atomic trait_list block is one purchase: it is priced once, whatever its count.
 */
class CostEngineSinglePurchaseRowTest extends TestCase {

	private static function definition( array $data ): object {
		return json_decode( json_encode( $data ) );
	}

	private static function merits(): object {
		return self::definition( [
			'atomic' => true,
			'items'  => [
				[ 'name' => 'Iron Will', 'cost' => '3' ],
				[ 'name' => 'Ability Aptitude', 'cost' => '2-4' ],
				[ 'name' => 'Contacts Abroad', 'cost' => '2 or 4' ],
			],
		] );
	}

	private static function flaws(): object {
		return self::definition( [
			'atomic'   => true,
			'negative' => true,
			'items'    => [ [ 'name' => 'Dark Secret', 'cost' => '3' ] ],
		] );
	}

	private static function counted(): object {
		return self::definition( [ 'items' => [ [ 'name' => 'Iron Will', 'cost' => '3' ] ] ] );
	}

	/**
	 * @param array<string,mixed> $trait
	 * @return array<string,mixed>
	 */
	private static function change( array $trait ): array {
		return [ 'block_slug' => 'm', 'trait' => $trait ];
	}

	// --- the point audit -----------------------------------------------------------------

	public function test_a_held_merit_prices_once_at_its_catalog_cost_whatever_its_count(): void {
		$price = Cost_Engine::price_held_trait_list_item( self::merits(), [ 'name' => 'Iron Will', 'count' => 3 ] );

		$this->assertSame( [ 'xp' => 3, 'basis' => 'catalog_cost', 'unpriced_reason' => null ], $price );
	}

	public function test_a_held_flaw_prices_once(): void {
		$price = Cost_Engine::price_held_trait_list_item( self::flaws(), [ 'name' => 'Dark Secret', 'count' => 3 ] );

		$this->assertSame( -3, $price['xp'] );
	}

	public function test_a_count_that_is_one_of_a_range_items_costs_is_the_cost_chosen(): void {
		$price = Cost_Engine::price_held_trait_list_item( self::merits(), [ 'name' => 'Ability Aptitude', 'count' => 3 ] );

		$this->assertSame( [ 'xp' => 3, 'basis' => 'chosen_cost', 'unpriced_reason' => null ], $price );
	}

	public function test_a_count_that_is_one_of_a_set_items_costs_is_the_cost_chosen(): void {
		$price = Cost_Engine::price_held_trait_list_item( self::merits(), [ 'name' => 'Contacts Abroad', 'count' => 4 ] );

		$this->assertSame( [ 'xp' => 4, 'basis' => 'chosen_cost', 'unpriced_reason' => null ], $price );
	}

	public function test_a_count_outside_a_range_items_costs_prices_at_the_floor_once(): void {
		$price = Cost_Engine::price_held_trait_list_item( self::merits(), [ 'name' => 'Ability Aptitude', 'count' => 5 ] );

		$this->assertSame( [ 'xp' => 2, 'basis' => 'rule_floor', 'unpriced_reason' => null ], $price );
	}

	public function test_a_recorded_chosen_cost_wins_over_the_count(): void {
		$price = Cost_Engine::price_held_trait_list_item( self::merits(), [ 'name' => 'Ability Aptitude', 'count' => 4, 'chosen_cost' => 2 ] );

		$this->assertSame( 2, $price['xp'] );
	}

	public function test_a_priced_custom_row_is_charged_its_price_once(): void {
		$price = Cost_Engine::price_held_trait_list_item( self::merits(), [ 'name' => 'Odd Gift', 'count' => 2, 'custom' => true, 'chosen_cost' => 3 ] );

		$this->assertSame( [ 'xp' => 3, 'basis' => 'chosen_cost', 'unpriced_reason' => null ], $price );
	}

	public function test_a_row_on_a_counted_block_still_prices_per_dot(): void {
		$price = Cost_Engine::price_held_trait_list_item( self::counted(), [ 'name' => 'Iron Will', 'count' => 3 ] );

		$this->assertSame( 9, $price['xp'] );
	}

	public function test_an_audit_line_for_a_merit_names_it_without_a_count(): void {
		$method = new ReflectionMethod( Point_Audit::class, 'trait_list_lines' );
		$method->setAccessible( true );
		$lines = $method->invoke(
			null,
			self::merits(),
			[ 'slug' => 'vampire-merits', 'label' => 'Merits', 'undeclared' => false ],
			[ [ 'name' => 'Iron Will', 'count' => 3 ] ]
		);

		$this->assertSame( 'Iron Will', $lines[0]['label'] );
		$this->assertSame( 3, $lines[0]['xp'] );
	}

	// --- a purchase ----------------------------------------------------------------------

	public function test_buying_a_merit_charges_its_cost_once_whatever_count_is_sent(): void {
		$xp = Cost_Engine::price_trait_list_change( [], self::merits(), 'm', 'add_trait', self::change( [ 'name' => 'Iron Will', 'count' => 3 ] ) );

		$this->assertSame( 3, $xp );
	}

	public function test_buying_off_a_flaw_charges_its_cost_once_whatever_count_it_holds(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Dark Secret', 'count' => 3 ] ] ];

		$xp = Cost_Engine::price_trait_list_change( $sheet, self::flaws(), 'm', 'remove_trait', self::change( [ 'name' => 'Dark Secret' ] ) );

		$this->assertSame( 3, $xp );
	}

	public function test_removing_a_merit_returns_its_cost_once(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Iron Will', 'count' => 3 ] ] ];

		$xp = Cost_Engine::price_trait_list_change( $sheet, self::merits(), 'm', 'remove_trait', self::change( [ 'name' => 'Iron Will' ] ) );

		$this->assertSame( -3, $xp );
	}

	public function test_changing_a_held_merits_count_costs_nothing_when_its_cost_is_fixed(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Iron Will', 'count' => 2 ] ] ];

		$xp = Cost_Engine::price_trait_list_change( $sheet, self::merits(), 'm', 'modify_trait', self::change( [ 'name' => 'Iron Will', 'count' => 3 ] ) );

		$this->assertSame( 0, $xp );
	}

	public function test_changing_a_range_items_count_moves_the_cost_it_chose(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Ability Aptitude', 'count' => 2 ] ] ];

		$xp = Cost_Engine::price_trait_list_change( $sheet, self::merits(), 'm', 'modify_trait', self::change( [ 'name' => 'Ability Aptitude', 'count' => 4 ] ) );

		$this->assertSame( 2, $xp );
	}

	public function test_buying_on_a_counted_block_still_charges_per_dot(): void {
		$xp = Cost_Engine::price_trait_list_change( [], self::counted(), 'm', 'add_trait', self::change( [ 'name' => 'Iron Will', 'count' => 3 ] ) );

		$this->assertSame( 9, $xp );
	}

	// --- a purchase priced by a Storyteller ---------------------------------------------

	public function test_a_managers_priced_homebrew_merit_is_quoted_once(): void {
		$quote = Cost_Engine::quote_trait_list_change( [], self::merits(), 'm', 'add_trait', self::change( [ 'name' => 'Odd Gift', 'count' => 2, 'custom' => true, 'chosen_cost' => 3 ] ), true );

		$this->assertSame( [ 'xp' => 3, 'priced' => true, 'unpriced_reason' => null ], $quote );
	}

	public function test_raising_a_homebrew_merits_count_is_quoted_free(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Odd Gift', 'count' => 2, 'custom' => true ] ] ];

		$quote = Cost_Engine::quote_trait_list_change( $sheet, self::merits(), 'm', 'modify_trait', self::change( [ 'name' => 'Odd Gift', 'count' => 4, 'custom' => true ] ) );

		$this->assertSame( [ 'xp' => 0, 'priced' => true, 'unpriced_reason' => null ], $quote );
	}

	public function test_a_homebrew_merit_waiting_for_a_price_is_one_pick(): void {
		$units = Cost_Engine::price_units( [], self::merits(), 'm', 'add_trait', self::change( [ 'name' => 'Odd Gift', 'count' => 2, 'custom' => true ] ) );

		$this->assertSame( [ 'per' => 'pick', 'units' => 1 ], $units );
	}

	public function test_a_storytellers_price_on_a_homebrew_flaw_is_recorded_once_with_the_flaws_sign(): void {
		$priced = Cost_Engine::apply_set_price( [], self::flaws(), 'm', 'add_trait', self::change( [ 'name' => 'Odd Curse', 'count' => 2, 'custom' => true ] ), 3 );

		$this->assertSame( -3, $priced['xp'] );
		$this->assertSame( 3, $priced['change_data']['trait']['chosen_cost'] );
	}
}
