<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Services\Demo_Chronicle;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A demo chronicle's account passwords: never written or read through the games route, never set on an administrator,
 * and a demo account is never an administrator.
 */
class DemoAccountPasswordThreadTest extends WP_UnitTestCase {

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

		$slug = 'thread-demo-password-' . wp_generate_password( 8, false );
		Game::create( [
			'name'     => 'Thread Demo Password',
			'slug'     => $slug,
			'settings' => [ 'demo' => [
				'on'                => true,
				'reset_hours'       => 6,
				'accounts'          => [ 'storyteller' => $this->storyteller_id, 'player' => $this->player_id ],
				'accounts_password' => [ 'storyteller' => 'Stored-Value-1!' ],
			] ],
		] );
		$this->game = Game::find_by_slug( $slug );
	}

	public function tearDown(): void {
		foreach ( [ $this->game->slug, $this->game->slug . '-companion' ] as $slug ) {
			$game = Game::find_by_slug( $slug );
			if ( $game ) {
				Demo_Chronicle::unschedule( (int) $game->id );
				Game::delete_with_content( $slug );
			}
		}
		parent::tearDown();
	}

	private function put_demo( array $demo ) {
		$request = new WP_REST_Request( 'PUT', "/be/v1/games/{$this->game->slug}" );
		$request->set_url_params( [ 'slug' => $this->game->slug ] );
		$request->set_param( 'settings', [ 'demo' => $demo ] );
		return rest_get_server()->dispatch( $request );
	}

	private function stored_demo(): object {
		return Game::find_by_slug( $this->game->slug )->settings->demo;
	}

	private function accounts(): array {
		return [ 'storyteller' => $this->storyteller_id, 'player' => $this->player_id ];
	}

	public function test_the_games_route_does_not_write_a_submitted_account_password(): void {
		$response = $this->put_demo( [
			'on'                => true,
			'accounts'          => $this->accounts(),
			'accounts_password' => [ 'storyteller' => 'Submitted-Value-1!' ],
		] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Stored-Value-1!', $this->stored_demo()->accounts_password->storyteller );
	}

	public function test_saving_demo_settings_without_a_password_keeps_the_stored_one(): void {
		$response = $this->put_demo( [ 'on' => true, 'reset_hours' => 12, 'accounts' => $this->accounts() ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 12, (int) $this->stored_demo()->reset_hours );
		$this->assertSame( 'Stored-Value-1!', $this->stored_demo()->accounts_password->storyteller );
	}

	public function test_the_games_route_never_returns_an_account_password(): void {
		$one = new WP_REST_Request( 'GET', "/be/v1/games/{$this->game->slug}" );
		$one->set_url_params( [ 'slug' => $this->game->slug ] );
		$json = (string) wp_json_encode( rest_get_server()->dispatch( $one )->get_data() );
		$this->assertStringNotContainsString( 'accounts_password', $json );
		$this->assertStringNotContainsString( 'Stored-Value-1!', $json );

		$list = new WP_REST_Request( 'GET', '/be/v1/games' );
		$list->set_param( 'per_page', 100 );
		$json = (string) wp_json_encode( rest_get_server()->dispatch( $list )->get_data() );
		$this->assertStringNotContainsString( 'Stored-Value-1!', $json );

		$update = $this->put_demo( [ 'on' => true, 'accounts' => $this->accounts() ] );
		$this->assertStringNotContainsString( 'Stored-Value-1!', (string) wp_json_encode( $update->get_data() ) );
	}

	public function test_an_administrator_cannot_be_a_demo_account(): void {
		$response = $this->put_demo( [
			'on'       => true,
			'accounts' => [ 'storyteller' => $this->admin_id, 'player' => $this->player_id ],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $this->storyteller_id, (int) $this->stored_demo()->accounts->storyteller );
	}

	public function test_creating_a_game_checks_its_demo_accounts_and_drops_a_password(): void {
		$slug    = 'thread-demo-create-' . wp_generate_password( 8, false );
		$request = new WP_REST_Request( 'POST', '/be/v1/games' );
		$request->set_param( 'name', 'Thread Demo Create' );
		$request->set_param( 'slug', $slug );
		$request->set_param( 'settings', [ 'demo' => [
			'on'       => true,
			'accounts' => [ 'storyteller' => $this->admin_id, 'player' => $this->player_id ],
		] ] );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertNull( Game::find_by_slug( $slug ) );

		$request->set_param( 'settings', [ 'demo' => [
			'on'                => true,
			'accounts'          => $this->accounts(),
			'accounts_password' => [ 'storyteller' => 'Submitted-Value-1!' ],
		] ] );
		$this->assertSame( 201, rest_get_server()->dispatch( $request )->get_status() );
		$created = Game::find_by_slug( $slug );
		$this->assertNotNull( $created );
		$this->assertFalse( isset( $created->settings->demo->accounts_password ) );
		Game::delete_with_content( $slug );
	}

	public function test_a_reset_never_changes_an_administrators_password(): void {
		wp_set_password( 'Admin-Own-1!', $this->admin_id );
		$settings         = (array) $this->game->settings;
		$settings['demo'] = [
			'on'                => true,
			'accounts'          => [ 'storyteller' => $this->admin_id, 'player' => $this->player_id ],
			'accounts_password' => [ 'storyteller' => 'Taken-Over-1!' ],
		];
		Game::update( $this->game->slug, [ 'settings' => $settings ] );

		Demo_Chronicle::reset( Game::find_by_slug( $this->game->slug ) );

		$admin = get_userdata( $this->admin_id );
		$this->assertTrue( wp_check_password( 'Admin-Own-1!', $admin->user_pass, $this->admin_id ) );
	}
}
