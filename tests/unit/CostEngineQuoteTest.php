<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Cost_Engine;
use PHPUnit\Framework\TestCase;

/**
 * 1.3.3 E1 (design §3.10): a purchase with no catalog price is unpriced, not free. Until this
 * every approved custom purchase deducted a stored 0 and nothing let a Storyteller set a price.
 * The quote says which case a change is - `priced` - so the controller can hold an unpriced one
 * for a Storyteller's number and the player's preview can say so instead of "0 XP".
 *
 * Per Q2 (owner, 2026-09-23) only a CUSTOM purchase is unpriced: a catalog item with no cost
 * keeps pricing at 0, exactly as it always has.
 */
class CostEngineQuoteTest extends TestCase {

	private static function definition( array $data ): object {
		return json_decode( json_encode( $data ) );
	}

	/** @param array<string,mixed> $flags */
	private static function block( array $flags = [] ): object {
		return self::definition( $flags + [
			'items' => [
				[ 'name' => 'Contacts', 'cost' => '1 or 3' ],
				[ 'name' => 'Free Perk' ],
				[ 'name' => 'Iron Will', 'cost' => '3' ],
			],
		] );
	}

	/**
	 * @param array<string,mixed> $trait
	 * @return array<string,mixed>
	 */
	private static function change( string $block, array $trait, array $extra = [] ): array {
		return [ 'block_slug' => $block, 'trait' => $trait ] + $extra;
	}

	private const UNPRICED = 'custom_no_catalog_entry';

	// --- the catalog: nothing changes -------------------------------------------

	public function test_a_catalog_item_is_priced_from_the_catalog(): void {
		$quote = Cost_Engine::quote_trait_list_change( [], self::block(), 'm', 'add_trait', self::change( 'm', [ 'name' => 'Iron Will', 'count' => 2 ] ) );

		$this->assertSame( [ 'xp' => 6, 'priced' => true, 'unpriced_reason' => null ], $quote );
	}

	public function test_a_catalog_item_with_no_cost_is_still_free_and_priced(): void {
		$quote = Cost_Engine::quote_trait_list_change( [], self::block(), 'm', 'add_trait', self::change( 'm', [ 'name' => 'Free Perk', 'count' => 1 ] ) );

		$this->assertSame( [ 'xp' => 0, 'priced' => true, 'unpriced_reason' => null ], $quote, 'Q2: only custom purchases are unpriced' );
	}

	public function test_removing_a_catalog_item_is_priced_as_it_always_was(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Iron Will', 'count' => 1 ] ] ];

		$quote = Cost_Engine::quote_trait_list_change( $sheet, self::block(), 'm', 'remove_trait', self::change( 'm', [ 'name' => 'Iron Will' ] ) );

		$this->assertSame( [ 'xp' => -3, 'priced' => true, 'unpriced_reason' => null ], $quote );
	}

	// --- a custom add ------------------------------------------------------------

