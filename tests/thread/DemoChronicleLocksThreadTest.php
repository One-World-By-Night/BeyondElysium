<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Demo_Chronicle;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A demo chronicle refuses delete, rename, a transfer, and AI Assist; sends no mail; and its routes report its own
 * state.
 */
class DemoChronicleLocksThreadTest extends WP_UnitTestCase {

	private int $admin_id;
	private int $storyteller_id;
	private int $player_id;
	private object $game;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->admin_id       = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->player_id      = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->admin_id );

		$slug = 'thread-demo-locks-' . wp_generate_password( 8, false );
		Game::create( [
			'name' => 'Thread Demo Locks', 'slug' => $slug,
			'settings' => [ 'demo' => [
				'on' => true, 'reset_hours' => 6,
				'accounts' => [ 'storyteller' => $this->storyteller_id, 'player' => $this->player_id ],
			] ],
		] );
		$this->game = Game::find_by_slug( $slug );
	}

	public function tearDown(): void {
		Demo_Chronicle::unschedule( (int) $this->game->id );
		$companion = Game::find_by_slug( $this->game->slug . '-companion' );
		Game::delete_with_content( $this->game->slug );
		if ( $companion ) {
			Demo_Chronicle::unschedule( (int) $companion->id );
			Game::delete_with_content( $companion->slug );
		}
		parent::tearDown();
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_demo_chronicle_cannot_be_deleted(): void {
		$request = new WP_REST_Request( 'DELETE', "/be/v1/games/{$this->game->slug}" );
		$request->set_url_params( [ 'slug' => $this->game->slug ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'demo_locked', $response->as_error()->get_error_code() );
		$this->assertNotNull( Game::find_by_slug( $this->game->slug ) );
	}

	public function test_a_demo_chronicle_cannot_be_renamed(): void {
		$request = new WP_REST_Request( 'PUT', "/be/v1/games/{$this->game->slug}" );
		$request->set_param( 'slug', 'renamed-away-from-demo' );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'demo_locked', $response->as_error()->get_error_code() );
	}

	public function test_turning_the_flag_off_allows_a_rename_again(): void {
		$off = new WP_REST_Request( 'PUT', "/be/v1/games/{$this->game->slug}" );
		$off->set_param( 'settings', [ 'demo' => [ 'on' => false ] ] );
		$this->assertSame( 200, $this->dispatch( $off )->get_status() );

		$rename = new WP_REST_Request( 'PUT', "/be/v1/games/{$this->game->slug}" );
		$rename->set_param( 'slug', 'renamed-away-from-demo-' . wp_generate_password( 6, false ) );
		$this->assertSame( 200, $this->dispatch( $rename )->get_status() );
	}

	public function test_a_demo_chronicle_refuses_to_initiate_a_transfer(): void {
		$character_id = Character::create( [
			'name' => 'Demo Traveler', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game->slug,
		] );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game->slug}/transfers/outbound" );
		$request->set_url_params( [ 'game_slug' => $this->game->slug ] );
		$request->set_param( 'character_id', $character_id );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'demo_locked', $response->as_error()->get_error_code() );
	}

	public function test_a_demo_chronicle_refuses_an_inbound_transfer_offer(): void {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game->slug}/transfers/inbound" );
		$request->set_url_params( [ 'game_slug' => $this->game->slug ] );
		$request->set_param( 'payload', '<gex/>' );
		$request->set_param( 'short_code', 'ABCD1234' );
		$request->set_param( 'home_site', 'https://example.test' );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'demo_locked', $response->as_error()->get_error_code() );
	}

	public function test_ai_assist_refuses_on_a_demo_chronicle(): void {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game->slug}/ai-assist" );
		$request->set_url_params( [ 'game_slug' => $this->game->slug ] );
		$request->set_param( 'field_context', 'character_biography' );
		$request->set_param( 'current_text', '' );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'demo_locked', $response->as_error()->get_error_code() );
	}

	public function test_no_email_leaves_a_demo_chronicle_on_submission_received(): void {
		$sent = [];
		add_filter( 'pre_wp_mail', function ( $return, $atts ) use ( &$sent ) {
			$sent[] = $atts;
			return true;
		}, 10, 2 );

		Game_Member::set_role( (int) $this->game->id, $this->storyteller_id, 'hst' );
		\BeyondElysium\Core\Notifications::submission_received(
			$this->game,
			(object) [ 'character_name' => 'Someone', 'stack_slug' => 'vampire', 'arrival' => 'joining' ],
			wp_get_current_user()
		);

		$this->assertSame( [], $sent, 'a demo chronicle must send no mail at all' );
	}

	public function test_my_games_reports_the_demo_flag(): void {
		Game_Member::set_role( (int) $this->game->id, $this->admin_id, 'hst' );

		$request  = new WP_REST_Request( 'GET', '/be/v1/my/games' );
		$response = $this->dispatch( $request );
		$row      = current( array_filter( $response->get_data(), fn( $g ) => $g['slug'] === $this->game->slug ) );

		$this->assertNotFalse( $row );
		$this->assertTrue( $row['demo'] );
	}

	public function test_get_demo_status_reports_cadence_and_next_run(): void {
		Demo_Chronicle::schedule( $this->game );

		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game->slug}/demo" );
		$request->set_url_params( [ 'game_slug' => $this->game->slug ] );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['on'] );
		$this->assertSame( 6, $data['reset_hours'] );
		$this->assertNotNull( $data['next_reset'] );
	}

	public function test_reset_demo_route_runs_a_real_reset(): void {
		$request = new WP_REST_Request( 'POST', "/be/v1/games/{$this->game->slug}/demo/reset" );
		$request->set_url_params( [ 'slug' => $this->game->slug ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 25, Character::count_for_game( $this->game->slug ), '23 fixtures plus Radu and the Dockside Hunger' );
	}

	public function test_reset_demo_route_refuses_a_non_demo_chronicle(): void {
		$plain_slug = 'thread-not-a-demo-' . wp_generate_password( 8, false );
		Game::create( [ 'name' => 'Not A Demo', 'slug' => $plain_slug ] );

		$request = new WP_REST_Request( 'POST', "/be/v1/games/{$plain_slug}/demo/reset" );
		$request->set_url_params( [ 'slug' => $plain_slug ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		Game::delete_with_content( $plain_slug );
	}
}
