<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The player feed's `is_action_allocation` flag, true only on a plot whose Action_Allocator actor is one of the
 * caller's own characters - the one signal a bot needs to list a player's own downtime plots.
 */
class MyPlotsAllocationFlagThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-my-plots-allocation-flag';
	private int $game_id;
	private int $player;
	private int $own_character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'My Plots Allocation Flag' ] );

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		$this->own_character = (int) Character::create( [
			'name' => 'Own Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
		] );
	}

	private function my_plots(): array {
		wp_set_current_user( $this->player );
		$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/my/plots" ) )->get_data();
		return $data['plots'];
	}

	private function find_plot( array $plots, string $title ): ?object {
		foreach ( $plots as $plot ) {
			if ( $plot->title === $title ) {
				return $plot;
			}
		}
		return null;
	}

	public function test_a_characters_own_auto_created_plot_is_flagged(): void {
		$plots = $this->my_plots();

		$own_plot = $this->find_plot( $plots, Character::plot_title( 'Own Character', $this->own_character ) );

		$this->assertNotNull( $own_plot );
		$this->assertTrue( $own_plot->is_action_allocation );
	}

	public function test_a_plot_the_player_is_only_a_member_of_is_not_flagged(): void {
		$open_plot_id = \BeyondElysium\Models\Plot::create( [
			'game_id' => $this->game_id, 'title' => 'Open Plot', 'initiated_by' => 'st',
			'game_date' => '2026-09-01', 'created_by' => 1, 'audience' => 'everyone',
		] );
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $open_plot_id,
			'target_type' => 'character', 'target_id' => $this->own_character,
			'label' => 'plot_member', 'created_by' => 1,
		] );

		$plots = $this->my_plots();

		$open_plot = $this->find_plot( $plots, 'Open Plot' );
		$this->assertNotNull( $open_plot );
		$this->assertFalse( $open_plot->is_action_allocation );
	}

	public function test_another_characters_allocation_plot_is_not_flagged_for_this_player(): void {
		$other_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $other_player, 'player' );
		$other_character = (int) Character::create( [
			'name' => 'Other Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $other_player,
		] );
		$other_plot_id = Character::plot_id( $other_character );
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $other_plot_id,
			'target_type' => 'character', 'target_id' => $this->own_character,
			'label' => 'plot_member', 'created_by' => $other_player,
		] );

		$plots = $this->my_plots();

		$other_plot = $this->find_plot( $plots, Character::plot_title( 'Other Character', $other_character ) );
		$this->assertNotNull( $other_plot );
		$this->assertFalse( $other_plot->is_action_allocation );
	}
}