	public function test_a_players_custom_add_is_unpriced(): void {
		$quote = Cost_Engine::quote_trait_list_change( [], self::block(), 'm', 'add_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true ] ) );

		$this->assertSame( [ 'xp' => 0, 'priced' => false, 'unpriced_reason' => self::UNPRICED ], $quote );
	}

	public function test_a_managers_custom_add_with_no_price_is_unpriced_too(): void {
		$quote = Cost_Engine::quote_trait_list_change( [], self::block(), 'm', 'add_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true ] ), true );

		$this->assertFalse( $quote['priced'] );
	}

	public function test_a_managers_custom_add_with_a_price_is_priced_per_dot(): void {
		$quote = Cost_Engine::quote_trait_list_change( [], self::block(), 'm', 'add_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true, 'chosen_cost' => 2 ] ), true );

		$this->assertSame( [ 'xp' => 6, 'priced' => true, 'unpriced_reason' => null ], $quote );
	}

	public function test_a_price_of_zero_is_a_price(): void {
		$quote = Cost_Engine::quote_trait_list_change( [], self::block(), 'm', 'add_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true, 'chosen_cost' => 0 ] ), true );

		$this->assertSame( [ 'xp' => 0, 'priced' => true, 'unpriced_reason' => null ], $quote, '0 is allowed - "priced at nothing" is not "unpriced"' );
	}

	public function test_a_players_own_price_is_never_read(): void {
		$quote = Cost_Engine::quote_trait_list_change( [], self::block(), 'm', 'add_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true, 'chosen_cost' => 1 ] ), false );

		$this->assertFalse( $quote['priced'], 'a player never prices their own homebrew' );
		$this->assertSame( 0, $quote['xp'] );
	}

	/** @return array<string,array{0:mixed}> */
	public static function unusable_prices(): array {
		return [ 'negative' => [ -1 ], 'over the cap' => [ 501 ] ];
	}

	/** @dataProvider unusable_prices */
	public function test_a_price_outside_zero_to_five_hundred_is_not_a_price( $price ): void {
		$quote = Cost_Engine::quote_trait_list_change( [], self::block(), 'm', 'add_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 1, 'custom' => true, 'chosen_cost' => $price ] ), true );

		$this->assertFalse( $quote['priced'] );
	}

	public function test_a_custom_flaw_grants_points_so_its_price_is_negative(): void {
		$quote = Cost_Engine::quote_trait_list_change( [], self::block( [ 'negative' => true ] ), 'f', 'add_trait', self::change( 'f', [ 'name' => 'Odd Curse', 'count' => 3, 'custom' => true, 'chosen_cost' => 2 ] ), true );

		$this->assertSame( [ 'xp' => -6, 'priced' => true, 'unpriced_reason' => null ], $quote );
	}

	// --- raising a custom row ------------------------------------------------------

	public function test_raising_a_priced_custom_row_uses_its_own_price(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true, 'chosen_cost' => 2 ] ] ];

		$quote = Cost_Engine::quote_trait_list_change( $sheet, self::block(), 'm', 'modify_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 5, 'custom' => true ] ) );

		$this->assertSame( [ 'xp' => 4, 'priced' => true, 'unpriced_reason' => null ], $quote, 'two new dots at the row\'s own 2 XP each' );
	}

	public function test_raising_an_unpriced_custom_row_is_unpriced(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true ] ] ];

		$quote = Cost_Engine::quote_trait_list_change( $sheet, self::block(), 'm', 'modify_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 4, 'custom' => true ] ) );

		$this->assertSame( [ 'xp' => 0, 'priced' => false, 'unpriced_reason' => self::UNPRICED ], $quote );
	}

	public function test_a_manager_can_price_the_raise_of_an_unpriced_row(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true ] ] ];

		$quote = Cost_Engine::quote_trait_list_change( $sheet, self::block(), 'm', 'modify_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 5, 'custom' => true, 'chosen_cost' => 3 ] ), true );

		$this->assertSame( [ 'xp' => 6, 'priced' => true, 'unpriced_reason' => null ], $quote );
	}

