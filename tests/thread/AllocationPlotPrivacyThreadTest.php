<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Services\Action_Allocator;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-063 (Pass H intake `t3-content-controllers`). Another character's action
 * allocation is private: the plot list leaves it out, since its title alone names the character.
 * But a player could still open it by id (200, with its title and its link to the character),
 * see it among a shared plot's child plots, and list the link itself through the connections
 * route, which any member could read.
 */
class AllocationPlotPrivacyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-allocation-privacy';
	private int $storyteller;
	private int $narrator;
	private int $alice;
	private int $bob;
	private int $bob_character;
	private int $shared_plot;
	private int $bob_allocation;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$game_id           = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Allocation Privacy' ] );
		$this->storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->narrator    = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->alice       = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->bob         = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->storyteller, 'hst' );
		Game_Member::set_role( $game_id, $this->narrator, 'narrator' );
		Game_Member::set_role( $game_id, $this->alice, 'player' );
		Game_Member::set_role( $game_id, $this->bob, 'player' );

		$this->bob_character = Character::create( [ 'name' => 'Bob Secret Kindred', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'status' => 'active', 'wp_user_id' => $this->bob ] );

		wp_set_current_user( $this->storyteller );
		$this->shared_plot    = (int) Plot::create( [ 'game_id' => $game_id, 'title' => 'Court Night', 'initiated_by' => 'st' ] );
		$this->bob_allocation = Action_Allocator::persist( $this->bob_character, '2026-10-01', $this->shared_plot );
	}

	private function get_as( int $user, string $route ) {
		wp_set_current_user( $user );
		[ $path, $query ] = array_pad( explode( '?', $route, 2 ), 2, '' );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}{$path}" );
		parse_str( $query, $params );
		$request->set_query_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	public function test_another_player_cannot_open_the_allocation_or_its_entries(): void {
		$this->assertSame( 404, $this->get_as( $this->alice, "/plots/{$this->bob_allocation}" )->get_status() );
		$this->assertSame( 404, $this->get_as( $this->alice, "/plots/{$this->bob_allocation}/entries" )->get_status() );
	}

	public function test_its_player_and_the_storytellers_still_open_it(): void {
		foreach ( [ $this->bob, $this->storyteller, $this->narrator ] as $user ) {
			$response = $this->get_as( $user, "/plots/{$this->bob_allocation}" );
			$this->assertSame( 200, $response->get_status() );
			$this->assertNotEmpty( $response->get_data()->entries );
		}
	}

	public function test_a_shared_plot_lists_the_allocation_among_its_children_only_for_those_who_may_open_it(): void {
		$children = static fn( $response ) => array_map( 'intval', array_column( (array) $response->get_data()->children, 'id' ) );

		$this->assertNotContains( $this->bob_allocation, $children( $this->get_as( $this->alice, "/plots/{$this->shared_plot}" ) ) );
		$this->assertContains( $this->bob_allocation, $children( $this->get_as( $this->bob, "/plots/{$this->shared_plot}" ) ) );
		$this->assertContains( $this->bob_allocation, $children( $this->get_as( $this->storyteller, "/plots/{$this->shared_plot}" ) ) );
	}

	public function test_a_player_cannot_list_connections_but_plot_staff_can(): void {
		$route = "/connections?entity_type=character&entity_id={$this->bob_character}";

		$this->assertSame( 403, $this->get_as( $this->alice, $route )->get_status() );
		foreach ( [ $this->storyteller, $this->narrator ] as $user ) {
			$response = $this->get_as( $user, $route );
			$this->assertSame( 200, $response->get_status() );
			$this->assertContains( Action_Allocator::ACTOR_LABEL, array_column( (array) $response->get_data(), 'label' ) );
		}
	}
}
