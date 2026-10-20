<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/Bot_Signed_Request.php';

use BeyondElysium\Core\Bot_Auth;
use BeyondElysium\Core\Bot_Signing;
use BeyondElysium\Models\Bot_Connection;
use BeyondElysium\Models\Bot_Notice;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Discord_Link;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Tests\Support\Bot_Signed_Request;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The response to every signed call and every bot route carries headers no cache may store: a service call, a user
 * call, a refused signature and an unsigned request alike. The website's own responses keep their usual headers.
 */
class BotResponsesNeverCachedThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-bot-no-cache';
	private int $game_id;
	private int $connection_id;
	private string $secret;
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Uncached by Night' ] );
		$connected      = Bot_Connection::connect( $this->game_id, '1', 'Guild', 1 );
		$this->connection_id = $connected['id'];
		$this->secret         = Bot_Signing::base64url_decode( $connected['secret'] );

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		$character_id = (int) Character::create( [
			'name' => 'Cached Nowhere', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'wp_user_id' => $this->player,
		] );
		$link = Discord_Link::start_or_renew( $this->game_id, '77', 'player-discord' );
		Discord_Link::confirm( Discord_Link::find( $link['link_id'] ), $this->player, [ $character_id ], true );
	}

	/**
	 * The response as the REST server sends it: dispatched, then passed through `rest_post_dispatch`.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	private function served( WP_REST_Request $request ) {
		$server   = rest_get_server();
		$response = rest_ensure_response( $server->dispatch( $request ) );
		return apply_filters( 'rest_post_dispatch', $response, $server, $request );
	}

	private function signed( string $method, string $route, string $discord_user_id = '', array $query = [], ?string $secret = null ) {
		return $this->served( Bot_Signed_Request::build( $method, $route, $this->connection_id, $secret ?? $this->secret, $discord_user_id, $query, null ) );
	}

	private function assertNeverCached( $response ): void {
		$headers = $response->get_headers();
		foreach ( Bot_Auth::NO_CACHE_HEADERS as $name => $value ) {
			$this->assertSame( $value, $headers[ $name ] ?? null, $name );
		}
	}

	public function test_the_notice_feed_is_never_cached(): void {
		Bot_Notice::add( $this->game_id, $this->player, 'change_decided', [ 'character_name' => 'Cached Nowhere', 'label' => 'experience', 'status' => 'approved' ] );

		$response = $this->signed( 'GET', "/be/v1/{$this->slug}/bot/notices" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
		$this->assertNeverCached( $response );
	}

	public function test_the_approved_links_list_is_never_cached(): void {
		$response = $this->signed( 'GET', "/be/v1/{$this->slug}/bot/approved-links" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNeverCached( $response );
	}

	public function test_a_user_call_is_never_cached(): void {
		$response = $this->signed( 'GET', "/be/v1/{$this->slug}/my/characters", '77' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNeverCached( $response );
	}

	public function test_a_refused_signature_is_never_cached(): void {
		$response = $this->signed( 'GET', "/be/v1/{$this->slug}/bot/notices", '', [], str_repeat( 'x', 32 ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertNeverCached( $response );
	}

	public function test_an_unsigned_request_to_a_bot_route_is_never_cached(): void {
		$response = $this->served( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/bot/notices" ) );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertNeverCached( $response );
	}

	public function test_a_query_value_the_route_does_not_read_still_verifies(): void {
		$response = $this->signed( 'GET', "/be/v1/{$this->slug}/bot/notices", '', [ 'nocache' => 'f3a9c1d2e4b5a6978877665544332211' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNeverCached( $response );
	}

	public function test_the_websites_own_responses_are_left_alone(): void {
		wp_set_current_user( $this->player );
		$response = $this->served( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/my/characters" ) );
		wp_set_current_user( 0 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'Expires', $response->get_headers() );
	}
}
