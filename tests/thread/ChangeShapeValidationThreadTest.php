<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The change route refuses a misspelled or invented name, a second pool smuggled into a resource change, and other
 * malformed change shapes.
 */
class ChangeShapeValidationThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-change-shapes';
	private int $player;
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug,
			// Auto-approve by default.
			'settings' => wp_json_encode( [ 'auto_approve' => true ] ),
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) Game::find_by_slug( $this->slug )->id;

		Schema_Block::create( [
			'slug' => 'tcs-abilities', 'name' => 'Abilities', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'items' => [ [ 'name' => 'Academics', 'cost' => '2' ] ] ],
		] );
		Schema_Block::create( [
			'slug' => 'tcs-disciplines', 'name' => 'Disciplines', 'section_type' => 'tiered_power', 'is_system' => 0,
			'definition' => [ 'powers' => [ [ 'name' => 'Celerity', 'levels' => [
				[ 'level' => 1, 'power_name' => 'Alacrity', 'cost' => '3' ],
				[ 'level' => null, 'power_name' => 'Precision', 'tier' => 'elder', 'cost' => '12' ],
			] ] ] ],
		] );
		Schema_Block::create( [
			'slug' => 'tcs-pools', 'name' => 'Pools', 'section_type' => 'resource_pool', 'is_system' => 0,
			'definition' => [ 'pools' => [
				[ 'name' => 'Willpower', 'cost_per_dot' => 3, 'default_start' => 2, 'max' => 20 ],
				[ 'name' => 'Glory', 'default_start' => 0, 'max' => 10 ],
			] ],
		] );
		Creature_Stack::create( [
			'slug' => 'tcs-stack', 'name' => 'Shape Test Creature', 'is_system' => 0, 'created_by' => 1,
			'stack_definition' => [ 'sections' => [
				[ 'block_slug' => 'tcs-abilities' ], [ 'block_slug' => 'tcs-disciplines' ], [ 'block_slug' => 'tcs-pools' ],
			] ],
		] );

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->player, 'player' );

		$this->character = Character::create( [
			'name' => 'Shape Tester', 'stack_slug' => 'tcs-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
			'sheet_data' => [ 'tcs-pools' => [ 'Willpower' => [ 'permanent' => 2, 'temporary' => 2 ], 'Glory' => [ 'permanent' => 0, 'temporary' => 0 ] ] ],
		] );
		Character::update_xp( $this->character, 50, 50 );
	}

	private function submit( array $change ) {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/changes" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array_merge( [ 'category' => 'test' ], $change ) ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_misspelled_trait_is_bought_under_its_real_name_at_its_real_price(): void {
		$response = $this->submit( [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'tcs-abilities', 'trait' => [ 'name' => 'academics', 'count' => 2 ] ],
		] );

		$this->assertSame( 201, $response->get_status() );
		$change = Change::find( (int) $response->get_data()->id );
		$this->assertSame( 'Academics', $change->change_data['trait']['name'] );
		$this->assertSame( 4.0, (float) $change->xp_cost );
	}

	public function test_a_made_up_elder_power_is_refused(): void {
		$response = $this->submit( [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'tcs-disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Not A Real Power' ] ],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, Change::count_for_character( $this->character ) );
	}

	public function test_a_made_up_trait_is_refused(): void {
		$response = $this->submit( [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'tcs-abilities', 'trait' => [ 'name' => 'Free Points', 'count' => 5 ] ],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unknown_trait', $response->as_error()->get_error_code() );
	}

	public function test_a_second_pool_cannot_ride_along_in_one_resource_change(): void {
		$response = $this->submit( [
			'change_type' => 'modify_resource',
			'change_data' => [ 'block_slug' => 'tcs-pools', 'values' => [
				'Willpower' => [ 'permanent' => 2, 'temporary' => 1 ],
				'Glory'     => [ 'permanent' => 10, 'temporary' => 10 ],
			] ],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, (int) Character::find( $this->character )->sheet_data['tcs-pools']['Glory']['permanent'] );
	}

	public function test_a_player_cannot_raise_an_awarded_pool(): void {
		$response = $this->submit( [
			'change_type' => 'modify_resource',
			'change_data' => [ 'block_slug' => 'tcs-pools', 'values' => [ 'Glory' => [ 'permanent' => 10, 'temporary' => 10 ] ] ],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pool_not_purchasable', $response->as_error()->get_error_code() );
	}

	public function test_change_types_the_route_does_not_take_are_refused(): void {
		$response = $this->submit( [
			'change_type' => 'import_note',
			'change_data' => [ 'source_file' => 'forged.gex' ],
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_section_the_character_does_not_have_is_refused(): void {
		$response = $this->submit( [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'werewolf-gifts', 'trait' => [ 'name' => 'Anything' ] ],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unknown_block', $response->as_error()->get_error_code() );
	}
}
