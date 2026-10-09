<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A Touched Edge spends one Mercy Trait in place, never lowering Mercy's own rating, and never costing XP; raising a
 * Virtue converts the Conviction pool's own temporary points instead.
 */
class HunterSpentFromAndRaisedByThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-hunter-spent-from';
	private int $game_id;
	private int $character_id;
	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id  = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Hunter Spent From' ] );
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		$this->character_id = Character::create( [
			'name'       => 'Martyr Test',
			'stack_slug' => 'hunter',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->slug,
			'sheet_data' => [
				'hunter-identity'  => [ 'Creed' => 'Innocence' ],
				'hunter-virtues'   => [ 'Mercy' => [ 'permanent' => 3, 'temporary' => 0, 'spent' => 0 ] ],
				'hunter-resources' => [ 'Conviction' => [ 'permanent' => 3, 'temporary' => 10 ] ],
			],
		] );
	}

	/**
	 * A Hunter owned by a non-manager player. Switches the current user to that player.
	 *
	 * @return array{0:int,1:int} [player_id, character_id]
	 */
	private function owned_hunter( int $mercy, int $conviction_temporary ): array {
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$character_id = Character::create( [
			'name'       => 'Owned Martyr',
			'stack_slug' => 'hunter',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->slug,
			'wp_user_id' => $player_id,
			'sheet_data' => [
				'hunter-identity'  => [ 'Creed' => 'Innocence' ],
				'hunter-virtues'   => [ 'Mercy' => [ 'permanent' => $mercy, 'temporary' => 0, 'spent' => 0 ] ],
				'hunter-resources' => [ 'Conviction' => [ 'permanent' => 3, 'temporary' => $conviction_temporary ] ],
			],
		] );
		wp_set_current_user( $player_id );
		return [ $player_id, $character_id ];
	}

	private function send( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}{$route}" );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_buying_a_touched_edge_spends_one_mercy_trait_and_leaves_mercy_unchanged(): void {
		$submitted = $this->send( 'POST', "/characters/{$this->character_id}/changes", [
			'change_type' => 'add_trait',
			'category'    => 'hunter-edges',
			'change_data' => [ 'block_slug' => 'hunter-edges', 'trait' => [ 'name' => 'Innocence Path', 'power_name' => 'Hide' ] ],
		] );
		$this->assertSame( 201, $submitted->get_status(), wp_json_encode( $submitted->get_data() ) );

		$change_id = (int) $submitted->get_data()->id;
		$this->assertEquals( 0, $submitted->get_data()->xp_cost, 'an edge never charges XP' );

		$approved = $this->send( 'PUT', "/changes/{$change_id}", [ 'status' => 'approved' ] );
		$this->assertSame( 200, $approved->get_status(), wp_json_encode( $approved->get_data() ) );

		$sheet = Character::find( $this->character_id )->sheet_data;
		$this->assertSame(
			[ 'name' => 'Innocence Path', 'power_name' => 'Hide', 'spent_cost' => 1, 'spent_pool' => 'Mercy', 'spent_rank' => 'touched' ],
			$sheet['hunter-edges'][0] ?? null,
			'the stored row also carries the display-only spent_* stamp'
		);
		$this->assertSame( 3, $sheet['hunter-virtues']['Mercy']['permanent'], "buying an edge never lowers the Virtue it's spent from" );
		$this->assertSame( 1, $sheet['hunter-virtues']['Mercy']['spent'], 'a Touched edge spends one Mercy Trait' );
	}

	public function test_buying_a_second_touched_edge_without_enough_unspent_mercy_is_refused(): void {
		// Mercy is already fully spent (3 permanent, 3 spent) - no Touched edge (cost 1) can be bought.
		$character = Character::find( $this->character_id );
		$sheet     = $character->sheet_data;
		$sheet['hunter-virtues']['Mercy']['spent'] = 3;
		Character::update_sheet_data( $this->character_id, $sheet );

		$response = $this->send( 'POST', "/characters/{$this->character_id}/changes", [
			'change_type' => 'add_trait',
			'category'    => 'hunter-edges',
			'change_data' => [ 'block_slug' => 'hunter-edges', 'trait' => [ 'name' => 'Innocence Path', 'power_name' => 'Hide' ] ],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'not_enough_unspent', $response->get_data()['code'] );
	}

	public function test_removing_a_held_edge_frees_its_spent_dot(): void {
		$character = Character::find( $this->character_id );
		$sheet     = $character->sheet_data;
		$sheet['hunter-edges']                     = [ [ 'name' => 'Innocence Path', 'power_name' => 'Hide' ] ];
		$sheet['hunter-virtues']['Mercy']['spent'] = 1;
		Character::update_sheet_data( $this->character_id, $sheet );

		$submitted = $this->send( 'POST', "/characters/{$this->character_id}/changes", [
			'change_type' => 'remove_trait',
			'category'    => 'hunter-edges',
			'change_data' => [ 'block_slug' => 'hunter-edges', 'trait' => [ 'name' => 'Innocence Path', 'power_name' => 'Hide' ] ],
		] );
		$this->assertSame( 201, $submitted->get_status(), wp_json_encode( $submitted->get_data() ) );

		$change_id = (int) $submitted->get_data()->id;
		$this->send( 'PUT', "/changes/{$change_id}", [ 'status' => 'approved' ] );

		$after = Character::find( $this->character_id )->sheet_data;
		$this->assertSame( [], $after['hunter-edges'] );
		$this->assertSame( 0, $after['hunter-virtues']['Mercy']['spent'], 'removing the edge frees the dot it spent' );
	}

	public function test_a_deviance_edge_is_refused_for_an_innocence_creed_hunter(): void {
		$character = Character::find( $this->character_id );
		$sheet     = $character->sheet_data;
		$sheet['hunter-virtues']['Vision'] = [ 'permanent' => 5, 'temporary' => 0, 'spent' => 0 ];
		Character::update_sheet_data( $this->character_id, $sheet );

		$response = $this->send( 'POST', "/characters/{$this->character_id}/changes", [
			'change_type' => 'add_trait',
			'category'    => 'hunter-edges',
			'change_data' => [ 'block_slug' => 'hunter-edges', 'trait' => [ 'name' => 'Deviance Path', 'power_name' => 'Impart' ] ],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'creed_restricted', $response->get_data()['code'] );
	}

	public function test_raising_mercy_converts_ten_temporary_conviction_and_adds_one_permanent_dot(): void {
		[ , $owned_id ] = $this->owned_hunter( 3, 10 );

		$submitted = $this->send( 'POST', "/characters/{$owned_id}/changes", [
			'change_type' => 'modify_resource',
			'category'    => 'hunter-virtues',
			'change_data' => [ 'block_slug' => 'hunter-virtues', 'values' => [ 'Mercy' => [ 'permanent' => 4, 'temporary' => 0 ] ] ],
		] );
		$this->assertSame( 201, $submitted->get_status(), wp_json_encode( $submitted->get_data() ) );
		$this->assertEquals( 0, $submitted->get_data()->xp_cost, 'a Virtue is never raised with XP' );

		$change_id = (int) $submitted->get_data()->id;
		wp_set_current_user( $this->admin_id );
		$approved  = $this->send( 'PUT', "/changes/{$change_id}", [ 'status' => 'approved' ] );
		$this->assertSame( 200, $approved->get_status(), wp_json_encode( $approved->get_data() ) );

		$sheet = Character::find( $owned_id )->sheet_data;
		$this->assertSame( 4, $sheet['hunter-virtues']['Mercy']['permanent'] );
		$this->assertSame( 0, $sheet['hunter-resources']['Conviction']['temporary'], 'all 10 temporary Conviction Traits were spent' );
	}

	public function test_a_gifted_edge_is_refused_without_a_touched_edge_held_in_the_same_path(): void {
		$response = $this->send( 'POST', "/characters/{$this->character_id}/changes", [
			'change_type' => 'add_trait',
			'category'    => 'hunter-edges',
			'change_data' => [ 'block_slug' => 'hunter-edges', 'trait' => [ 'name' => 'Innocence Path', 'power_name' => 'Illuminate' ] ],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rank_not_unlocked', $response->get_data()['code'] );
	}

	public function test_a_gifted_edge_succeeds_once_a_touched_edge_in_the_same_path_is_held(): void {
		$character = Character::find( $this->character_id );
		$sheet     = $character->sheet_data;
		$sheet['hunter-edges']                     = [ [ 'name' => 'Innocence Path', 'power_name' => 'Hide', 'spent_rank' => 'touched' ] ];
		$sheet['hunter-virtues']['Mercy']['spent'] = 1;
		Character::update_sheet_data( $this->character_id, $sheet );

		$submitted = $this->send( 'POST', "/characters/{$this->character_id}/changes", [
			'change_type' => 'add_trait',
			'category'    => 'hunter-edges',
			'change_data' => [ 'block_slug' => 'hunter-edges', 'trait' => [ 'name' => 'Innocence Path', 'power_name' => 'Illuminate' ] ],
		] );
		$this->assertSame( 201, $submitted->get_status(), wp_json_encode( $submitted->get_data() ) );
	}

	public function test_a_second_edge_in_another_path_is_allowed_while_it_only_ties_the_primary_path(): void {
		$character = Character::find( $this->character_id );
		$sheet     = $character->sheet_data;
		$sheet['hunter-edges']                     = [ [ 'name' => 'Innocence Path', 'power_name' => 'Hide', 'spent_rank' => 'touched' ] ];
		$sheet['hunter-virtues']['Mercy']['spent'] = 1;
		Character::update_sheet_data( $this->character_id, $sheet );

		$response = $this->send( 'POST', "/characters/{$this->character_id}/changes", [
			'change_type' => 'add_trait',
			'category'    => 'hunter-edges',
			'change_data' => [ 'block_slug' => 'hunter-edges', 'trait' => [ 'name' => 'Martyrdom Path', 'power_name' => 'Demand' ] ],
		] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	public function test_a_third_edge_in_another_path_is_refused_once_it_would_outrank_the_primary_path(): void {
		$character = Character::find( $this->character_id );
		$sheet     = $character->sheet_data;
		$sheet['hunter-edges'] = [
			[ 'name' => 'Innocence Path', 'power_name' => 'Hide', 'spent_rank' => 'touched' ],
			[ 'name' => 'Martyrdom Path', 'power_name' => 'Demand', 'spent_rank' => 'touched' ],
		];
		$sheet['hunter-virtues']['Mercy']['spent'] = 2;
		Character::update_sheet_data( $this->character_id, $sheet );

		$response = $this->send( 'POST', "/characters/{$this->character_id}/changes", [
			'change_type' => 'add_trait',
			'category'    => 'hunter-edges',
			'change_data' => [ 'block_slug' => 'hunter-edges', 'trait' => [ 'name' => 'Martyrdom Path', 'power_name' => 'Project' ] ],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'outranks_primary_path', $response->get_data()['code'] );
	}

	/**
	 * Run as the character's owning player, not the administrator.
	 */
	public function test_raising_mercy_with_only_9_temporary_conviction_is_refused(): void {
		[ , $owned_id ] = $this->owned_hunter( 3, 9 );

		$response = $this->send( 'POST', "/characters/{$owned_id}/changes", [
			'change_type' => 'modify_resource',
			'category'    => 'hunter-virtues',
			'change_data' => [ 'block_slug' => 'hunter-virtues', 'values' => [ 'Mercy' => [ 'permanent' => 4, 'temporary' => 0 ] ] ],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'not_enough_temporary', $response->get_data()['code'] );
	}

	/**
	 * A Storyteller (here, the plain administrator) sets any value directly: no Conviction, no one-dot-at-a-time
	 * limit.
	 */
	public function test_a_storyteller_raises_mercy_by_several_dots_with_zero_conviction(): void {
		$submitted = $this->send( 'POST', "/characters/{$this->character_id}/changes", [
			'change_type' => 'modify_resource',
			'category'    => 'hunter-virtues',
			'change_data' => [ 'block_slug' => 'hunter-virtues', 'values' => [ 'Mercy' => [ 'permanent' => 9, 'temporary' => 0 ] ] ],
		] );
		$this->assertSame( 201, $submitted->get_status(), wp_json_encode( $submitted->get_data() ) );
		$this->assertEquals( 0, $submitted->get_data()->xp_cost, 'never priced - a manager edit, not a purchase' );

		$change_id = (int) $submitted->get_data()->id;
		$approved  = $this->send( 'PUT', "/changes/{$change_id}", [ 'status' => 'approved' ] );
		$this->assertSame( 200, $approved->get_status(), wp_json_encode( $approved->get_data() ) );

		$sheet = Character::find( $this->character_id )->sheet_data;
		$this->assertSame( 9, $sheet['hunter-virtues']['Mercy']['permanent'], 'a six-dot jump, with none of it priced or Conviction-gated' );
		$this->assertSame( 10, $sheet['hunter-resources']['Conviction']['temporary'], 'nothing was spent converting this' );
	}

	/**
	 * The exact single-dot case: `Change_Engine::convert_temporary_for_raise()` converts Conviction for an exact +1
	 * raise at approval time only for someone who is not a manager.
	 */
	public function test_a_storyteller_raises_mercy_by_exactly_one_dot_with_zero_conviction(): void {
		$submitted = $this->send( 'POST', "/characters/{$this->character_id}/changes", [
			'change_type' => 'modify_resource',
			'category'    => 'hunter-virtues',
			'change_data' => [ 'block_slug' => 'hunter-virtues', 'values' => [ 'Mercy' => [ 'permanent' => 4, 'temporary' => 0 ] ] ],
		] );
		$this->assertSame( 201, $submitted->get_status(), wp_json_encode( $submitted->get_data() ) );

		$change_id = (int) $submitted->get_data()->id;
		$approved  = $this->send( 'PUT', "/changes/{$change_id}", [ 'status' => 'approved' ] );
		$this->assertSame( 200, $approved->get_status(), wp_json_encode( $approved->get_data() ) );

		$sheet = Character::find( $this->character_id )->sheet_data;
		$this->assertSame( 4, $sheet['hunter-virtues']['Mercy']['permanent'] );
		$this->assertSame( 10, $sheet['hunter-resources']['Conviction']['temporary'], 'a one-dot manager raise must not drain Conviction either' );
	}
}
