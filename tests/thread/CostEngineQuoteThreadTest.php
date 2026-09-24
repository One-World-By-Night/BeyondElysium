<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Cost_Engine;
use WP_UnitTestCase;

/**
 * Against the real seeded catalog: `quote_for_change()` reads the character's own block (a chronicle's fork where it
 * has one) and `cost_for_change()` is nothing but its number.
 */
class CostEngineQuoteThreadTest extends WP_UnitTestCase {

	private string $game = 'cost-quote-thread';
	private object $character;

	public function setUp(): void {
		parent::setUp();
		Game::create( [ 'slug' => $this->game, 'name' => 'Cost Quote Thread' ] );
		$id              = (int) Character::create( [
			'name' => 'Quote Vampire', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->game, 'status' => 'active', 'sheet_data' => [ 'vampire-merits' => [] ],
		] );
		$this->character = Character::find( $id );
	}

	/** @param array<string,mixed> $trait */
	private function add( string $block, array $trait ): array {
		return [ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => $block, 'trait' => $trait ] ];
	}

	public function test_a_catalog_purchase_is_priced_and_cost_for_change_returns_the_same_number(): void {
		$change = $this->add( 'vampire-merits', [ 'name' => 'Iron Will', 'count' => 1 ] );

		$this->assertSame( 3, Cost_Engine::cost_for_change( $this->character, $change ) );
		$this->assertSame( [ 'xp' => 3, 'priced' => true, 'unpriced_reason' => null ], Cost_Engine::quote_for_change( $this->character, $change ) );
	}

	public function test_a_custom_purchase_is_unpriced_and_a_price_is_only_read_from_a_manager(): void {
		$change = $this->add( 'vampire-merits', [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true, 'chosen_cost' => 2 ] );

		$this->assertSame( 0, Cost_Engine::cost_for_change( $this->character, $change ), 'the bare number never read a price and still does not' );
		$this->assertSame( [ 'xp' => 0, 'priced' => false, 'unpriced_reason' => 'custom_no_catalog_entry' ], Cost_Engine::quote_for_change( $this->character, $change ) );
		$this->assertSame( [ 'xp' => 6, 'priced' => true, 'unpriced_reason' => null ], Cost_Engine::quote_for_change( $this->character, $change, true ) );
	}

	public function test_a_block_the_character_does_not_have_is_priced_at_nothing(): void {
		$change = $this->add( 'no-such-block', [ 'name' => 'Anything', 'custom' => true ] );

		$this->assertSame( [ 'xp' => 0, 'priced' => true, 'unpriced_reason' => null ], Cost_Engine::quote_for_change( $this->character, $change, true ) );
	}

	public function test_a_change_that_names_no_block_is_priced_at_nothing(): void {
		$this->assertSame( [ 'xp' => 0, 'priced' => true, 'unpriced_reason' => null ], Cost_Engine::quote_for_change( $this->character, [ 'change_type' => 'add_trait', 'change_data' => [] ] ) );
	}

	public function test_an_identity_change_carries_no_price(): void {
		$change = [ 'change_type' => 'modify_identity', 'change_data' => [ 'block_slug' => 'vampire-identity', 'fields' => [ 'Clan' => 'Tremere' ] ] ];

		$this->assertSame( [ 'xp' => 0, 'priced' => true, 'unpriced_reason' => null ], Cost_Engine::quote_for_change( $this->character, $change, true ) );
	}
}
