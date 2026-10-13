<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/Bot_Signed_Request.php';

use BeyondElysium\Core\Bot_Signing;
use BeyondElysium\Models\Bot_Connection;
use BeyondElysium\Models\Discord_Link;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Tests\Support\Bot_Signed_Request;
use WP_UnitTestCase;

/**
 * A bot's signature is checked before anything it asks for runs.
 */
class BotSignatureThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-bot-signature';
	private int $game_id;
	private int $connection_id;
	private string $secret;
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Signature by Night' ] );
		$connected     = Bot_Connection::connect( $this->game_id, '1', 'Guild', 1 );
		$this->connection_id = $connected['id'];
		$this->secret         = Bot_Signing::base64url_decode( $connected['secret'] );

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		$link = Discord_Link::start_or_renew( $this->game_id, '99', 'player' );
		Discord_Link::confirm( Discord_Link::find( $link['link_id'] ), $this->player, [], true );
	}

	private function route(): string {
		return "/be/v1/{$this->slug}/my/characters";
	}

	public function test_a_good_call_passes_and_touches_last_seen_at(): void {
		$this->assertNull( Bot_Connection::find( $this->connection_id )->last_seen_at );

		$request = Bot_Signed_Request::build( 'GET', $this->route(), $this->connection_id, $this->secret, '99' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotNull( Bot_Connection::find( $this->connection_id )->last_seen_at );
	}

	public function test_a_bad_signature_is_refused(): void {
		$request = Bot_Signed_Request::build( 'GET', $this->route(), $this->connection_id, $this->secret, '99' );
		$request->set_header( 'X-BE-Bot-Signature', str_repeat( '0', 64 ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'bot_auth_failed', $response->as_error()->get_error_code() );
	}

	public function test_a_tampered_body_is_refused(): void {
		$request = Bot_Signed_Request::build(
			'POST',
			"/be/v1/{$this->slug}/discord-links/{$this->fake_link_id()}/approve",
			$this->connection_id,
			$this->secret,
			'99'
		);
		$request->set_body( '{"tampered":true}' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	private function fake_link_id(): int {
		return 1;
	}

	public function test_a_tampered_query_is_refused(): void {
		$request  = Bot_Signed_Request::build( 'GET', "/be/v1/{$this->slug}/discord-links", $this->connection_id, $this->secret, '99', [ 'status' => 'approved' ] );
		$request->set_param( 'status', 'confirmed' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_an_old_timestamp_is_refused(): void {
		$request  = Bot_Signed_Request::build( 'GET', $this->route(), $this->connection_id, $this->secret, '99', [], null, time() - 400 );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_a_future_timestamp_is_refused(): void {
		$request  = Bot_Signed_Request::build( 'GET', $this->route(), $this->connection_id, $this->secret, '99', [], null, time() + 400 );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_a_replayed_nonce_is_refused(): void {
		$request1 = Bot_Signed_Request::build( 'GET', $this->route(), $this->connection_id, $this->secret, '99', [], null, null, 'fixed-nonce-value' );
		$response1 = rest_get_server()->dispatch( $request1 );
		$this->assertSame( 200, $response1->get_status() );

		$request2 = Bot_Signed_Request::build( 'GET', $this->route(), $this->connection_id, $this->secret, '99', [], null, null, 'fixed-nonce-value' );
		$response2 = rest_get_server()->dispatch( $request2 );
		$this->assertSame( 401, $response2->get_status() );
	}

	public function test_a_revoked_connection_is_refused(): void {
		Bot_Connection::disconnect( $this->connection_id, null );
		$request = Bot_Signed_Request::build( 'GET', $this->route(), $this->connection_id, $this->secret, '99' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_a_route_for_another_chronicle_is_refused(): void {
		$other_game_id = (int) Game::create( [ 'slug' => 'thread-bot-signature-other', 'name' => 'Other' ] );

		$request = Bot_Signed_Request::build( 'GET', '/be/v1/thread-bot-signature-other/my/characters', $this->connection_id, $this->secret, '99' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'bot_wrong_chronicle', $response->as_error()->get_error_code() );
	}

	public function test_a_route_off_the_allowlist_is_refused(): void {
		$request = Bot_Signed_Request::build( 'GET', "/be/v1/{$this->slug}/characters/statuses", $this->connection_id, $this->secret, '99' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'bot_route_not_allowed', $response->as_error()->get_error_code() );
	}
}
