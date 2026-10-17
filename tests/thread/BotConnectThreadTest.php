<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Secret_Box;
use BeyondElysium\Models\Bot_Connect_Code;
use BeyondElysium\Models\Bot_Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Connecting and disconnecting a chronicle's Discord server.
 */
class BotConnectThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-bot-connect';
	private int $game_id;
	private int $hst;
	private int $ast;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Connect by Night' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->ast     = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		Game_Member::set_role( $this->game_id, $this->ast, 'ast' );
	}

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_only_the_hst_makes_codes(): void {
		wp_set_current_user( $this->ast );
		$response = $this->dispatch( 'POST', "/be/v1/{$this->slug}/bot/connect-codes" );
		$this->assertSame( 403, $response->get_status() );

		wp_set_current_user( $this->hst );
		$response = $this->dispatch( 'POST', "/be/v1/{$this->slug}/bot/connect-codes" );
		$this->assertSame( 201, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['code'] );
	}

	public function test_a_code_works_once_and_expires(): void {
		$code = Bot_Connect_Code::generate( $this->game_id, $this->hst );

		$this->assertSame( $this->game_id, Bot_Connect_Code::consume( $code )['game_id'] );
		$this->assertNull( Bot_Connect_Code::consume( $code ), 'a used code does not work a second time' );

		$code2 = Bot_Connect_Code::generate( $this->game_id, $this->hst );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_bot_connect_codes', [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ], [ 'game_id' => $this->game_id ] );
		$this->assertNull( Bot_Connect_Code::consume( $code2 ), 'an expired code does not work' );
	}

	public function test_a_fresh_code_works_on_a_site_with_a_non_utc_timezone(): void {
		update_option( 'timezone_string', 'America/New_York' );
		try {
			$code = Bot_Connect_Code::generate( $this->game_id, $this->hst );
			$this->assertSame( $this->game_id, Bot_Connect_Code::consume( $code )['game_id'], 'a code generated the instant before is not already expired' );
		} finally {
			update_option( 'timezone_string', '' );
		}
	}

	public function test_a_wrong_code_gives_one_message(): void {
		Bot_Connect_Code::generate( $this->game_id, $this->hst );

		$response = $this->dispatch( 'POST', '/be/v1/bot/connect', [ 'code' => 'WRONGWRONGW', 'guild_id' => '1', 'guild_name' => 'g' ] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_code', $response->as_error()->get_error_code() );
	}

	public function test_ten_failures_burn_the_code(): void {
		$code = Bot_Connect_Code::generate( $this->game_id, $this->hst );
		Bot_Connect_Code::consume( $code );

		for ( $i = 0; $i < Bot_Connect_Code::MAX_FAILURES; $i++ ) {
			Bot_Connect_Code::consume( $code );
		}

		$code2 = Bot_Connect_Code::generate( $this->game_id, $this->hst );
		$this->assertSame( $this->game_id, Bot_Connect_Code::consume( $code2 )['game_id'], 'a fresh code still works after the old one burned' );
	}

	public function test_a_second_connection_is_refused_with_409(): void {
		Bot_Connection::connect( $this->game_id, '1', 'Guild One', $this->hst );

		$response = $this->dispatch( 'POST', '/be/v1/bot/connect', [
			'code'       => Bot_Connect_Code::generate( $this->game_id, $this->hst ),
			'guild_id'   => '2',
			'guild_name' => 'Guild Two',
		] );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'already_connected', $response->as_error()->get_error_code() );
	}

	public function test_disconnecting_from_either_side_empties_the_secret_and_allows_reconnecting(): void {
		$connected = Bot_Connection::connect( $this->game_id, '1', 'Guild', $this->hst );

		wp_set_current_user( $this->hst );
		$response = $this->dispatch( 'DELETE', "/be/v1/{$this->slug}/bot/connection" );
		$this->assertSame( 200, $response->get_status() );

		$row = Bot_Connection::find( $connected['id'] );
		$this->assertSame( '', $row->secret );
		$this->assertNotNull( $row->revoked_at );

		$reconnected = Bot_Connection::connect( $this->game_id, '2', 'Guild Again', $this->hst );
		$this->assertSame( $connected['id'], $reconnected['id'], 'the same row is reused' );
		$this->assertNull( Bot_Connection::find( $reconnected['id'] )->revoked_at );

		Bot_Connection::disconnect( $reconnected['id'], null );
		$this->assertNull( Bot_Connection::active_for_game( $this->game_id ) );
	}
}
