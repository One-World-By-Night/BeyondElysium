<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Transfer;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 9: the `Transfers_Controller` REST layer.
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

	public function test_initiate_without_a_host_creates_a_pending_transfer_and_returns_the_document(): void {
		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/outbound", [ 'character_id' => $this->character_id ] );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'pending', $data['transfer']->state );
		$this->assertStringContainsString( '<vampire', $data['xml'] );
	}

	public function test_initiate_refuses_a_second_open_transfer_for_the_same_character(): void {
		$this->post( "/be/v1/{$this->home_slug}/transfers/outbound", [ 'character_id' => $this->character_id ] );
		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/outbound", [ 'character_id' => $this->character_id ] );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_acknowledge_moves_pending_to_abroad(): void {
		$id = Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'pending', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'payload_hash' => 'abc', 'initiated_by' => 1,
		] );

		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/{$id}/acknowledge" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'abroad', $response->get_data()->state );
	}

	public function test_acknowledge_refuses_from_the_wrong_state(): void {
		$id = Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'abroad', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'payload_hash' => 'abc', 'initiated_by' => 1,
		] );

		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/{$id}/acknowledge" );
		$this->assertSame( 409, $response->get_status() );
	}

	public function test_release_moves_abroad_to_released(): void {
		$id = Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'abroad', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'payload_hash' => 'abc', 'initiated_by' => 1,
		] );

		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/{$id}/release" );
		$this->assertSame( 'released', $response->get_data()->state );
	}

	public function test_decline_moves_pending_to_declined(): void {
		$id = Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'pending', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Thread Test Xfer Home',
			'payload_hash' => 'abc', 'initiated_by' => 1,
		] );

		$response = $this->post( "/be/v1/{$this->home_slug}/transfers/{$id}/decline" );
		$this->assertSame( 'declined', $response->get_data()->state );
	}

	public function test_list_items_returns_rows_touching_this_game(): void {
		Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'pending', 'home_slug' => $this->home_slug,
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
		$this->assertSame( 'pending', $data['transfer']->state );
		$this->assertSame( 'Thread Test Xfer Host', $data['transfer']->host_chronicle );

		$offer = Transfer::find_open( $this->character->uuid, 'inbound' );
		$this->assertSame( 'offered', $offer->state );
		$this->assertSame( 0, Character::count_for_game( $this->host_slug ), 'nothing arrives before the host accepts' );

		$accepted = $this->post( "/be/v1/{$this->host_slug}/transfers/{$offer->id}/accept", [
			'resolutions' => [ 'duplicates' => [ 'Transfer Test Vampire' => 'import_as_new' ] ],
		] );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $accepted->get_status(), wp_json_encode( $accepted->get_data() ) );
		$this->assertSame( 'visiting', Transfer::find( (int) $offer->id )->state );
		$this->assertSame( 1, Character::count_for_game( $this->host_slug ) );

		$home = $this->post( "/be/v1/{$this->home_slug}/transfers/{$data['transfer']->id}/acknowledge" );
		$this->assertSame( 'abroad', $home->get_data()->state );
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
}