	public function test_lowering_a_custom_row_is_never_a_refund(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Occult Library', 'count' => 5, 'custom' => true, 'chosen_cost' => 2 ] ] ];

		$quote = Cost_Engine::quote_trait_list_change( $sheet, self::block(), 'm', 'modify_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 2, 'custom' => true ] ) );

		$this->assertSame( [ 'xp' => 0, 'priced' => true, 'unpriced_reason' => null ], $quote );
	}

	public function test_changing_only_a_custom_rows_note_costs_nothing(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true ] ] ];

		$quote = Cost_Engine::quote_trait_list_change( $sheet, self::block(), 'm', 'modify_trait', self::change( 'm', [ 'name' => 'Occult Library', 'custom' => true, 'note' => 'moved to the basement' ] ) );

		$this->assertSame( [ 'xp' => 0, 'priced' => true, 'unpriced_reason' => null ], $quote );
	}

	public function test_removing_a_custom_row_costs_and_refunds_nothing(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true, 'chosen_cost' => 2 ] ] ];

		$quote = Cost_Engine::quote_trait_list_change( $sheet, self::block(), 'm', 'remove_trait', self::change( 'm', [ 'name' => 'Occult Library', 'custom' => true ] ) );

		$this->assertSame( [ 'xp' => 0, 'priced' => true, 'unpriced_reason' => null ], $quote, 'never a refund' );
	}

	public function test_a_custom_flag_wins_over_a_catalog_name(): void {
		// The validator strips the flag from a name the catalog carries, so this only arises when
		// something flags one anyway - and a flagged row has never been priced from the catalog.
		$quote = Cost_Engine::quote_trait_list_change( [], self::block(), 'm', 'add_trait', self::change( 'm', [ 'name' => 'Iron Will', 'count' => 2, 'custom' => true ] ) );

		$this->assertSame( [ 'xp' => 0, 'priced' => false, 'unpriced_reason' => self::UNPRICED ], $quote );
	}

	public function test_a_managers_price_beats_the_price_the_row_already_holds(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true, 'chosen_cost' => 2 ] ] ];

		$quote = Cost_Engine::quote_trait_list_change( $sheet, self::block(), 'm', 'modify_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 4, 'custom' => true, 'chosen_cost' => 7 ] ), true );

		$this->assertSame( 7, $quote['xp'], 'the number the Storyteller just set, not the old one' );
	}

	public function test_a_players_price_never_beats_the_price_the_row_already_holds(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true, 'chosen_cost' => 2 ] ] ];

		$quote = Cost_Engine::quote_trait_list_change( $sheet, self::block(), 'm', 'modify_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 4, 'custom' => true, 'chosen_cost' => 0 ] ), false );

		$this->assertSame( 2, $quote['xp'] );
	}

	public function test_a_held_name_the_catalog_no_longer_carries_is_custom_for_pricing(): void {
		// The catalog dropped it, the character still holds it, and the client did not flag it.
		$sheet = [ 'm' => [ [ 'name' => 'Retired Perk', 'count' => 2 ] ] ];

		$quote = Cost_Engine::quote_trait_list_change( $sheet, self::block(), 'm', 'modify_trait', self::change( 'm', [ 'name' => 'Retired Perk', 'count' => 3 ] ) );

		$this->assertFalse( $quote['priced'] );
	}

	public function test_the_row_a_relabel_addresses_is_the_one_priced(): void {
		$sheet = [ 'm' => [
			[ 'name' => 'Retainers', 'specialization' => 'John', 'count' => 1, 'custom' => true, 'chosen_cost' => 2 ],
			[ 'name' => 'Retainers', 'specialization' => 'Sue', 'count' => 1, 'custom' => true, 'chosen_cost' => 5 ],
		] ];

		$quote = Cost_Engine::quote_trait_list_change(
			$sheet,
			self::block( [ 'allow_multiples' => true ] ),
			'm',
			'modify_trait',
			self::change( 'm', [ 'name' => 'Retainers', 'specialization' => 'Sue', 'count' => 3, 'custom' => true ] )
		);

		$this->assertSame( 10, $quote['xp'], 'two more dots on Sue\'s own 5 XP, not John\'s 2' );
	}

	// --- tiered powers ---------------------------------------------------------------

	private static function powers(): object {
		return self::definition( [
			'sequential' => false,
			'powers'     => [
				[ 'name' => 'Celerity', 'levels' => [
					[ 'level' => 1, 'cost' => '3' ],
					[ 'level' => 2, 'cost' => '6' ],
					[ 'power_name' => 'Precision', 'tier' => 'elder', 'cost' => '12' ],
				] ],
			],
		] );
	}

	public function test_a_catalog_power_is_priced_as_it_always_was(): void {
		$quote = Cost_Engine::quote_tiered_power_change( [], self::powers(), 'add_trait', self::change( 'p', [ 'name' => 'Celerity', 'level' => 1 ] ), true );

		$this->assertSame( [ 'xp' => 3, 'priced' => true, 'unpriced_reason' => null ], $quote );
	}

	public function test_a_catalog_elder_pick_is_priced_as_it_always_was(): void {
		$quote = Cost_Engine::quote_tiered_power_change( [], self::powers(), 'add_trait', self::change( 'p', [ 'name' => 'Celerity', 'power_name' => 'Precision' ] ), true );

		$this->assertSame( [ 'xp' => 12, 'priced' => true, 'unpriced_reason' => null ], $quote );
	}

	public function test_a_custom_family_is_unpriced(): void {
		$quote = Cost_Engine::quote_tiered_power_change( [], self::powers(), 'add_trait', self::change( 'p', [ 'name' => 'Dur-An-Ki: Path of Spirit', 'level' => 3, 'custom' => true ] ), true );

		$this->assertSame( [ 'xp' => 0, 'priced' => false, 'unpriced_reason' => self::UNPRICED ], $quote );
	}

	public function test_a_custom_pick_under_a_real_family_is_unpriced(): void {
		$quote = Cost_Engine::quote_tiered_power_change( [], self::powers(), 'add_trait', self::change( 'p', [ 'name' => 'Celerity', 'power_name' => 'My Own Trick', 'custom' => true ] ), true );

		$this->assertFalse( $quote['priced'] );
	}

	public function test_raising_a_custom_family_is_unpriced_and_a_metadata_edit_is_not(): void {
		$sheet = [ 'p' => [ [ 'name' => 'Dur-An-Ki: Path of Spirit', 'level' => 3, 'custom' => true ] ] ];

		$raise = Cost_Engine::quote_tiered_power_change( $sheet, self::powers(), 'modify_trait', self::change( 'p', [ 'name' => 'Dur-An-Ki: Path of Spirit', 'level' => 4, 'custom' => true ] ), true );
		$note  = Cost_Engine::quote_tiered_power_change( $sheet, self::powers(), 'modify_trait', self::change( 'p', [ 'name' => 'Dur-An-Ki: Path of Spirit', 'tradition' => 'Dur-An-Ki', 'custom' => true ] ), true );
		$same  = Cost_Engine::quote_tiered_power_change( $sheet, self::powers(), 'modify_trait', self::change( 'p', [ 'name' => 'Dur-An-Ki: Path of Spirit', 'level' => 3, 'custom' => true ] ), true );

		$this->assertFalse( $raise['priced'] );
		$this->assertTrue( $note['priced'] );
		$this->assertTrue( $same['priced'] );
	}

	public function test_an_unflagged_family_or_pick_the_catalog_does_not_carry_is_unpriced_too(): void {
		// Held, dropped from the catalog, and the client never flagged it: still nothing to price from.
		$family = Cost_Engine::quote_tiered_power_change( [], self::powers(), 'add_trait', self::change( 'p', [ 'name' => 'Retired Path', 'level' => 2 ] ), true );
		$pick   = Cost_Engine::quote_tiered_power_change( [], self::powers(), 'add_trait', self::change( 'p', [ 'name' => 'Celerity', 'power_name' => 'A Pick Nobody Printed' ] ), true );

		$this->assertFalse( $family['priced'] );
		$this->assertFalse( $pick['priced'] );
	}

	public function test_lowering_a_custom_familys_level_is_priced_at_nothing(): void {
		$sheet = [ 'p' => [ [ 'name' => 'Dur-An-Ki: Path of Spirit', 'level' => 4, 'custom' => true ] ] ];

		$quote = Cost_Engine::quote_tiered_power_change( $sheet, self::powers(), 'modify_trait', self::change( 'p', [ 'name' => 'Dur-An-Ki: Path of Spirit', 'level' => 2, 'custom' => true ] ), true );

		$this->assertSame( [ 'xp' => 0, 'priced' => true, 'unpriced_reason' => null ], $quote );
	}

	public function test_removing_a_custom_power_costs_nothing(): void {
		$sheet = [ 'p' => [ [ 'name' => 'Dur-An-Ki: Path of Spirit', 'level' => 3, 'custom' => true, 'chosen_cost' => 9 ] ] ];

		$quote = Cost_Engine::quote_tiered_power_change( $sheet, self::powers(), 'remove_trait', self::change( 'p', [ 'name' => 'Dur-An-Ki: Path of Spirit', 'custom' => true ] ), true );

		$this->assertSame( [ 'xp' => 0, 'priced' => true, 'unpriced_reason' => null ], $quote );
	}

	// --- how many units a price spans ----------------------------------------------

	public function test_a_trait_list_add_spans_its_dots(): void {
		$this->assertSame(
			[ 'per' => 'dot', 'units' => 3 ],
			Cost_Engine::price_units( [], self::block(), 'm', 'add_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true ] ) )
		);
	}

	public function test_a_trait_list_add_with_no_count_is_one_dot(): void {
		$this->assertSame(
			[ 'per' => 'dot', 'units' => 1 ],
			Cost_Engine::price_units( [], self::block(), 'm', 'add_trait', self::change( 'm', [ 'name' => 'Occult Library', 'custom' => true ] ) )
		);
	}

	public function test_a_raise_spans_only_the_new_dots_and_a_drop_spans_none(): void {
		$sheet = [ 'm' => [ [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true ] ] ];

		$this->assertSame( [ 'per' => 'dot', 'units' => 2 ], Cost_Engine::price_units( $sheet, self::block(), 'm', 'modify_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 5, 'custom' => true ] ) ) );
		$this->assertSame( [ 'per' => 'dot', 'units' => 0 ], Cost_Engine::price_units( $sheet, self::block(), 'm', 'modify_trait', self::change( 'm', [ 'name' => 'Occult Library', 'count' => 1, 'custom' => true ] ) ) );
		$this->assertSame( [ 'per' => 'dot', 'units' => 0 ], Cost_Engine::price_units( $sheet, self::block(), 'm', 'remove_trait', self::change( 'm', [ 'name' => 'Occult Library', 'custom' => true ] ) ) );
	}

	public function test_a_power_is_one_pick_however_many_levels(): void {
		$sheet = [ 'p' => [ [ 'name' => 'Dur-An-Ki: Path of Spirit', 'level' => 3, 'custom' => true ] ] ];

		$this->assertSame( [ 'per' => 'pick', 'units' => 1 ], Cost_Engine::price_units( [], self::powers(), 'p', 'add_trait', self::change( 'p', [ 'name' => 'Dur-An-Ki: Path of Spirit', 'level' => 3, 'custom' => true ] ) ) );
		$this->assertSame( [ 'per' => 'pick', 'units' => 1 ], Cost_Engine::price_units( $sheet, self::powers(), 'p', 'modify_trait', self::change( 'p', [ 'name' => 'Dur-An-Ki: Path of Spirit', 'level' => 5, 'custom' => true ] ) ) );
		$this->assertSame( [ 'per' => 'pick', 'units' => 0 ], Cost_Engine::price_units( $sheet, self::powers(), 'p', 'modify_trait', self::change( 'p', [ 'name' => 'Dur-An-Ki: Path of Spirit', 'tradition' => 'x', 'custom' => true ] ) ) );
		$this->assertSame( [ 'per' => 'pick', 'units' => 0 ], Cost_Engine::price_units( $sheet, self::powers(), 'p', 'remove_trait', self::change( 'p', [ 'name' => 'Dur-An-Ki: Path of Spirit', 'custom' => true ] ) ) );
	}

	// --- what a Storyteller's price makes of a purchase that was waiting for one (E3) -------

	public function test_a_price_becomes_a_per_dot_total_and_lands_on_the_trait(): void {
		$change = self::change( 'm', [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true ], [ 'cost_pending' => true ] );

		$set = Cost_Engine::apply_set_price( [], self::block(), 'm', 'add_trait', $change, 2 );

		$this->assertSame( 6, $set['xp'] );
		$this->assertSame( 2, $set['change_data']['trait']['chosen_cost'] );
		$this->assertArrayNotHasKey( 'cost_pending', $set['change_data'] );
		$this->assertSame( 3, $set['change_data']['trait']['count'], 'nothing else about the trait changes' );
	}

	public function test_a_raise_is_priced_on_the_new_dots_only(): void {
		$sheet  = [ 'm' => [ [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true ] ] ];
		$change = self::change( 'm', [ 'name' => 'Occult Library', 'count' => 5, 'custom' => true ], [ 'cost_pending' => true ] );

		$set = Cost_Engine::apply_set_price( $sheet, self::block(), 'm', 'modify_trait', $change, 3 );

		$this->assertSame( 6, $set['xp'] );
		$this->assertSame( 3, $set['change_data']['trait']['chosen_cost'] );
	}

	public function test_a_negative_block_prices_negative(): void {
		$change = self::change( 'f', [ 'name' => 'Odd Curse', 'count' => 2, 'custom' => true ], [ 'cost_pending' => true ] );

		$set = Cost_Engine::apply_set_price( [], self::block( [ 'negative' => true ] ), 'f', 'add_trait', $change, 3 );

		$this->assertSame( -6, $set['xp'] );
	}

	public function test_a_price_of_zero_totals_zero_and_is_still_stamped(): void {
		$change = self::change( 'm', [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true ], [ 'cost_pending' => true ] );

		$set = Cost_Engine::apply_set_price( [], self::block(), 'm', 'add_trait', $change, 0 );

		$this->assertSame( 0, $set['xp'] );
		$this->assertSame( 0, $set['change_data']['trait']['chosen_cost'] );
	}

	public function test_a_power_is_one_flat_price_however_many_levels(): void {
		$change = self::change( 'p', [ 'name' => 'My Own Path', 'level' => 3, 'custom' => true ], [ 'cost_pending' => true ] );

		$set = Cost_Engine::apply_set_price( [], self::powers(), 'p', 'add_trait', $change, 5 );

		$this->assertSame( 5, $set['xp'] );
		$this->assertSame( 5, $set['change_data']['trait']['chosen_cost'] );
	}

	public function test_raising_a_custom_power_adds_to_the_price_its_row_holds(): void {
		$sheet  = [ 'p' => [ [ 'name' => 'My Own Path', 'level' => 3, 'custom' => true, 'chosen_cost' => 4 ] ] ];
		$change = self::change( 'p', [ 'name' => 'My Own Path', 'level' => 4, 'custom' => true ], [ 'cost_pending' => true ] );

		$set = Cost_Engine::apply_set_price( $sheet, self::powers(), 'p', 'modify_trait', $change, 2 );

		$this->assertSame( 2, $set['xp'], 'only the new purchase is deducted' );
		$this->assertSame( 6, $set['change_data']['trait']['chosen_cost'], 'the row now stands for everything paid on it' );
	}

	public function test_raising_a_custom_power_whose_row_holds_no_price_starts_from_nothing(): void {
		$sheet  = [ 'p' => [ [ 'name' => 'My Own Path', 'level' => 3, 'custom' => true ] ] ];
		$change = self::change( 'p', [ 'name' => 'My Own Path', 'level' => 4, 'custom' => true ], [ 'cost_pending' => true ] );

		$set = Cost_Engine::apply_set_price( $sheet, self::powers(), 'p', 'modify_trait', $change, 2 );

		$this->assertSame( 2, $set['change_data']['trait']['chosen_cost'] );
	}

	public function test_an_add_never_inherits_the_price_of_a_row_already_held(): void {
		$sheet  = [ 'p' => [ [ 'name' => 'My Own Path', 'level' => 1, 'custom' => true, 'chosen_cost' => 4 ] ] ];
		$change = self::change( 'p', [ 'name' => 'My Own Path', 'level' => 2, 'custom' => true ], [ 'cost_pending' => true ] );

		$set = Cost_Engine::apply_set_price( $sheet, self::powers(), 'p', 'add_trait', $change, 2 );

		$this->assertSame( 2, $set['change_data']['trait']['chosen_cost'], 'only a raise adds to what a row carries' );
	}

	public function test_a_price_on_a_change_that_buys_nothing_totals_zero(): void {
		$sheet  = [ 'm' => [ [ 'name' => 'Occult Library', 'count' => 5, 'custom' => true ] ] ];
		$change = self::change( 'm', [ 'name' => 'Occult Library', 'count' => 2, 'custom' => true ], [ 'cost_pending' => true ] );

		$set = Cost_Engine::apply_set_price( $sheet, self::block(), 'm', 'modify_trait', $change, 4 );

		$this->assertSame( 0, $set['xp'], 'a drop is never charged and never refunded' );
	}
}
