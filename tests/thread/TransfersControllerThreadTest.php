<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Transfer;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The `Transfers_Controller` REST layer.
 */
class TransfersControllerThreadTest extends WP_UnitTestCase {

	private string $home_slug = 'thread-test-xfer-home';
	private string $host_slug = 'thread-test-xfer-host';
	private int $home_game_id;
	private int $host_game_id;
	private int $character_id;
	private object $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->home_slug, 'name' => 'Thread Test Xfer Home',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );
		$this->home_game_id = (int) $wpdb->insert_id;

		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->host_slug, 'name' => 'Thread Test Xfer Host',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );
		$this->host_game_id = (int) $wpdb->insert_id;

		$this->character_id = Character::create( [
			'name' => 'Transfer Test Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->home_slug,
			'status' => 'active',
		] );
		$this->character = Character::find( $this->character_id );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function post( string $path, array $params = [] ) {
		$request = new WP_REST_Request( 'POST', $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->dispatch( $request );
	}

	public function test_initiate_without_a_host_creates_an_offered_transfer_and_returns_the_document(): void {
		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/outbound", [ 'character_id' => $this->character_id ] );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'offered', $data['transfer']->state );
		$this->assertStringContainsString( '<vampire', $data['xml'] );
	}

	public function test_initiate_refuses_a_second_open_transfer_for_the_same_character(): void {
		$this->post( "/be/v1/{$this->home_slug}/transfers/outbound", [ 'character_id' => $this->character_id ] );
		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/outbound", [ 'character_id' => $this->character_id ] );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_release_moves_visiting_to_released(): void {
		$id = Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'payload_hash' => 'abc', 'initiated_by' => 1,
		] );

		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/{$id}/release" );
		$this->assertSame( 'released', $response->get_data()->state );
	}

	public function test_decline_moves_offered_to_declined(): void {
		$id = Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'offered', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'payload_hash' => 'abc', 'initiated_by' => 1,
		] );

		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/{$id}/decline" );
		$this->assertSame( 'declined', $response->get_data()->state );
	}

	public function test_decline_refuses_from_the_wrong_state(): void {
		$id = Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'payload_hash' => 'abc', 'initiated_by' => 1,
		] );

		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/{$id}/decline" );
		$this->assertSame( 409, $response->get_status() );
	}

	public function test_list_items_returns_rows_touching_this_game(): void {
		Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'offered', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'payload_hash' => 'abc', 'initiated_by' => 1,
		] );

		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->home_slug}/transfers" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
	}

	/**
	 * Routes both outbound-side HTTP calls (home's POST to the host, and the host's own callback to `/verify/{code}`) to
	 * a real internal REST dispatch.
	 */
	private function loopback_both_directions(): callable {
		$callback = function ( $preempt, $parsed_args, $url ) {
			if ( strpos( $url, '/verify/' ) !== false ) {
				$code     = rawurldecode( substr( $url, strrpos( $url, '/' ) + 1 ) );
				$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/verify/' . $code ) );
			} elseif ( strpos( $url, '/transfers/inbound' ) !== false ) {
				$body    = json_decode( (string) ( $parsed_args['body'] ?? '{}' ), true );
				$request = new WP_REST_Request( 'POST', "/be/v1/{$this->host_slug}/transfers/inbound" );
				foreach ( (array) $body as $key => $value ) {
					$request->set_param( $key, $value );
				}
				$response = rest_get_server()->dispatch( $request );
			} elseif ( preg_match( '#/([a-z0-9\-]+)/transfers/([^/]+)/(from-host|from-home)#', $url, $m ) ) {
				$body    = json_decode( (string) ( $parsed_args['body'] ?? '{}' ), true );
				$request = new WP_REST_Request( 'POST', "/be/v1/{$m[1]}/transfers/{$m[2]}/{$m[3]}" );
				foreach ( (array) $body as $key => $value ) {
					$request->set_param( $key, $value );
				}
				$response = rest_get_server()->dispatch( $request );
			} else {
				return $preempt;
			}

			return [
				'response' => [ 'code' => $response->get_status(), 'message' => '' ],
				'body'     => wp_json_encode( $response->get_data() ),
				'headers'  => [],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );
		return $callback;
	}

	public function test_full_online_round_trip_needs_a_storyteller_on_each_side(): void {
		$callback = $this->loopback_both_directions();

		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/outbound", [
			'character_id' => $this->character_id,
			'host_site'    => home_url(),
			'host_slug'    => $this->host_slug,
		] );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['host']['pending_review'] ?? false, wp_json_encode( $data['host'] ?? null ) );
		$this->assertSame( 'offered', $data['transfer']->state );
		$this->assertSame( 'Thread Test Xfer Host', $data['transfer']->host_chronicle );

		$offer = Transfer::find_open_visit( $this->character->uuid, home_url(), $this->host_slug );
		$this->assertSame( 'offered', $offer->state );
		$this->assertSame( 0, Character::count_for_game( $this->host_slug ), 'nothing arrives before the host accepts' );

		$accepted = $this->post( "/be/v1/{$this->host_slug}/transfers/{$offer->id}/accept", [
			'resolutions' => [ 'duplicates' => [ 'Transfer Test Vampire' => 'import_as_new' ] ],
		] );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $accepted->get_status(), wp_json_encode( $accepted->get_data() ) );
		$this->assertSame( 'visiting', Transfer::find( (int) $offer->id )->state );
		$this->assertSame( 1, Character::count_for_game( $this->host_slug ) );

		// The host's own accept calls home directly, and home's row moves by itself. It never takes the character
		// away either way (it still shows active at home throughout).
		$this->assertSame( 'visiting', Transfer::find( (int) $data['transfer']->id )->state );
	}

	public function test_inbound_rejects_a_payload_whose_hash_does_not_match_the_attestation(): void {
		$export = \BeyondElysium\Services\Character_Exporter::export( $this->character_id, [ 'as_transfer' => true ] );
		preg_match( '/code=([A-Za-z0-9-]+)/', $export['xml'], $m );

		$callback = $this->loopback_both_directions();
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/inbound", [
			'payload'        => $export['xml'] . '<!-- tampered -->',
			'short_code'     => $m[1],
			'home_site'      => home_url(),
			'home_slug'      => $this->home_slug,
			'home_chronicle' => 'Thread Test Xfer Home',
			'character_uuid' => $this->character->uuid,
		] );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'verify_failed', $response->as_error()->get_error_code() );
	}

	public function test_inbound_never_calls_a_link_local_home_site(): void {
		$export = \BeyondElysium\Services\Character_Exporter::export( $this->character_id, [ 'as_transfer' => true ] );
		preg_match( '/code=([A-Za-z0-9-]+)/', $export['xml'], $m );

		$called = [];
		$spy    = function ( $preempt, $args, $url ) use ( &$called ) {
			$called[] = $url;
			return new \WP_Error( 'blocked', 'no network in this test' );
		};
		add_filter( 'pre_http_request', $spy, 10, 3 );
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/inbound", [
			'payload'        => $export['xml'],
			'short_code'     => $m[1],
			'home_site'      => 'http://169.254.169.254',
			'home_slug'      => $this->home_slug,
			'home_chronicle' => 'Thread Test Xfer Home',
			'character_uuid' => $this->character->uuid,
		] );
		remove_filter( 'pre_http_request', $spy, 10 );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unsafe_site', $response->as_error()->get_error_code() );
		$this->assertSame( [], $called );
	}

	public function test_inbound_rejects_a_verified_export_dressed_up_as_a_transfer(): void {
		$export = \BeyondElysium\Services\Character_Exporter::export( $this->character_id, [ 'verify' => true ] );
		preg_match( '/code=([A-Za-z0-9-]+)/', $export['xml'], $m );
		$dressed = preg_replace( '/(<verification\b[^>]*?)\s*\/>/', '$1 character_uuid="' . $this->character->uuid . '"/>', $export['xml'], 1 );
		$this->assertStringContainsString( 'character_uuid=', $dressed );

		$callback = $this->loopback_both_directions();
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/inbound", [
			'payload'        => $dressed,
			'short_code'     => $m[1],
			'home_site'      => home_url(),
			'home_slug'      => $this->home_slug,
			'home_chronicle' => 'Thread Test Xfer Home',
			'character_uuid' => $this->character->uuid,
		] );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'verify_failed', $response->as_error()->get_error_code() );
		$this->assertNull( Transfer::find_open( $this->character->uuid, 'inbound' ) );
	}

	public function test_inbound_rejects_when_the_claimed_home_site_does_not_match_the_real_issuer(): void {
		$export = \BeyondElysium\Services\Character_Exporter::export( $this->character_id, [ 'as_transfer' => true ] );
		preg_match( '/code=([A-Za-z0-9-]+)/', $export['xml'], $m );

		// The loopback intercepts by PATH.
		$callback = $this->loopback_both_directions();
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/inbound", [
			'payload'        => $export['xml'],
			'short_code'     => $m[1],
			'home_site'      => 'https://not-the-real-issuer.example',
			'home_slug'      => $this->home_slug,
			'home_chronicle' => 'Thread Test Xfer Home',
			'character_uuid' => $this->character->uuid,
		] );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'verify_failed', $response->as_error()->get_error_code() );
	}

	public function test_inbound_reports_unreachable_when_the_home_site_cannot_be_contacted_at_all(): void {
		// No loopback registered here.
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/inbound", [
			'payload'        => '<?xml version="1.0"?><grapevine version="3.0"><vampire name="X"></vampire></grapevine>',
			'short_code'     => 'ABCD-EFGH',
			'home_site'      => 'https://this-host-does-not-resolve.invalid',
			'home_slug'      => $this->home_slug,
			'home_chronicle' => 'Thread Test Xfer Home',
			'character_uuid' => wp_generate_uuid4(),
		] );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'verify_unreachable', $response->as_error()->get_error_code() );
	}

	/**
	 * A fabricated `/verify/` response naming whatever issuer the test asks for, regardless of the code requested.
	 */
	private function spoofed_verify_response( string $issuer_site, string $hash = 'whatever' ): callable {
		$callback = function ( $preempt, $args, $url ) use ( $issuer_site, $hash ) {
			if ( strpos( $url, '/verify/' ) === false ) {
				return $preempt;
			}
			return [
				'response' => [ 'code' => 200, 'message' => '' ],
				'body'     => wp_json_encode( [
					'valid'    => true,
					'revoked'  => false,
					'kind'     => 'visit_item',
					'issuer'   => [ 'site' => $issuer_site ],
					'attested' => [ 'sheet_hash' => $hash ],
				] ),
				'headers' => [], 'cookies' => [], 'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );
		return $callback;
	}

	public function test_from_host_refuses_a_call_whose_verified_issuer_does_not_match_the_claimed_host_site(): void {
		$home_row = Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'offered', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Thread Test Xfer Host',
			'payload_hash' => 'abc', 'initiated_by' => 1,
		] );

		$callback = $this->spoofed_verify_response( 'https://attacker.example' );
		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/{$this->character->uuid}/from-host", [
			'type' => 'accept', 'host_site' => home_url(), 'host_slug' => $this->host_slug, 'code' => 'FAKE-CODE',
		] );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'verify_failed', $response->as_error()->get_error_code() );
		$this->assertSame( 'offered', Transfer::find( $home_row )->state, 'nothing changes without a matching issuer' );
	}

	public function test_from_host_refuses_a_revoked_code(): void {
		$home_row = Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'offered', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Thread Test Xfer Host',
			'payload_hash' => 'abc', 'initiated_by' => 1,
		] );

		$callback = function ( $preempt, $args, $url ) {
			if ( strpos( $url, '/verify/' ) === false ) {
				return $preempt;
			}
			return [
				'response' => [ 'code' => 200, 'message' => '' ],
				'body'     => wp_json_encode( [ 'valid' => false, 'revoked' => true, 'kind' => 'visit_item' ] ),
				'headers' => [], 'cookies' => [], 'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );
		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/{$this->character->uuid}/from-host", [
			'type' => 'accept', 'host_site' => home_url(), 'host_slug' => $this->host_slug, 'code' => 'REVOKED-CODE',
		] );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'verify_failed', $response->as_error()->get_error_code() );
		$this->assertSame( 'offered', Transfer::find( $home_row )->state );
	}

	public function test_sending_a_visitor_home_ends_the_home_row_too(): void {
		$character2 = Character::find( $this->character_id );
		$home_row   = Transfer::create( [
			'character_uuid' => $character2->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Thread Test Xfer Host',
			'payload_hash' => 'abc', 'initiated_by' => 1,
		] );
		$host_copy = Character::create( [
			'name' => 'Visiting Copy', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->host_slug, 'status' => 'active',
		] );
		$host_row = Transfer::create( [
			'character_uuid' => $character2->uuid, 'character_id' => $host_copy,
			'direction' => 'inbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Thread Test Xfer Host',
			'payload_hash' => 'def', 'initiated_by' => 1,
		] );

		$callback = $this->loopback_both_directions();
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/{$host_row}/send-home" );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'ended', $response->get_data()->state );
		$this->assertSame( 'ended', Transfer::find( $home_row )->state, 'home learns the visit ended too' );
	}

	public function test_releasing_a_visitor_retains_it_at_the_host(): void {
		$character2 = Character::find( $this->character_id );
		$home_row   = Transfer::create( [
			'character_uuid' => $character2->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Thread Test Xfer Host',
			'payload_hash' => 'abc', 'initiated_by' => 1,
		] );
		$host_copy = Character::create( [
			'name' => 'Visiting Copy', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->host_slug, 'status' => 'active',
		] );
		$host_row = Transfer::create( [
			'character_uuid' => $character2->uuid, 'character_id' => $host_copy,
			'direction' => 'inbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Thread Test Xfer Host',
			'payload_hash' => 'def', 'initiated_by' => 1,
		] );

		$callback = $this->loopback_both_directions();
		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/{$home_row}/release" );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'released', $response->get_data()->state );
		$this->assertSame( 'retained', Transfer::find( $host_row )->state, 'the host learns the character is now theirs for good' );
	}

	public function test_from_host_rate_limits_by_ip(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.88';
		$key = 'be_transfer_rl_' . sha1( '203.0.113.88' );
		delete_transient( $key );

		for ( $i = 0; $i < 10; $i++ ) {
			$response = $this->post( "/be/v1/{$this->home_slug}/transfers/" . wp_generate_uuid4() . '/from-host', [
				'type' => 'accept', 'host_site' => home_url(), 'host_slug' => $this->host_slug, 'code' => 'X',
			] );
			$this->assertNotSame( 429, $response->get_status(), "request {$i} should not be rate limited yet" );
		}
		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/" . wp_generate_uuid4() . '/from-host', [
			'type' => 'accept', 'host_site' => home_url(), 'host_slug' => $this->host_slug, 'code' => 'X',
		] );
		$this->assertSame( 429, $response->get_status() );

		delete_transient( $key );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_keep_current_set_at_send_and_accepted_at_review(): void {
		$callback = $this->loopback_both_directions();

		$sent = $this->post( "/be/v1/{$this->home_slug}/transfers/outbound", [
			'character_id' => $this->character_id, 'host_site' => home_url(), 'host_slug' => $this->host_slug,
			'keep_current' => true,
		] );
		$home_row = $sent->get_data()['transfer'];

		$offer = Transfer::find_open_visit( $this->character->uuid, home_url(), $this->host_slug, 'inbound' );
		$this->assertTrue( $offer->keep_current, 'the host sees the home request on review' );
		$this->assertFalse( $offer->keep_current_accepted );

		$accepted = $this->post( "/be/v1/{$this->host_slug}/transfers/{$offer->id}/accept", [
			'resolutions'            => [ 'duplicates' => [ 'Transfer Test Vampire' => 'import_as_new' ] ],
			'keep_current_accepted' => true,
		] );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $accepted->get_status(), wp_json_encode( $accepted->get_data() ) );
		$this->assertTrue( Transfer::find( (int) $offer->id )->keep_current );
		$this->assertTrue( Transfer::find( (int) $offer->id )->keep_current_accepted );
		$this->assertTrue( Transfer::find( (int) $home_row->id )->keep_current, 'home learns the host accepted' );
		$this->assertTrue( Transfer::find( (int) $home_row->id )->keep_current_accepted );
	}

	public function test_keep_current_set_later_and_accepted_later(): void {
		$character2 = Character::find( $this->character_id );
		$home_row   = Transfer::create( [
			'character_uuid' => $character2->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Thread Test Xfer Host',
			'payload_hash' => 'abc', 'initiated_by' => 1,
		] );
		$host_copy = Character::create( [
			'name' => 'Visiting Copy', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->host_slug, 'status' => 'active',
		] );
		$host_row = Transfer::create( [
			'character_uuid' => $character2->uuid, 'character_id' => $host_copy,
			'direction' => 'inbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Thread Test Xfer Host',
			'payload_hash' => 'def', 'initiated_by' => 1,
		] );

		$callback = $this->loopback_both_directions();

		$set = $this->post( "/be/v1/{$this->home_slug}/transfers/{$home_row}/keep-current", [ 'on' => true ] );
		$this->assertSame( 200, $set->get_status(), wp_json_encode( $set->get_data() ) );
		$this->assertTrue( Transfer::find( $home_row )->keep_current );
		$this->assertTrue( Transfer::find( $host_row )->keep_current, 'the host learns home is asking' );
		$this->assertFalse( Transfer::find( $host_row )->keep_current_accepted );

		$accept = $this->post( "/be/v1/{$this->host_slug}/transfers/{$host_row}/keep-current/accept" );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $accept->get_status(), wp_json_encode( $accept->get_data() ) );
		$this->assertTrue( Transfer::find( $host_row )->keep_current_accepted );
		$this->assertTrue( Transfer::find( $home_row )->keep_current_accepted, 'home learns the host accepted' );
	}

	public function test_keep_current_turned_off_by_either_side(): void {
		$character2 = Character::find( $this->character_id );
		$home_row   = Transfer::create( [
			'character_uuid' => $character2->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Thread Test Xfer Host',
			'payload_hash' => 'abc', 'initiated_by' => 1, 'keep_current' => true,
		] );
		$host_copy = Character::create( [
			'name' => 'Visiting Copy', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->host_slug, 'status' => 'active',
		] );
		$host_row = Transfer::create( [
			'character_uuid' => $character2->uuid, 'character_id' => $host_copy,
			'direction' => 'inbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Thread Test Xfer Host',
			'payload_hash' => 'def', 'initiated_by' => 1, 'keep_current' => true,
		] );
		Transfer::transition( $host_row, 'visiting', [ 'keep_current_accepted' => 1 ] );
		Transfer::transition( $home_row, 'visiting', [ 'keep_current_accepted' => 1 ] );

		// The host turns it off; home learns both its own flags are gone too.
		$callback = $this->loopback_both_directions();
		$off      = $this->post( "/be/v1/{$this->host_slug}/transfers/{$host_row}/keep-current", [ 'on' => false ] );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $off->get_status(), wp_json_encode( $off->get_data() ) );
		$this->assertFalse( Transfer::find( $host_row )->keep_current );
		$this->assertFalse( Transfer::find( $host_row )->keep_current_accepted );
		$this->assertFalse( Transfer::find( $home_row )->keep_current, 'home learns the host turned it off' );
		$this->assertFalse( Transfer::find( $home_row )->keep_current_accepted );
	}

	public function test_accepting_without_a_keep_current_request_never_sets_either_flag(): void {
		$callback = $this->loopback_both_directions();
		$this->post( "/be/v1/{$this->home_slug}/transfers/outbound", [
			'character_id' => $this->character_id, 'host_site' => home_url(), 'host_slug' => $this->host_slug,
		] );
		$offer = Transfer::find_open_visit( $this->character->uuid, home_url(), $this->host_slug, 'inbound' );

		$accepted = $this->post( "/be/v1/{$this->host_slug}/transfers/{$offer->id}/accept", [
			'resolutions'            => [ 'duplicates' => [ 'Transfer Test Vampire' => 'import_as_new' ] ],
			'keep_current_accepted' => true,
		] );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $accepted->get_status(), wp_json_encode( $accepted->get_data() ) );
		$this->assertFalse( Transfer::find( (int) $offer->id )->keep_current, 'home never asked' );
		$this->assertFalse( Transfer::find( (int) $offer->id )->keep_current_accepted, 'nothing to accept without a request' );
	}
}
