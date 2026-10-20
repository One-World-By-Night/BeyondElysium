<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Cost_Engine;
use PHPUnit\Framework\TestCase;

/**
 * A list whose stored number is its price (Combination Disciplines) is priced per pick: each row is one purchase,
 * charged once whatever its number, and a price a Storyteller names becomes that number.
 */
class CostEngineComboPricingTest extends TestCase {

	private static function combos(): object {
		return json_decode( json_encode( [
			'count_is_cost' => true,
			'allow_custom'  => true,
			'items'         => [ [ 'name' => 'Draw Fire', 'cost' => '12' ] ],
		] ) );
	}

	public function test_a_list_whose_number_is_its_price_has_single_purchase_rows(): void {
		$this->assertTrue( Cost_Engine::rows_are_purchases( self::combos() ) );
		$this->assertFalse( Cost_Engine::rows_are_purchases( json_decode( json_encode( [ 'items' => [] ] ) ) ) );
	}

	public function test_a_held_combo_is_charged_its_catalog_price_once(): void {
		$price = Cost_Engine::price_held_trait_list_item( self::combos(), [ 'name' => 'Draw Fire', 'count' => 12 ] );

		$this->assertSame( 12, $price['xp'], 'the stored number is the price, not a number of dots' );
	}

	public function test_a_held_homebrew_combo_is_charged_the_price_stamped_on_it_once(): void {
		$price = Cost_Engine::price_held_trait_list_item( self::combos(), [ 'name' => 'Home Brew', 'count' => 4, 'custom' => true, 'chosen_cost' => 4 ] );

		$this->assertSame( 4, $price['xp'] );
		$this->assertSame( 'chosen_cost', $price['basis'] );
	}

	public function test_adding_a_catalog_combo_costs_its_price_once(): void {
		$change = [ 'block_slug' => 'c', 'trait' => [ 'name' => 'Draw Fire', 'count' => 3 ] ];

		$this->assertSame( 12, Cost_Engine::price_trait_list_change( [], self::combos(), 'c', 'add_trait', $change ) );
	}

	public function test_a_price_for_a_homebrew_combo_covers_the_whole_pick(): void {
		$change = [ 'block_slug' => 'c', 'trait' => [ 'name' => 'Home Brew', 'count' => 1, 'custom' => true ] ];

		$this->assertSame( [ 'per' => 'pick', 'units' => 1 ], Cost_Engine::price_units( [], self::combos(), 'c', 'add_trait', $change ) );
	}

	public function test_naming_a_price_for_a_homebrew_combo_makes_it_the_row_s_number(): void {
		$change = [ 'block_slug' => 'c', 'trait' => [ 'name' => 'Home Brew', 'count' => 1, 'custom' => true ], 'cost_pending' => true ];

		$priced = Cost_Engine::apply_set_price( [], self::combos(), 'c', 'add_trait', $change, 4 );

		$this->assertSame( 4, $priced['xp'] );
		$this->assertSame( 4, $priced['change_data']['trait']['chosen_cost'] );
		$this->assertSame( 4, $priced['change_data']['trait']['count'], 'a combo\'s number is its price' );
		$this->assertArrayNotHasKey( 'cost_pending', $priced['change_data'] );
	}

	public function test_a_free_homebrew_combo_is_numbered_zero(): void {
		$change = [ 'block_slug' => 'c', 'trait' => [ 'name' => 'Home Brew', 'count' => 1, 'custom' => true ] ];

		$priced = Cost_Engine::apply_set_price( [], self::combos(), 'c', 'add_trait', $change, 0 );

		$this->assertSame( 0, $priced['xp'] );
		$this->assertSame( 0, $priced['change_data']['trait']['count'] );
	}

	public function test_a_list_whose_number_is_not_a_price_keeps_its_count_when_priced(): void {
		$counted = json_decode( json_encode( [ 'allow_custom' => true, 'items' => [] ] ) );
		$change  = [ 'block_slug' => 'a', 'trait' => [ 'name' => 'Home Brew', 'count' => 3, 'custom' => true ] ];

		$priced = Cost_Engine::apply_set_price( [], $counted, 'a', 'add_trait', $change, 2 );

		$this->assertSame( 6, $priced['xp'], '2 XP a dot, three dots' );
		$this->assertSame( 3, $priced['change_data']['trait']['count'] );
	}
}
