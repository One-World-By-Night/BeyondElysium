<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 §3.15, C1: Rote Cards scoped to a mage's own held rotes, when a non-manager views it.
 * The engine pattern is kept throughout - the rule reads `report-registry.php`'s own
 * `holder_block` ('mage-rotes'), never a hardcoded creature type, so any future stack that
 * gains a rote-shaped block gets the identical behavior for free.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.15
 */
class RoteCardsForMagesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-rote-cards';
	private int $game_id;
	private int $storyteller_id;
	private string $rote_a;
	private string $rote_b;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Rote Cards' ] );
		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );

		// Two real names from the actual seeded mage-rotes catalog, not invented ones - the
		// same "measure the real data" discipline this project has followed throughout.
		$catalog = Schema_Block::find_by_slug( 'mage-rotes' );
		$items   = $catalog->definition->items ?? [];
		$this->assertGreaterThanOrEqual( 2, count( $items ), 'the real mage-rotes catalog must have at least two items for this test to mean anything' );
		$this->rote_a = (string) $items[0]->name;
		$this->rote_b = (string) $items[1]->name;
	}

	private function make_mage( int $player_id ): int {
		$id = (int) Character::create( [
			'name' => 'A Mage', 'stack_slug' => 'mage', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $player_id, 'created_by' => $this->storyteller_id,
		] );
		Character::update_sheet_data( $id, [
			'mage-rotes' => [ [ 'name' => $this->rote_a ], [ 'name' => $this->rote_b ] ],
		] );
		return $id;
	}

	private function make_vampire( int $player_id ): int {
		return (int) Character::create( [
			'name' => 'Not A Mage', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $player_id, 'created_by' => $this->storyteller_id,
		] );
	}

	private function rote_cards( int $wp_user_id, ?int $character_id ) {
		wp_set_current_user( $wp_user_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/reports/rote-cards" );
		if ( $character_id !== null ) {
			$request->set_param( 'character_id', $character_id );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function availability( int $wp_user_id, ?int $character_id ) {
		wp_set_current_user( $wp_user_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/reports/availability" );
		if ( $character_id !== null ) {
			$request->set_param( 'character_id', $character_id );
		}
		return rest_get_server()->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// A non-mage refuses; a mage gets only their own held rotes.
	// -------------------------------------------------------------------------

	public function test_a_non_mage_is_refused(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );
		$character_id = $this->make_vampire( $player );

		$response = $this->rote_cards( $player, $character_id );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_character_id_is_required_for_a_non_manager(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );

		$response = $this->rote_cards( $player, null );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_mage_gets_only_their_own_held_rotes(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );
		$character_id = $this->make_mage( $player );

		// A third, real rote this character does NOT hold - proves the card list is scoped,
		// not just "every rote in the chronicle."
		$catalog     = Schema_Block::find_by_slug( 'mage-rotes' );
		$unheld_name = (string) ( $catalog->definition->items[2]->name ?? 'Unheld Rote' );
		World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'rote', 'name' => $unheld_name, 'created_by' => $this->storyteller_id,
		] );

		$response = $this->rote_cards( $player, $character_id );
		$this->assertSame( 200, $response->get_status() );

		$cards = $response->get_data()['cards'];
		$this->assertCount( 2, $cards );

		$names = array_map( static function ( $card ) {
			foreach ( $card as [ $label, $value ] ) {
				if ( $label === 'Name' ) {
					return $value;
				}
			}
			return null;
		}, $cards );
		$this->assertContains( $this->rote_a, $names );
		$this->assertContains( $this->rote_b, $names );
		$this->assertNotContains( $unheld_name, $names );
	}

	public function test_a_held_rote_with_no_world_object_gets_a_fallback_card_from_the_catalog(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );
		$character_id = $this->make_mage( $player );

		// Neither rote_a nor rote_b has a world object yet - both cards are fallback cards.
		$response = $this->rote_cards( $player, $character_id );
		$cards    = $response->get_data()['cards'];

		foreach ( $cards as $card ) {
			$labels = array_column( $card, 0 );
			$this->assertSame( [ 'Name', 'Note', 'Source' ], $labels );
		}
	}

	public function test_a_held_rote_with_a_matching_world_object_uses_the_real_card(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );
		$character_id = $this->make_mage( $player );

		World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'rote', 'name' => $this->rote_a,
			'properties' => [ 'level' => 3 ], 'created_by' => $this->storyteller_id,
		] );

		$response = $this->rote_cards( $player, $character_id );
		$cards    = $response->get_data()['cards'];

		$a_card = null;
		foreach ( $cards as $card ) {
			if ( [ 'Name', $this->rote_a ] === $card[0] ) {
				$a_card = $card;
			}
		}
		$this->assertNotNull( $a_card );
		// The real card's own columns (Level among them) - not the three-field fallback shape.
		$this->assertContains( [ 'Level', '3' ], $a_card );
	}

	// -------------------------------------------------------------------------
	// A Storyteller may run it unscoped, or scoped to any character.
	// -------------------------------------------------------------------------

	public function test_a_storyteller_sees_every_rote_unscoped(): void {
		World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'rote', 'name' => 'Some Rote', 'created_by' => $this->storyteller_id,
		] );

		$response = $this->rote_cards( $this->storyteller_id, null );
		$this->assertSame( 200, $response->get_status() );
		$this->assertGreaterThanOrEqual( 1, count( $response->get_data()['cards'] ) );
	}

	// -------------------------------------------------------------------------
	// The availability route.
	// -------------------------------------------------------------------------

	public function test_availability_is_true_for_a_mage_and_false_for_a_non_mage(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );
		$mage_id     = $this->make_mage( $player );
		$vampire_id  = $this->make_vampire( $player );

		$mage_result    = $this->availability( $player, $mage_id )->get_data();
		$vampire_result = $this->availability( $player, $vampire_id )->get_data();

		$this->assertTrue( $mage_result['rote-cards'] );
		$this->assertFalse( $vampire_result['rote-cards'] );
		// item-cards/location-cards have no holder_block at all - always available.
		$this->assertTrue( $mage_result['item-cards'] );
		$this->assertTrue( $vampire_result['item-cards'] );
	}

	public function test_availability_is_true_for_a_manager_with_no_character_at_all(): void {
		$result = $this->availability( $this->storyteller_id, null );
		$this->assertTrue( $result->get_data()['rote-cards'] );
	}
}
