<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Step 7a, workflow-0.9.md - `GET /{game_slug}/stats`, the ST dashboard's own aggregate
 * numbers.
 */
class GameStatsControllerTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-stats-game';
	private int $game_id;
	private int $st_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Stats Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) Game::find_by_slug( $this->game_slug )->id;

		$this->st_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_stats_returns_all_five_metrics_correctly_computed(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$vampire_id = Character::create( [
			'name' => 'Stats Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $player,
			'status' => 'active',
		] );
		Character::create( [
			'name' => 'Stats Werewolf', 'stack_slug' => 'werewolf',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $player,
			'status' => 'inactive',
		] );
		Character::update_xp( $vampire_id, 10, 10 );

		Plot::create( [ 'game_id' => $this->game_id, 'title' => 'Active Plot', 'status' => 'active' ] );
		Plot::create( [ 'game_id' => $this->game_id, 'title' => 'Resolved Plot', 'status' => 'resolved' ] );

		wp_set_current_user( $player );
		$submit = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$vampire_id}/changes" );
		$submit->set_param( 'change_type', 'add_trait' );
		$submit->set_param( 'category', 'met-merits' );
		$submit->set_param( 'change_data', [ 'block_slug' => 'met-merits', 'trait' => [ 'name' => 'Iron Will' ] ] );
		$this->dispatch( $submit );

		wp_set_current_user( $this->st_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/stats" );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( [ 'vampire' => 1, 'werewolf' => 1 ], $data['characters_by_stack'] );
		$this->assertSame( [ 'active' => 1, 'inactive' => 1 ], $data['characters_by_status'] );
		$this->assertSame( 1, $data['pending_changes'] );
		$this->assertSame( 1, $data['active_plots'] );
		$this->assertCount( 1, $data['recent_activity'] );
		$this->assertSame( 'Stats Vampire', $data['recent_activity'][0]->character_name );
	}

	public function test_stats_is_denied_to_a_plain_player(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $player );

		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/stats" );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status(), 'the ST dashboard is not the player view - 7d is the separate /my/... routes' );
	}

	public function test_stats_are_cached_across_requests(): void {
		wp_set_current_user( $this->st_id );

		$first = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/stats" ) )->get_data();
		$this->assertSame( [], $first['characters_by_stack'] );

		// A character created after the first request must not appear until the cache
		// expires - proves flush actually caches rather than merely not erroring.
		Character::create( [
			'name' => 'Post Cache Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );

		$second = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/stats" ) )->get_data();
		$this->assertSame( [], $second['characters_by_stack'], 'still the cached, pre-creation value' );

		delete_transient( 'be_game_stats_' . $this->game_slug );
		$third = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/stats" ) )->get_data();
		$this->assertSame( [ 'vampire' => 1 ], $third['characters_by_stack'], 'a fresh computation after the cache is cleared' );
	}

	/**
	 * The realistic workflow this cache has to survive: an ST checks the dashboard, goes
	 * and approves something, and checks again - all well inside one CACHE_TTL window.
	 * Without `Changes_Controller::update_item()`'s own `Game_Stats_Controller::
	 * invalidate()` call, the second load would still show the just-approved change as
	 * pending for up to a minute - found and fixed during this feature's own pre-deploy
	 * trace, not by a separate bug report.
	 */
	public function test_approving_a_change_invalidates_the_stats_cache(): void {
		$player      = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$character_id = Character::create( [
			'name' => 'Cache Invalidation Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $player,
		] );
		Character::update_xp( $character_id, 10, 10 );

		wp_set_current_user( $player );
		$submit = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$character_id}/changes" );
		$submit->set_param( 'change_type', 'add_trait' );
		$submit->set_param( 'category', 'met-merits' );
		$submit->set_param( 'change_data', [ 'block_slug' => 'met-merits', 'trait' => [ 'name' => 'Iron Will' ] ] );
		$change_id = (int) $this->dispatch( $submit )->get_data()->id;

		wp_set_current_user( $this->st_id );
		$before = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/stats" ) )->get_data();
		$this->assertSame( 1, $before['pending_changes'] );

		$approve = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$approve->set_param( 'status', 'approved' );
		$this->dispatch( $approve );

		$after = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/stats" ) )->get_data();
		$this->assertSame( 0, $after['pending_changes'], 'the approval must be visible immediately, not after the cache expires' );
	}

	/**
	 * Same proof as above, through the batch path instead of the single-change one -
	 * `batch_approve()`'s own invalidate() call, gated on "only when something was really
	 * approved" so an all-skipped batch does not pointlessly evict a still-valid cache.
	 */
	public function test_batch_approving_invalidates_the_stats_cache(): void {
		$player       = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$character_id = Character::create( [
			'name' => 'Batch Cache Invalidation Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $player,
		] );
		Character::update_xp( $character_id, 10, 10 );

		wp_set_current_user( $player );
		$submit = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$character_id}/changes" );
		$submit->set_param( 'change_type', 'add_trait' );
		$submit->set_param( 'category', 'met-merits' );
		$submit->set_param( 'change_data', [ 'block_slug' => 'met-merits', 'trait' => [ 'name' => 'Iron Will' ] ] );
		$change_id = (int) $this->dispatch( $submit )->get_data()->id;

		wp_set_current_user( $this->st_id );
		$this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/stats" ) );

		$batch = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/changes/batch-approve" );
		$batch->set_param( 'change_ids', [ $change_id ] );
		$this->dispatch( $batch );

		$after = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/stats" ) )->get_data();
		$this->assertSame( 0, $after['pending_changes'] );
	}
}
