<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Authorization::check_request() reads game_slug from the URL.
 */
class UrlParamGuardThreadTest extends WP_UnitTestCase {

	private string $home = 'thread-guard-home';
	private string $other = 'thread-guard-other';
	private int $storyteller;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		foreach ( [ $this->home, $this->other ] as $slug ) {
			$wpdb->insert( $wpdb->prefix . 'be_games', [
				'slug' => $slug, 'name' => $slug, 'settings' => '{}',
				'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			] );
		}

		// A Storyteller of the home chronicle only - an editor, as every HST is by this plugin's role map.
		$this->storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) Game::find_by_slug( $this->home )->id, $this->storyteller, 'hst' );

		Character::create( [
			'name' => 'Home Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->home,
		] );
		Character::create( [
			'name' => 'Other Chronicle Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->other,
		] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_conflicting_game_slug_in_the_query_string_is_refused(): void {
		wp_set_current_user( $this->storyteller );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->home}/characters" );
		$request->set_query_params( [ 'game_slug' => $this->other ] );

		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'url_param_conflict', $response->as_error()->get_error_code() );
	}

	public function test_a_conflicting_game_slug_in_a_json_body_cannot_write_another_chronicles_settings(): void {
		wp_set_current_user( $this->storyteller );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->home}/ai-assist/settings" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'game_slug' => $this->other, 'openai_base_url' => 'https://outside.example/v1/chat/completions' ] ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'url_param_conflict', $response->as_error()->get_error_code() );
		$this->assertEmpty( Game::find_by_slug( $this->other )->settings->ai_openai_base_url ?? '' );
	}

	public function test_a_conflicting_game_slug_in_a_form_body_is_refused(): void {
		wp_set_current_user( $this->storyteller );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->home}/ai-assist/settings" );
		$request->set_body_params( [ 'game_slug' => $this->other, 'enabled' => true ] );

		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertEmpty( Game::find_by_slug( $this->other )->settings->ai_assist_enabled ?? false );
	}

	public function test_an_array_game_slug_is_refused_without_a_warning(): void {
		wp_set_current_user( $this->storyteller );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->home}/characters" );
		$request->set_query_params( [ 'game_slug' => [ $this->other ] ] );

		$this->assertSame( 400, $this->dispatch( $request )->get_status() );
	}

	public function test_a_matching_game_slug_passes_through(): void {
		wp_set_current_user( $this->storyteller );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->home}/characters" );
		$request->set_query_params( [ 'game_slug' => $this->home ] );

		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'Home Character' ], array_column( $response->get_data(), 'name' ) );
	}

	public function test_a_route_without_a_game_slug_in_its_url_is_unaffected(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$request = new WP_REST_Request( 'GET', '/be/v1/games' );
		$request->set_query_params( [ 'game_slug' => $this->other ] );

		$this->assertSame( 200, $this->dispatch( $request )->get_status() );
	}
}
