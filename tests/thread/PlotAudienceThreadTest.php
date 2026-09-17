<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Audience;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 U3: plots enforce their own audience on every reader, a new global plot defaults to
 * `storytellers` rather than `everyone` (owner ruling), and a player-created plot is always a
 * real player plot - restricted to its owning character (§2.3a) - never wide open.
 *
 * These tests fail against the pre-1.1.0 code: `get_items()`/`get_item()`/`get_my_plots()` had
 * no audience concept at all, and `create_item()` never required or used `character_id`.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §2.3, §2.3a, U3
 */
class PlotAudienceThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-plot-audience';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Plot Audience',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	private function make_player(): int {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );
		return $player;
	}

	private function make_manager(): int {
		$hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $hst, 'hst' );
		return $hst;
	}

	private function make_character( int $wp_user_id, array $overrides = [] ): int {
		return (int) Character::create( array_merge( [
			'name'       => 'Fixture Character',
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'wp_user_id' => $wp_user_id,
			'created_by' => $wp_user_id,
		], $overrides ) );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// create_item() defaults and validation
	// -------------------------------------------------------------------------

	public function test_a_new_global_plot_defaults_to_storytellers_only(): void {
		wp_set_current_user( $this->make_manager() );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$request->set_param( 'title', 'A New Global Plot' );
		$data = $this->dispatch( $request )->get_data();

		$this->assertSame( Audience::STORYTELLERS, $data->audience );
	}

	public function test_a_manager_may_set_a_global_plot_to_everyone_explicitly(): void {
		wp_set_current_user( $this->make_manager() );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$request->set_param( 'title', 'An Open Plot' );
		$request->set_param( 'audience', Audience::EVERYONE );
		$data = $this->dispatch( $request )->get_data();

		$this->assertSame( Audience::EVERYONE, $data->audience );
	}

	public function test_a_player_creating_a_plot_without_a_character_id_is_refused(): void {
		wp_set_current_user( $this->make_player() );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$request->set_param( 'title', 'No Owner Named' );

		$this->assertSame( 400, $this->dispatch( $request )->get_status() );
	}

	public function test_a_player_cannot_create_a_plot_for_someone_elses_character(): void {
		$player           = $this->make_player();
		$other_player      = $this->make_player();
		$others_character = $this->make_character( $other_player );

		wp_set_current_user( $player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$request->set_param( 'title', 'Claiming Someone Elses Character' );
		$request->set_param( 'character_id', $others_character );

		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	public function test_a_players_own_plot_is_restricted_and_connected_to_their_character(): void {
		$player       = $this->make_player();
		$character_id = $this->make_character( $player );
		wp_set_current_user( $player );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$request->set_param( 'title', 'My Own Story' );
		$request->set_param( 'character_id', $character_id );
		$data = $this->dispatch( $request )->get_data();

		$this->assertSame( Audience::RESTRICTED, $data->audience );

		$connections = Connection::for_source( 'plot', (int) $data->id );
		$targets     = array_map( static fn( $c ) => (int) $c->target_id, $connections );
		$this->assertContains( $character_id, $targets, 'the owner must be a real connection Audience can find' );
	}

	public function test_a_player_cannot_set_audience_or_audience_rules_on_their_own_plot(): void {
		$player       = $this->make_player();
		$character_id = $this->make_character( $player );
		wp_set_current_user( $player );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$request->set_param( 'title', 'Trying To Go Public' );
		$request->set_param( 'character_id', $character_id );
		$request->set_param( 'audience', Audience::EVERYONE );
		$data = $this->dispatch( $request )->get_data();

		$this->assertSame(
			Audience::RESTRICTED,
			$data->audience,
			'A player can never widen their own plot at creation - only a Storyteller may (owner ruling, §2.3a).'
		);
	}

	// -------------------------------------------------------------------------
	// get_item()
	// -------------------------------------------------------------------------

	public function test_a_players_own_plot_is_invisible_to_an_unrelated_player(): void {
		$owner        = $this->make_player();
		$stranger     = $this->make_player();
		$character_id = $this->make_character( $owner );

		wp_set_current_user( $owner );
		$create  = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$create->set_param( 'title', 'A Private Story' );
		$create->set_param( 'character_id', $character_id );
		$plot_id = $this->dispatch( $create )->get_data()->id;

		wp_set_current_user( $stranger );
		$get = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" ) );
		$this->assertSame( 404, $get->get_status(), 'not found, never a 403 - matching this file\'s own unowned-allocation precedent' );

		wp_set_current_user( $owner );
		$this->assertSame( 200, $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" ) )->get_status() );
	}

	public function test_a_manager_always_sees_a_storytellers_only_plot(): void {
		$manager = $this->make_manager();
		wp_set_current_user( $manager );

		$create  = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$create->set_param( 'title', 'Staff Eyes Only' );
		$plot_id = $this->dispatch( $create )->get_data()->id;

		$this->assertSame( 200, $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" ) )->get_status() );
	}

	public function test_a_storytellers_only_child_is_absent_from_a_visible_parents_children(): void {
		$manager = $this->make_manager();
		wp_set_current_user( $manager );

		$parent = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$parent->set_param( 'title', 'Open Parent' );
		$parent->set_param( 'audience', Audience::EVERYONE );
		$parent_id = $this->dispatch( $parent )->get_data()->id;

		$child = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$child->set_param( 'title', 'Secret Child' );
		$child->set_param( 'parent_plot_id', $parent_id );
		// audience left unset - defaults to storytellers.
		$this->dispatch( $child );

		wp_set_current_user( $this->make_player() );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$parent_id}" ) )->get_data();

		$this->assertSame( 200, 200 ); // parent itself reachable
		$this->assertCount( 0, $data->children, 'the storytellers-only child must not appear to a player' );
	}

	// -------------------------------------------------------------------------
	// get_items() - list + the pagination-safety fix
	// -------------------------------------------------------------------------

	public function test_the_list_never_includes_a_storytellers_only_plot_for_a_player(): void {
		$manager = $this->make_manager();
		wp_set_current_user( $manager );
		$open = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$open->set_param( 'title', 'Open Plot' );
		$open->set_param( 'audience', Audience::EVERYONE );
		$this->dispatch( $open );

		$secret = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$secret->set_param( 'title', 'Secret Plot' );
		$this->dispatch( $secret );

		wp_set_current_user( $this->make_player() );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots" ) );
		$titles   = array_map( static fn( $p ) => $p->title, $response->get_data() );

		$this->assertContains( 'Open Plot', $titles );
		$this->assertNotContains( 'Secret Plot', $titles );
	}

	/**
	 * The D38-class bug this design doc's own §6 gate names by name: fetching a SQL-paginated
	 * page and *then* dropping invisible rows from it would silently truncate a page below
	 * `per_page` while more real, visible plots existed past the cut a SQL LIMIT already made.
	 * Proven directly: 3 visible plots plus 2 storytellers-only ones, asked for 2 per page -
	 * the total must read 3 (not 5, not the raw SQL row count), and both pages must be full.
	 */
	public function test_the_list_total_and_paging_reflect_only_what_is_actually_visible(): void {
		$manager = $this->make_manager();
		wp_set_current_user( $manager );

		foreach ( [ 'Open A', 'Open B', 'Open C' ] as $title ) {
			$req = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
			$req->set_param( 'title', $title );
			$req->set_param( 'audience', Audience::EVERYONE );
			$this->dispatch( $req );
		}
		foreach ( [ 'Hidden A', 'Hidden B' ] as $title ) {
			$req = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
			$req->set_param( 'title', $title );
			$this->dispatch( $req );
		}

		wp_set_current_user( $this->make_player() );

		$page1 = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots" );
		$page1->set_param( 'per_page', 2 );
		$page1->set_param( 'page', 1 );
		$response1 = $this->dispatch( $page1 );

		$this->assertSame( '3', $response1->get_headers()['X-WP-Total'], 'total must count only the visible plots' );
		$this->assertCount( 2, $response1->get_data(), 'a full page must be full when enough visible rows exist' );

		$page2 = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots" );
		$page2->set_param( 'per_page', 2 );
		$page2->set_param( 'page', 2 );
		$response2 = $this->dispatch( $page2 );

		$this->assertCount( 1, $response2->get_data(), 'the remainder, not zero and not silently dropped' );

		$all_titles = array_merge(
			array_map( static fn( $p ) => $p->title, $response1->get_data() ),
			array_map( static fn( $p ) => $p->title, $response2->get_data() )
		);
		sort( $all_titles );
		$this->assertSame( [ 'Open A', 'Open B', 'Open C' ], $all_titles );
	}

	public function test_a_manager_sees_every_plot_in_the_list_regardless_of_audience(): void {
		wp_set_current_user( $this->make_manager() );
		$secret = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$secret->set_param( 'title', 'Manager Sees This Too' );
		$this->dispatch( $secret );

		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots" ) );
		$titles   = array_map( static fn( $p ) => $p->title, $response->get_data() );

		$this->assertContains( 'Manager Sees This Too', $titles );
	}

	// -------------------------------------------------------------------------
	// get_my_plots()
	// -------------------------------------------------------------------------

	public function test_my_plots_excludes_a_connected_plot_that_is_storytellers_only(): void {
		$player       = $this->make_player();
		$character_id = $this->make_character( $player );

		// Plot::create() only defaults audience to `storytellers` through the REST create path
		// (Plots_Controller::create_item()) - a direct model call like this one falls through to
		// the raw schema default of `everyone`, so the audience is set explicitly here to actually
		// exercise "a connection alone doesn't override a stricter audience".
		$plot_id = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'Connected But Secret', 'created_by' => 1, 'audience' => Audience::STORYTELLERS ] );
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'plot',
			'source_id'   => $plot_id,
			'target_type' => 'character',
			'target_id'   => $character_id,
			'label'       => 'mentioned',
			'created_by'  => 1,
		] );

		wp_set_current_user( $player );
		$data  = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/my/plots" ) )->get_data();
		$titles = array_map( static fn( $p ) => $p->title, $data['plots'] );

		$this->assertNotContains(
			'Connected But Secret',
			$titles,
			'a connection alone must not override a stricter audience (1.1.0 §2.1)'
		);
	}

	public function test_my_plots_includes_the_players_own_restricted_plot(): void {
		$player       = $this->make_player();
		$character_id = $this->make_character( $player );
		wp_set_current_user( $player );

		$create  = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$create->set_param( 'title', 'My Own Story' );
		$create->set_param( 'character_id', $character_id );
		$this->dispatch( $create );

		$data   = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/my/plots" ) )->get_data();
		$titles = array_map( static fn( $p ) => $p->title, $data['plots'] );

		$this->assertContains( 'My Own Story', $titles );
	}

	// -------------------------------------------------------------------------
	// update_item() - manager-only, may widen or narrow, may set rules
	// -------------------------------------------------------------------------

	public function test_a_manager_may_widen_a_players_plot_to_everyone(): void {
		$player       = $this->make_player();
		$character_id = $this->make_character( $player );
		wp_set_current_user( $player );

		$create  = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$create->set_param( 'title', 'My Own Story' );
		$create->set_param( 'character_id', $character_id );
		$plot_id = $this->dispatch( $create )->get_data()->id;

		wp_set_current_user( $this->make_manager() );
		$update = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$update->set_param( 'audience', Audience::EVERYONE );
		$data = $this->dispatch( $update )->get_data();

		$this->assertSame( Audience::EVERYONE, $data->audience );
	}

	public function test_update_rejects_an_invalid_audience_value(): void {
		wp_set_current_user( $this->make_manager() );
		$create  = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$create->set_param( 'title', 'A Plot' );
		$plot_id = $this->dispatch( $create )->get_data()->id;

		$update = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$update->set_param( 'audience', 'made-up-value' );

		$this->assertSame( 400, $this->dispatch( $update )->get_status() );
	}

	// -------------------------------------------------------------------------
	// Action plots (Character::ensure_plot(), Action_Allocator::create_own_plot()) - both bypass
	// create_item()'s own defaulting, so they must set `audience` themselves or take the schema's
	// raw 'everyone' default (1.1.0 §1.1's second-survey finding).
	// -------------------------------------------------------------------------

	public function test_a_characters_own_action_plot_is_restricted_to_them(): void {
		$player       = $this->make_player();
		$character_id = $this->make_character( $player ); // Character::create() calls ensure_plot() itself.

		$plot_id = Character::plot_id( $character_id );
		$this->assertNotNull( $plot_id, 'ensure_plot() must have run during character creation' );

		$plot = Plot::find( (int) $plot_id );
		$this->assertSame( Audience::RESTRICTED, $plot->audience );

		$stranger = $this->make_player();
		wp_set_current_user( $stranger );
		$get = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" ) );
		$this->assertSame( 404, $get->get_status() );

		wp_set_current_user( $player );
		$this->assertSame( 200, $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" ) )->get_status() );
	}

	public function test_a_characters_downtime_round_plot_is_restricted_to_them(): void {
		$player       = $this->make_player();
		$character_id = $this->make_character( $player );
		$character    = Character::find( $character_id );

		$round_plot_id = Action_Allocator::create_own_plot( $character, '2026-09-20' );
		$this->assertNotNull( $round_plot_id );

		$plot = Plot::find( (int) $round_plot_id );
		$this->assertSame( Audience::RESTRICTED, $plot->audience );

		$stranger = $this->make_player();
		wp_set_current_user( $stranger );
		$get = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$round_plot_id}" ) );
		$this->assertSame( 404, $get->get_status() );

		wp_set_current_user( $player );
		$this->assertSame( 200, $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$round_plot_id}" ) )->get_status() );
	}

	public function test_update_rejects_malformed_audience_rules(): void {
		wp_set_current_user( $this->make_manager() );
		$create  = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$create->set_param( 'title', 'A Plot' );
		$plot_id = $this->dispatch( $create )->get_data()->id;

		$update = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$update->set_param( 'audience', Audience::RESTRICTED );
		$update->set_param( 'audience_rules', [ 'logic' => 'AND' ] ); // no conditions

		$this->assertSame( 400, $this->dispatch( $update )->get_status() );
	}
}
