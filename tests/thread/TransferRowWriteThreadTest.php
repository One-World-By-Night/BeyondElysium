<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/RowLockProbe.php';

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Character_Exporter;
use BeyondElysium\Tests\Support\RowLockProbe;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * (found triaging).
 */
class TransferRowWriteThreadTest extends WP_UnitTestCase {

	private string $home_slug = 'thread-transfer-row-home';
	private string $host_slug = 'thread-transfer-row-host';
	private int $character_id;
	private object $character;
	private int $hidden_checks = 0;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->home_slug, 'name' => 'Row Home' ] );
		Game::create( [ 'slug' => $this->host_slug, 'name' => 'Row Host' ] );

		$this->character_id = (int) Character::create( [
			'name' => 'Row Traveller', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->home_slug, 'status' => 'active',
		] );
		$this->character = Character::find( $this->character_id );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'pre_http_request', [ $this, 'loopback' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', '__return_true' );
		remove_filter( 'pre_http_request', [ $this, 'loopback' ], 10 );
		remove_filter( 'query', [ $this, 'break_transfer_inserts' ] );
		remove_filter( 'query', [ $this, 'hide_the_open_offer_once' ] );
		parent::tear_down();
	}

	/**
	 * Answers the host's verify callback through real REST dispatch.
	 */
	public function loopback( $preempt, $args, $url ) {
		if ( strpos( $url, '/verify/' ) === false ) {
			return $preempt;
		}
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/verify/' . rawurldecode( substr( $url, strrpos( $url, '/' ) + 1 ) ) ) );
		return [
			'response' => [ 'code' => $response->get_status(), 'message' => '' ],
			'body'     => wp_json_encode( $response->get_data() ),
			'headers'  => [], 'cookies' => [], 'filename' => null,
		];
	}

	/**
	 * Fails every insert into the transfers table, as a lost connection or lock timeout would.
	 */
	public function break_transfer_inserts( string $query ): string {
		global $wpdb;
		return preg_match( "/^\s*INSERT INTO `?{$wpdb->prefix}be_character_transfers`?/i", $query )
			? 'INSERT INTO be_no_such_table VALUES (1)'
			: $query;
	}

	/**
	 * The route's own check misses an offer, as it would one another request wrote a moment later.
	 */
	public function hide_the_open_offer_once( string $query ): string {
		if ( $this->hidden_checks === 0 && preg_match( "/be_character_transfers WHERE character_uuid = .* AND direction = 'inbound'/", $query ) ) {
			++$this->hidden_checks;
			return (string) preg_replace( '/ ORDER BY /', ' AND 1 = 0 ORDER BY ', $query, 1 );
		}
		return $query;
	}

	private function with_broken_inserts( callable $call ) {
		global $wpdb;
		add_filter( 'query', [ $this, 'break_transfer_inserts' ] );
		$quiet = $wpdb->suppress_errors( true );
		try {
			return $call();
		} finally {
			$wpdb->suppress_errors( $quiet );
			remove_filter( 'query', [ $this, 'break_transfer_inserts' ] );
		}
	}

	private function offer(): \WP_REST_Response {
		$export = Character_Exporter::export( $this->character_id, [ 'as_transfer' => true ] );
		preg_match( '/code=([A-Za-z0-9-]+)/', $export['xml'], $m );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->host_slug}/transfers/inbound" );
		foreach ( [ 'payload' => $export['xml'], 'short_code' => $m[1], 'home_site' => home_url(), 'home_slug' => $this->home_slug, 'home_chronicle' => 'Row Home' ] as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function inbound_row( string $host_slug ): array {
		return [
			'character_uuid' => $this->character->uuid, 'character_name' => 'Row Traveller',
			'direction' => 'inbound', 'state' => 'offered',
			'home_slug' => $this->home_slug, 'home_site' => home_url(), 'home_chronicle' => 'Row Home',
			'host_slug' => $host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Row Host',
			'payload_hash' => str_repeat( 'a', 64 ), 'initiated_by' => 1,
		];
	}

	public function test_an_offer_whose_row_did_not_save_is_answered_with_an_error(): void {
		$response = $this->with_broken_inserts( fn() => $this->offer() );

		$this->assertSame( 500, $response->get_status() );
		$this->assertNull( Transfer::find_open( $this->character->uuid, 'inbound' ) );
	}

	public function test_an_offer_that_arrived_during_the_check_is_answered_already_offered(): void {
		Transfer::create( $this->inbound_row( $this->host_slug ) );

		add_filter( 'query', [ $this, 'hide_the_open_offer_once' ] );
		$response = $this->offer();
		remove_filter( 'query', [ $this, 'hide_the_open_offer_once' ] );

		$this->assertSame( 1, $this->hidden_checks, 'the route checked for an open offer' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'already_offered', $response->get_data()['code'] );
	}

	public function test_a_send_whose_row_did_not_save_is_answered_with_an_error(): void {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->home_slug}/transfers/outbound" );
		$request->set_param( 'character_id', $this->character_id );

		$response = $this->with_broken_inserts( static fn() => rest_get_server()->dispatch( $request ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertNull( Transfer::find_open( $this->character->uuid, 'outbound' ) );
	}

	public function test_another_transfer_waits_while_one_is_checked_and_written(): void {
		$demo = Game::find_by_slug( 'be-demo' );
		$this->assertNotNull( $demo, 'The demo chronicle is seeded.' );

		$lockable = null;
		$probe    = static function ( $query ) use ( &$lockable, $demo ) {
			if ( $lockable === null && preg_match( '/^\s*INSERT INTO `?\w*be_character_transfers`?/i', $query ) ) {
				$lockable = RowLockProbe::could_lock( 'games', (int) $demo->id );
			}
			return $query;
		};

		add_filter( 'query', $probe );
		Transfer::create( $this->inbound_row( 'be-demo' ) );
		remove_filter( 'query', $probe );

		$this->assertFalse( $lockable, 'Another transfer into the chronicle could be checked and written mid-write.' );
	}
}
