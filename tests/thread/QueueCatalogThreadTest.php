<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A Storyteller ticks "Also add to this chronicle's catalog" on a homebrew entry waiting in the Approval Queue, and the
 * entry joins the chronicle's catalog when the change is approved.
 */
class QueueCatalogThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-queue-catalog';
	private int $game_id;
	private int $player;
	private int $hst;
	private int $ast;
	private int $character_id;
	private int $wolf_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Queue Catalog by Night' ] );
		$this->player  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor', 'display_name' => 'Head Storyteller' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		$this->ast = self::factory()->user->create( [ 'role' => 'editor', 'display_name' => 'Assistant Storyteller' ] );
		Game_Member::set_role( $this->game_id, $this->ast, 'ast' );

		$this->character_id = (int) Character::create( [
			'name' => 'Catalog Carrier', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
			'sheet_data' => [ 'vampire-abilities' => [ [ 'name' => 'Occult', 'count' => 3 ] ] ],
		] );
		Character::update_xp( $this->character_id, 30, 30 );

		$this->wolf_id = (int) Character::create( [
			'name' => 'Catalog Wolf', 'stack_slug' => 'werewolf', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player, 'sheet_data' => [],
		] );
		Character::update_xp( $this->wolf_id, 30, 30 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** @param array<string,mixed> $body */
	private function call( int $as, string $method, string $route, array $body = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		$response = rest_get_server()->dispatch( $request );
		wp_set_current_user( 0 );
		return $response;
	}

	/**
	 * What the player's editor sends when it saves changes.
	 *
	 * @param array<int,array<string,mixed>> $changes
	 * @return int[] The saved change ids.
	 */
	private function save( array $changes, ?int $character = null ): array {
		$character ??= $this->character_id;
		$response    = $this->call( $this->player, 'POST', "/be/v1/{$this->slug}/characters/{$character}/changes/submit", [ 'changes' => $changes ] );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return array_map( static fn( $c ) => (int) $c->id, $response->get_data()['changes'] );
	}

	/** @return array<string,mixed> */
	private function ritual( string $name ): array {
		return [
			'change_type' => 'add_trait', 'category' => 'vampire-rituals',
			'change_data' => [ 'block_slug' => 'vampire-rituals', 'trait' => [ 'name' => $name, 'note' => 'R1', 'count' => 1, 'custom' => true ] ],
		];
	}

	/** @return array<string,mixed> */
	private function ability( string $name ): array {
		return [
			'change_type' => 'add_trait', 'category' => 'vampire-abilities',
			'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => $name, 'count' => 1 ] ],
		];
	}

	private function token( int $id ): string {
		return Change::review_token( Change::find( $id ) );
	}

	/** @param array<string,mixed> $body */
	private function edit( int $id, array $body = [], ?int $as = null ) {
		return $this->call( $as ?? $this->hst, 'PATCH', "/be/v1/{$this->slug}/changes/{$id}", [ 'review_token' => $this->token( $id ) ] + $body );
	}

	/** @return object The change as the queue shows it. */
	private function queue_item( int $id, ?int $as = null ): object {
		$response = $this->call( $as ?? $this->hst, 'GET', "/be/v1/{$this->slug}/changes" );
		$this->assertSame( 200, $response->get_status() );
		foreach ( $response->get_data() as $item ) {
			if ( (int) $item->id === $id ) {
				return $item;
			}
		}
		$this->fail( "change {$id} is not in the queue" );
	}

	/** @return array<string,mixed> */
	private function stored_data( int $id ): array {
		return (array) Change::find( $id )->change_data;
	}

	private function groups_of_rituals(): array {
		$groups = [];
		foreach ( Schema_Block::find_by_slug( 'vampire-rituals' )->definition->items as $item ) {
			if ( isset( $item->group ) && ! in_array( $item->group, $groups, true ) ) {
				$groups[] = $item->group;
			}
		}
		return $groups;
	}

	// -------------------------------------------------------------------------
	// What the queue offers
	// -------------------------------------------------------------------------

	public function test_the_hst_is_offered_the_box_on_a_homebrew_ritual_with_the_lists_groups(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );

		$offer = $this->queue_item( $id )->catalog_offer;

		$this->assertSame( 'item', $offer['kind'] );
		$this->assertContains( 'Thaumaturgy', $offer['groups'] );
		$this->assertSame( $this->groups_of_rituals(), $offer['groups'] );
		$this->assertNull( $offer['suggested_group'] );
	}

	public function test_a_name_that_starts_with_a_group_suggests_it(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Thaumaturgy: Wax Ward' ) ] );

		$this->assertSame( 'Thaumaturgy', $this->queue_item( $id )->catalog_offer['suggested_group'] );
	}

	public function test_an_ast_is_not_offered_the_box(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );

		$this->assertNull( $this->queue_item( $id, $this->ast )->catalog_offer );
	}

	public function test_a_book_priced_change_is_not_offered_the_box(): void {
		[ $id ] = $this->save( [ $this->ability( 'Alertness' ) ] );

		$this->assertNull( $this->queue_item( $id )->catalog_offer );
	}

	public function test_a_name_the_catalog_already_holds_is_not_offered_the_box(): void {
		$name = Schema_Block::find_by_slug( 'vampire-rituals' )->definition->items[0]->name;
		[ $id ] = $this->save( [ $this->ritual( strtolower( $name ) ) ] );

		$change = $this->queue_item( $id );
		$this->assertNull( $change->catalog_offer, 'a name the book holds prices from the book, whatever its case' );
	}

	// -------------------------------------------------------------------------
	// Ticking it
	// -------------------------------------------------------------------------

	public function test_the_hst_ticks_a_priced_ritual_and_the_catalog_does_not_change_yet(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );
		$before = Schema_Block::find_for_game( 'vampire-rituals', $this->slug )->definition->items;

		$response = $this->edit( $id, [ 'price' => 2, 'catalog' => [ 'group' => 'Thaumaturgy' ] ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertEquals( [ 'group' => 'Thaumaturgy' ], $response->get_data()->catalog );
		$stored = $this->stored_data( $id )['st_catalog'];
		$this->assertSame( 'Thaumaturgy', $stored['group'] );
		$this->assertSame( $this->hst, (int) $stored['by'] );
		$this->assertEquals( $before, Schema_Block::find_for_game( 'vampire-rituals', $this->slug )->definition->items );
		$this->assertNull( Schema_Block::copy_for_game( 'vampire-rituals', $this->slug ), 'saving only marks it; no copy of the block is made' );
	}

	public function test_a_tick_with_no_group_is_fine(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );

		$response = $this->edit( $id, [ 'price' => 2, 'catalog' => [] ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEquals( [], $response->get_data()->catalog );
	}

	public function test_an_ast_cannot_tick_it(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );

		$response = $this->edit( $id, [ 'price' => 2, 'catalog' => [] ], $this->ast );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'catalog_not_allowed', $response->as_error()->get_error_code() );
		$this->assertArrayNotHasKey( 'st_catalog', $this->stored_data( $id ) );
	}

	public function test_an_asts_edit_keeps_the_hsts_tick_and_the_ast_can_see_it(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );
		$this->edit( $id, [ 'price' => 2, 'catalog' => [ 'group' => 'Thaumaturgy' ] ] );

		// The pop-up sends the price already set along with a note, as every save does.
		$response = $this->edit( $id, [ 'price' => 2, 'review_notes' => 'Looks right.' ], $this->ast );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertEquals( [ 'group' => 'Thaumaturgy' ], $response->get_data()->catalog );
		$this->assertSame( $this->hst, (int) $this->stored_data( $id )['st_catalog']['by'], 'the tick is still the HST\'s' );
		$this->assertEquals( [ 'group' => 'Thaumaturgy' ], $this->queue_item( $id, $this->ast )->catalog );
	}

	public function test_the_hst_can_clear_the_tick(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );
		$this->edit( $id, [ 'price' => 2, 'catalog' => [] ] );

		$response = $this->edit( $id, [ 'catalog' => null ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()->catalog );
		$this->assertArrayNotHasKey( 'st_catalog', $this->stored_data( $id ) );
	}

	// -------------------------------------------------------------------------
	// What is refused
	// -------------------------------------------------------------------------

	public function test_a_tick_with_no_price_is_refused(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );

		$response = $this->edit( $id, [ 'catalog' => [] ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'catalog_needs_price', $response->as_error()->get_error_code() );
		$this->assertArrayNotHasKey( 'st_catalog', $this->stored_data( $id ) );
	}

	public function test_a_book_priced_change_cannot_be_ticked(): void {
		[ $id ] = $this->save( [ $this->ability( 'Alertness' ) ] );

		$response = $this->edit( $id, [ 'price' => 2, 'catalog' => [] ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'catalog_not_applicable', $response->as_error()->get_error_code() );
	}

	public function test_a_group_the_list_does_not_have_is_refused(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );

		$response = $this->edit( $id, [ 'price' => 2, 'catalog' => [ 'group' => 'No Such Tradition' ] ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_group', $response->as_error()->get_error_code() );
	}

	public function test_a_tick_cannot_come_with_a_move(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );

		$response = $this->edit( $id, [ 'fields' => [ 'block_slug' => 'vampire-combo-disciplines' ], 'catalog' => [] ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'catalog_with_move', $response->as_error()->get_error_code() );
	}

	public function test_taking_the_price_away_drops_the_tick(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );
		$this->edit( $id, [ 'price' => 2, 'catalog' => [] ] );

		$response = $this->edit( $id, [ 'price' => null ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()->catalog );
		$this->assertArrayNotHasKey( 'st_catalog', $this->stored_data( $id ) );
	}

	public function test_a_move_drops_the_tick(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );
		$this->edit( $id, [ 'price' => 2, 'catalog' => [] ] );

		$response = $this->edit( $id, [ 'fields' => [ 'block_slug' => 'vampire-combo-disciplines' ] ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertNull( $response->get_data()->catalog );
		$this->assertArrayNotHasKey( 'st_catalog', $this->stored_data( $id ) );
	}

	// -------------------------------------------------------------------------
	// A whole new Discipline, and a Gift
	// -------------------------------------------------------------------------

	/** @return array<string,mixed> */
	private function power( string $block, string $name, int $level = 1 ): array {
		return [
			'change_type' => 'add_trait', 'category' => $block,
			'change_data' => [ 'block_slug' => $block, 'trait' => [ 'name' => $name, 'level' => $level, 'custom' => true ] ],
		];
	}

	public function test_a_homebrew_discipline_is_offered_as_a_whole_family(): void {
		[ $id ] = $this->save( [ $this->power( 'vampire-disciplines', 'Hollow Vein', 2 ) ] );

		$this->assertSame( [ 'kind' => 'family' ], $this->queue_item( $id )->catalog_offer );
	}

	public function test_the_hst_ticks_a_discipline_with_a_level_on_the_ladder(): void {
		[ $id ] = $this->save( [ $this->power( 'vampire-disciplines', 'Hollow Vein', 2 ) ] );

		$response = $this->edit( $id, [ 'price' => 4, 'catalog' => [] ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertEquals( [], $response->get_data()->catalog );
		$this->assertArrayHasKey( 'st_catalog', $this->stored_data( $id ) );
	}

	public function test_a_level_beyond_the_ladder_cannot_be_ticked(): void {
		[ $id ] = $this->save( [ $this->power( 'vampire-disciplines', 'Hollow Vein', 2 ) ] );

		$response = $this->edit( $id, [ 'price' => 4, 'fields' => [ 'level' => 6 ], 'catalog' => [] ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'catalog_incomplete', $response->as_error()->get_error_code() );
	}

	public function test_a_homebrew_gift_is_offered_as_a_pick_with_the_sections_ranks(): void {
		[ $id ] = $this->save( [ $this->power( 'werewolf-gifts', 'Moon Keepers' ) ], $this->wolf_id );

		$offer = $this->queue_item( $id )->catalog_offer;

		$this->assertSame( 'pick', $offer['kind'] );
		$this->assertSame( [ 'basic', 'intermediate', 'advanced', 'legend' ], $offer['ranks'] );
	}

	public function test_the_hst_ticks_a_new_gift_under_an_existing_family(): void {
		[ $id ] = $this->save( [ $this->power( 'werewolf-gifts', 'Moon Keepers' ) ], $this->wolf_id );

		$response = $this->edit( $id, [
			'price'   => 3,
			'fields'  => [ 'name' => 'Philodox', 'power_name' => 'Oath of Silence' ],
			'catalog' => [ 'rank' => 'intermediate' ],
		] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertEquals( [ 'rank' => 'intermediate' ], $response->get_data()->catalog );
		$trait = $this->stored_data( $id )['trait'];
		$this->assertSame( 'Philodox', $trait['name'] );
		$this->assertSame( 'Oath of Silence', $trait['power_name'] );
		$this->assertTrue( $trait['custom'], 'it is still the player\'s own entry until it is approved' );
	}

	public function test_a_gift_without_its_power_name_or_a_rank_of_this_section_is_refused(): void {
		[ $id ] = $this->save( [ $this->power( 'werewolf-gifts', 'Moon Keepers' ) ], $this->wolf_id );

		$no_power = $this->edit( $id, [ 'price' => 3, 'catalog' => [ 'rank' => 'basic' ] ] );
		$this->assertSame( 'catalog_incomplete', $no_power->as_error()->get_error_code() );

		$fields = [ 'name' => 'Philodox', 'power_name' => 'Oath of Silence' ];
		$no_rank = $this->edit( $id, [ 'price' => 3, 'fields' => $fields, 'catalog' => [] ] );
		$this->assertSame( 'invalid_rank', $no_rank->as_error()->get_error_code() );

		$wrong_rank = $this->edit( $id, [ 'price' => 3, 'fields' => $fields, 'catalog' => [ 'rank' => 'elder' ] ] );
		$this->assertSame( 'invalid_rank', $wrong_rank->as_error()->get_error_code() );
	}

	public function test_a_gift_the_family_already_holds_cannot_be_ticked(): void {
		[ $id ] = $this->save( [ $this->power( 'werewolf-gifts', 'Moon Keepers' ) ], $this->wolf_id );
		$held = null;
		foreach ( Schema_Block::find_by_slug( 'werewolf-gifts' )->definition->powers as $family ) {
			if ( $family->name === 'Philodox' ) {
				$held = ( (array) $family->elder )['basic'][0]->power_name;
			}
		}

		$response = $this->edit( $id, [ 'price' => 3, 'fields' => [ 'name' => 'Philodox', 'power_name' => $held ], 'catalog' => [ 'rank' => 'basic' ] ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'catalog_not_applicable', $response->as_error()->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Approving a ticked change
	// -------------------------------------------------------------------------

	/** @return \WP_REST_Response */
	private function approve( int $id, ?int $as = null ) {
		return $this->call( $as ?? $this->hst, 'PUT', "/be/v1/{$this->slug}/changes/{$id}", [ 'status' => 'approved', 'review_token' => $this->token( $id ) ] );
	}

	private function refuse_change( int $id ) {
		return $this->call( $this->hst, 'PUT', "/be/v1/{$this->slug}/changes/{$id}", [ 'status' => 'rejected', 'review_token' => $this->token( $id ) ] );
	}

	/** @param int[] $ids */
	private function approve_all( array $ids ) {
		$tokens = [];
		foreach ( $ids as $id ) {
			$tokens[ $id ] = $this->token( $id );
		}
		return $this->call( $this->hst, 'POST', "/be/v1/{$this->slug}/changes/batch-approve", [ 'change_ids' => $ids, 'review_tokens' => $tokens ] );
	}

	/** @return array<int,mixed> */
	private function catalog_items( string $block ): array {
		return array_values( (array) Schema_Block::find_for_game( $block, $this->slug )->definition->items );
	}

	private function catalog_item( string $block, string $name ): ?object {
		foreach ( $this->catalog_items( $block ) as $item ) {
			if ( strcasecmp( $item->name, $name ) === 0 ) {
				return $item;
			}
		}
		return null;
	}

	private function catalog_family( string $block, string $name ): ?object {
		foreach ( Schema_Block::find_for_game( $block, $this->slug )->definition->powers as $family ) {
			if ( strcasecmp( $family->name, $name ) === 0 ) {
				return $family;
			}
		}
		return null;
	}

	/** @return array<string,mixed> */
	private function sheet( int $character ): array {
		return Character::find( $character )->sheet_data;
	}

	private function unspent( int $character ): int {
		return (int) Character::find( $character )->xp_unspent;
	}

	/**
	 * What another character is told a pick costs.
	 *
	 * @param array<string,mixed> $trait
	 * @return array<string,mixed>
	 */
	private function previewed( int $character, string $block, array $trait ): array {
		$response = $this->call( $this->player, 'POST', "/be/v1/{$this->slug}/characters/{$character}/preview-changes", [
			'changes' => [ [ 'change_type' => 'add_trait', 'category' => $block, 'change_data' => [ 'block_slug' => $block, 'trait' => $trait ] ] ],
		] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data()['results'][0];
	}

	private function other_vampire(): int {
		$id = (int) Character::create( [
			'name' => 'Catalog Neighbour', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player, 'sheet_data' => [ 'vampire-abilities' => [] ],
		] );
		Character::update_xp( $id, 30, 30 );
		return $id;
	}

	public function test_approving_a_ticked_ritual_adds_it_to_the_chronicles_catalog_and_the_sheet_holds_the_catalog_entry(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );
		$this->edit( $id, [ 'price' => 2, 'catalog' => [ 'group' => 'Thaumaturgy' ] ] );
		$book = count( Schema_Block::find_by_slug( 'vampire-rituals' )->definition->items );

		$response = $this->approve( $id );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertEquals( (object) [ 'name' => 'Wax Ward', 'cost' => '2', 'group' => 'Thaumaturgy' ], $this->catalog_item( 'vampire-rituals', 'Wax Ward' ) );
		$this->assertSame( $book, count( Schema_Block::find_by_slug( 'vampire-rituals' )->definition->items ), 'the book block is untouched' );
		$this->assertNotEmpty( Schema_Block::copy_for_game( 'vampire-rituals', $this->slug )['changes'] );

		$row = end( $this->sheet( $this->character_id )['vampire-rituals'] );
		$this->assertSame( 'Wax Ward', $row['name'] );
		$this->assertArrayNotHasKey( 'custom', $row );
		$this->assertArrayNotHasKey( 'chosen_cost', $row );
		$this->assertSame( 28, $this->unspent( $this->character_id ), 'the price set for this change is what it cost' );
	}

	public function test_another_character_then_picks_it_at_that_price_and_the_chronicles_rules_decide_approval(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );
		$this->edit( $id, [ 'price' => 2, 'catalog' => [ 'group' => 'Thaumaturgy' ] ] );
		$this->approve( $id );
		Game::update( $this->slug, [ 'settings' => [ 'auto_approve' => true ] ] );
		$neighbour = $this->other_vampire();

		$result = $this->previewed( $neighbour, 'vampire-rituals', [ 'name' => 'Wax Ward', 'count' => 1 ] );

		$this->assertTrue( $result['priced'] );
		$this->assertSame( 2, (int) $result['xp_cost'] );
		$this->assertSame( 'auto', $result['approval_level'], 'a catalog entry follows the chronicle\'s own default' );
	}

	public function test_a_refused_ticked_change_adds_nothing(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );
		$this->edit( $id, [ 'price' => 2, 'catalog' => [] ] );

		$this->refuse_change( $id );

		$this->assertNull( Schema_Block::copy_for_game( 'vampire-rituals', $this->slug ) );
	}

	public function test_an_asts_approval_carries_out_the_hsts_tick(): void {
		[ $id ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );
		$this->edit( $id, [ 'price' => 2, 'catalog' => [] ] );

		$this->assertSame( 200, $this->approve( $id, $this->ast )->get_status() );

		$this->assertNotNull( $this->catalog_item( 'vampire-rituals', 'Wax Ward' ) );
	}

	public function test_two_ticked_changes_for_one_name_make_one_entry_at_the_first_price(): void {
		[ $first ] = $this->save( [ $this->ritual( 'Wax Ward' ) ] );
		$neighbour = $this->other_vampire();
		[ $second ] = $this->save( [ $this->ritual( 'Wax Ward' ) ], $neighbour );
		$this->edit( $first, [ 'price' => 2, 'catalog' => [] ] );
		$this->edit( $second, [ 'price' => 5, 'catalog' => [] ] );

		$this->approve( $first );
		$this->approve( $second );

		$named = array_filter( $this->catalog_items( 'vampire-rituals' ), static fn( $item ) => strcasecmp( $item->name, 'Wax Ward' ) === 0 );
		$this->assertCount( 1, $named );
		$this->assertSame( '2', reset( $named )->cost );
		$row = end( $this->sheet( $neighbour )['vampire-rituals'] );
		$this->assertSame( 5, (int) $row['chosen_cost'], 'the second player keeps the price they were given' );
		$this->assertSame( 25, $this->unspent( $neighbour ) );
	}

	public function test_approve_all_on_a_set_with_ticked_members_adds_each_once(): void {
		$ids = $this->save( [ $this->ritual( 'Wax Ward' ), $this->ritual( 'Candle Memory' ) ] );
		foreach ( $ids as $id ) {
			$this->edit( $id, [ 'price' => 2, 'catalog' => [] ] );
		}

		$response = $this->approve_all( $ids );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertNotNull( $this->catalog_item( 'vampire-rituals', 'Wax Ward' ) );
		$this->assertNotNull( $this->catalog_item( 'vampire-rituals', 'Candle Memory' ) );
	}

	public function test_a_set_that_cannot_be_approved_adds_nothing(): void {
		$ids = $this->save( [ $this->ritual( 'Wax Ward' ), $this->ritual( 'Candle Memory' ) ] );
		$this->edit( $ids[0], [ 'price' => 2, 'catalog' => [] ] );

		$this->approve_all( $ids );

		$this->assertNull( Schema_Block::copy_for_game( 'vampire-rituals', $this->slug ), 'the second member still waits for a price, so the whole set waits' );
		$this->assertSame( 30, $this->unspent( $this->character_id ) );
	}

	public function test_approving_a_ticked_discipline_adds_the_whole_family_at_the_sections_costs(): void {
		[ $id ] = $this->save( [ $this->power( 'vampire-disciplines', 'Hollow Vein', 2 ) ] );
		$this->edit( $id, [ 'price' => 4, 'fields' => [ 'power_name' => 'Ward of Ash' ], 'catalog' => [] ] );

		$this->assertSame( 200, $this->approve( $id )->get_status() );

		$family = $this->catalog_family( 'vampire-disciplines', 'Hollow Vein' );
		$this->assertNotNull( $family );
		$this->assertSame( [ '3', '3', '6', '6', '9' ], array_column( (array) $family->levels, 'cost' ) );
		$this->assertSame( [ '', 'Ward of Ash', '', '', '' ], array_column( (array) $family->levels, 'power_name' ) );

		$row = end( $this->sheet( $this->character_id )['vampire-disciplines'] );
		$this->assertSame( [ 'name' => 'Hollow Vein', 'level' => 2 ], array_intersect_key( $row, [ 'name' => 1, 'level' => 1, 'power_name' => 1, 'custom' => 1, 'chosen_cost' => 1 ] ) );

		$neighbour = $this->other_vampire();
		$known     = $this->previewed( $neighbour, 'vampire-disciplines', [ 'name' => 'Animalism', 'level' => 1 ] );
		$new       = $this->previewed( $neighbour, 'vampire-disciplines', [ 'name' => 'Hollow Vein', 'level' => 1 ] );
		$this->assertTrue( $new['priced'] );
		$this->assertSame( $known['xp_cost'], $new['xp_cost'], 'priced like any book family at its rank' );
	}

	public function test_approving_a_ticked_gift_adds_the_pick_to_its_family_and_the_sheet_holds_the_pick(): void {
		[ $id ] = $this->save( [ $this->power( 'werewolf-gifts', 'Moon Keepers' ) ], $this->wolf_id );
		$this->edit( $id, [ 'price' => 3, 'fields' => [ 'name' => 'Philodox', 'power_name' => 'Oath of Silence' ], 'catalog' => [ 'rank' => 'intermediate' ] ] );

		$this->assertSame( 200, $this->approve( $id )->get_status() );

		$picks = ( (array) $this->catalog_family( 'werewolf-gifts', 'Philodox' )->elder )['intermediate'];
		$this->assertEquals( (object) [ 'cost' => '6', 'tier' => 'intermediate', 'level' => null, 'source' => 'Philodox', 'power_name' => 'Oath of Silence' ], end( $picks ) );

		$row = end( $this->sheet( $this->wolf_id )['werewolf-gifts'] );
		$this->assertSame( [ 'name' => 'Philodox', 'power_name' => 'Oath of Silence' ], array_intersect_key( $row, [ 'name' => 1, 'level' => 1, 'power_name' => 1, 'custom' => 1, 'chosen_cost' => 1 ] ) );
	}
}
