<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The acceptance gate for GS-3 (guided-chronicle-setup-design.md §6.2):
 * `enabled_stacks` is a creation and picker filter, never a data filter. A
 * chronicle enabled to `['vampire']` that already holds a `werewolf`
 * character must still load it, resolve its template, list it, render its
 * sheet, and accept an approval against it - while the create picker offers
 * one option and a `werewolf` POST returns 400.
 *
 * @see BE_PROCESS/guided-chronicle-setup-design.md §6.2
 */
class EnabledStacksFilterTest extends WP_UnitTestCase {

	private $manager_id;
	private $game_slug = 'enabled-stacks-filter-test';
	private $werewolf_character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->manager_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		Game::create( [
			'slug'       => $this->game_slug,
			'name'       => 'Enabled Stacks Filter Test',
			'created_by' => $this->manager_id,
		] );

		// Pre-existing werewolf character, created before this chronicle ever narrows
		// its enabled_stacks - the exact scenario the binding rule exists to protect.
		$this->werewolf_character_id = Character::create( [
			'name'       => 'Pre-existing Werewolf',
			'owner_slug' => $this->game_slug,
			'stack_slug' => 'werewolf',
			'wp_user_id' => $this->manager_id,
			'created_by' => $this->manager_id,
		] );

		Game::update( $this->game_slug, [ 'settings' => [ 'enabled_stacks' => [ 'vampire' ] ] ] );
	}

	public function test_all_for_game_narrows_the_picker_to_the_enabled_list(): void {
		$stacks = Creature_Stack::all_for_game( $this->game_slug );
		$slugs  = array_column( $stacks, 'slug' );

		$this->assertSame( [ 'vampire' ], $slugs );
	}

	public function test_a_disabled_stacks_existing_character_still_loads_by_id(): void {
		$character = Character::find( $this->werewolf_character_id );

		$this->assertNotNull( $character );
		$this->assertSame( 'werewolf', $character->stack_slug );
	}

	public function test_a_disabled_stacks_existing_character_still_resolves_its_template_and_stack(): void {
		$character = Character::find( $this->werewolf_character_id );
		$resolved  = Creature_Stack::resolve( $character->stack_slug, $this->game_slug );

		$this->assertNotNull( $resolved, 'resolve() must never consult enabled_stacks' );
		$this->assertSame( 'werewolf', $resolved['stack']->slug );
	}

	public function test_a_disabled_stacks_existing_character_still_appears_in_the_full_roster(): void {
		$characters = Character::all_for_game( $this->game_slug );
		$slugs      = array_column( $characters, 'stack_slug' );

		$this->assertContains( 'werewolf', $slugs, 'Character::all_for_game() must never consult enabled_stacks' );
	}

	public function test_the_create_picker_offers_exactly_the_enabled_list_via_rest(): void {
		wp_set_current_user( $this->manager_id );
		$request = new WP_REST_Request( 'GET', '/be/v1/creature-stacks' );
		$request->set_param( 'game_slug', $this->game_slug );
		$response = rest_get_server()->dispatch( $request );

		$slugs = array_column( $response->get_data(), 'slug' );
		$this->assertSame( [ 'vampire' ], $slugs );
	}

	public function test_creating_a_disabled_stack_character_is_rejected_with_400(): void {
		wp_set_current_user( $this->manager_id );
		$request = new WP_REST_Request( 'POST', '/be/v1/' . $this->game_slug . '/characters' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_param( 'name', 'Should Not Exist' );
		$request->set_param( 'stack_slug', 'werewolf' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_creating_an_enabled_stack_character_still_succeeds(): void {
		wp_set_current_user( $this->manager_id );
		$request = new WP_REST_Request( 'POST', '/be/v1/' . $this->game_slug . '/characters' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_param( 'name', 'A New Vampire' );
		$request->set_param( 'stack_slug', 'vampire' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_a_chronicle_with_no_enabled_stacks_set_allows_every_stack(): void {
		$fresh_slug = 'enabled-stacks-no-setting-test';
		Game::create( [ 'slug' => $fresh_slug, 'name' => 'No Setting Test', 'created_by' => $this->manager_id ] );

		$stacks = Creature_Stack::all_for_game( $fresh_slug );

		$this->assertGreaterThanOrEqual( 11, count( $stacks ), 'absent enabled_stacks must mean all eleven, not zero' );
	}

	/**
	 * The merge helper lives in `Games_Controller::update_item()`, not the
	 * model - `Game::update()` itself still writes `settings` wholesale by
	 * design (R6), so this exercises the real REST path a UI actually uses.
	 */
	public function test_saving_enabled_stacks_via_rest_does_not_clobber_a_sibling_settings_key(): void {
		wp_set_current_user( $this->manager_id );

		$request1 = new WP_REST_Request( 'PUT', '/be/v1/games/' . $this->game_slug );
		$request1->set_url_params( [ 'slug' => $this->game_slug ] );
		$request1->set_param( 'settings', [ 'auto_approve' => true ] );
		rest_get_server()->dispatch( $request1 );

		$request2 = new WP_REST_Request( 'PUT', '/be/v1/games/' . $this->game_slug );
		$request2->set_url_params( [ 'slug' => $this->game_slug ] );
		$request2->set_param( 'settings', [ 'enabled_stacks' => [ 'vampire', 'mage' ] ] );
		rest_get_server()->dispatch( $request2 );

		$game = Game::find_by_slug( $this->game_slug );
		$this->assertTrue( $game->settings->auto_approve, 'the first PUT\'s key must survive the second PUT' );
		$this->assertSame( [ 'vampire', 'mage' ], $game->settings->enabled_stacks );
	}
}
