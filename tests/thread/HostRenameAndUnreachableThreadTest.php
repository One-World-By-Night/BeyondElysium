<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Keep_Current;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A host telling each home it hosts about its own rename, and the unreachable flag each side can carry for a
 * kept-current visit that's gone quiet.
 */
class HostRenameAndUnreachableThreadTest extends WP_UnitTestCase {

	private string $home_slug = 'thread-rename-home';
	private string $host_slug = 'thread-rename-host';
	private int $host_game_id;
	private int $home_character_id;
	private object $home_character;
	private int $home_visit_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->home_slug, 'name' => 'Rename Home' ] );
		$this->host_game_id = Game::create( [ 'slug' => $this->host_slug, 'name' => 'Rename Host' ] );

		$this->home_character_id = Character::create( [
			'name' => 'Traveler', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->home_slug, 'status' => 'active',
		] );
		$this->home_character = Character::find( $this->home_character_id );

		$this->home_visit_id = Transfer::create( [
			'character_uuid' => $this->home_character->uuid, 'character_id' => $this->home_character_id,
			'direction' => 'outbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Rename Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Rename Host',
			'payload_hash' => str_repeat( 'a', 64 ), 'initiated_by' => 1,
		] );

		$host_character_id = Character::create( [
			'name' => 'Traveler', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->host_slug, 'status' => 'active',
		] );
		Transfer::create( [
			'character_uuid' => $this->home_character->uuid, 'character_id' => $host_character_id,
			'direction' => 'inbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Rename Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Rename Host',
			'payload_hash' => str_repeat( 'd', 64 ), 'initiated_by' => 1,
		] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Routes a `from-host` call (and the `/verify/` callback it makes) to a real internal dispatch.
	 */
	private function loopback(): callable {
		$callback = function ( $preempt, $args, $url ) {
			if ( strpos( $url, '/verify/' ) !== false ) {
				$code = rawurldecode( substr( $url, strrpos( $url, '/' ) + 1 ) );
				return $this->dispatched_response( new WP_REST_Request( 'GET', '/be/v1/verify/' . $code ) );
			}
			if ( preg_match( '#/([a-z0-9\-]+)/transfers/([^/]+)/from-host#', $url, $m ) ) {
				$body    = json_decode( (string) ( $args['body'] ?? '{}' ), true );
				$request = new WP_REST_Request( 'POST', "/be/v1/{$m[1]}/transfers/{$m[2]}/from-host" );
				foreach ( (array) $body as $key => $value ) {
					$request->set_param( $key, $value );
				}
				return $this->dispatched_response( $request );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );
		return $callback;
	}

	private function dispatched_response( WP_REST_Request $request ): array {
		$response = rest_get_server()->dispatch( $request );
		return [
			'response' => [ 'code' => $response->get_status(), 'message' => '' ],
			'body'     => wp_json_encode( $response->get_data() ),
			'headers'  => [], 'cookies' => [], 'filename' => null,
		];
	}

	public function test_a_rename_moves_homes_host_slug(): void {
		$callback = $this->loopback();
		$result   = Game::rename( $this->host_game_id, 'rename-host-reborn' );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertTrue( $result['changed'] );

		$home_row = Transfer::find( $this->home_visit_id );
		$this->assertSame( 'rename-host-reborn', $home_row->host_slug );
		$this->assertSame( 'Rename Host', $home_row->host_chronicle );
	}

	public function test_a_day_of_failures_shows_the_flag(): void {
		$host_character_id = Character::create( [
			'name' => 'Visiting Copy', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->host_slug, 'status' => 'active',
		] );
		$host_character = Character::find( $host_character_id );

		$visit_id = Transfer::create( [
			'character_uuid' => $host_character->uuid, 'character_id' => $host_character_id,
			'direction' => 'inbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Rename Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Rename Host',
			'payload_hash' => str_repeat( 'b', 64 ), 'initiated_by' => 1,
		] );
		Transfer::transition( $visit_id, 'visiting', [ 'keep_current' => 1, 'keep_current_accepted' => 1 ] );
		Manager::update( 'character_transfers', [
			'acknowledged_at' => gmdate( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ),
		], [ 'id' => $visit_id ] );

		Keep_Current::sweep();

		$this->assertNotNull( Transfer::find( $visit_id )->unreachable_since );
	}

	public function test_a_success_clears_it(): void {
		$host_character_id = Character::create( [
			'name' => 'Visiting Copy', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->host_slug, 'status' => 'active',
		] );
		$host_character = Character::find( $host_character_id );

		$visit_id = Transfer::create( [
			'character_uuid' => $host_character->uuid, 'character_id' => $host_character_id,
			'direction' => 'inbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Rename Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Rename Host',
			'payload_hash' => str_repeat( 'c', 64 ), 'initiated_by' => 1,
		] );
		Transfer::transition( $visit_id, 'visiting', [ 'keep_current' => 1, 'keep_current_accepted' => 1 ] );
		Manager::update( 'character_transfers', [
			'unreachable_since' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
		], [ 'id' => $visit_id ] );

		$body = [
			'type' => 'update', 'home_site' => home_url(), 'home_slug' => $this->home_slug,
			'code' => 'WHATEVER-CODE', 'uuid' => $host_character->uuid, 'name' => 'Visiting Copy',
			'sheet_data' => [], 'xp_earned' => 0, 'xp_unspent' => 0, 'sequence' => 1,
		];
		$callback = function ( $preempt, $args, $url ) use ( $body ) {
			if ( strpos( $url, '/verify/' ) === false ) {
				return $preempt;
			}
			return [
				'response' => [ 'code' => 200, 'message' => '' ],
				'body'     => wp_json_encode( [
					'valid' => true, 'revoked' => false, 'kind' => 'transfer',
					'issuer' => [ 'site' => home_url() ],
					'attested' => [ 'sheet_hash' => Keep_Current::canonical_hash( $body ) ],
				] ),
				'headers' => [], 'cookies' => [], 'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->host_slug}/transfers/{$host_character->uuid}/from-home" );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_get_server()->dispatch( $request );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertNull( Transfer::find( $visit_id )->unreachable_since );
	}
}
