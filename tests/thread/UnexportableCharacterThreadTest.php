<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Character_Exporter;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A creature type an administrator adds has no Grapevine equivalent: exporting, transferring, comparing or verifying
 * one of its characters is refused cleanly.
 */
class UnexportableCharacterThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-unexportable';
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->slug, 'name' => 'Unexportable' ] );
		Creature_Stack::create( [ 'slug' => 'thread-ghoul', 'name' => 'Ghoul', 'stack_definition' => [ 'sections' => [] ] ] );
		$this->character = Character::create( [
			'name' => 'Loyal Retainer', 'stack_slug' => 'thread-ghoul', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'status' => 'active',
		] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function post( string $route, array $params = [] ) {
		$request = new WP_REST_Request( 'POST', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_exporting_says_the_creature_type_has_no_grapevine_equivalent(): void {
		$response = $this->post( "/be/v1/{$this->slug}/characters/{$this->character}/export" );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'not_exportable', $response->as_error()->get_error_code() );
		$this->assertStringContainsString( 'Grapevine', $response->as_error()->get_error_message() );
	}

	public function test_a_transfer_is_refused_before_anything_is_recorded(): void {
		global $wpdb;
		$snapshots = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_character_snapshots" );

		$response = $this->post( "/be/v1/{$this->slug}/transfers/outbound", [ 'character_id' => $this->character ] );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'not_exportable', $response->as_error()->get_error_code() );
		$this->assertNull( Transfer::find_open( Character::find( $this->character )->uuid, 'outbound' ) );
		$this->assertSame( $snapshots, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_character_snapshots" ) );
	}

	public function test_reviewing_an_arrival_named_like_one_still_works_without_a_comparison(): void {
		add_filter( 'pre_http_request', [ $this, 'loopback' ], 10, 3 );
		$home_slug = 'thread-unexportable-home';
		Game::create( [ 'slug' => $home_slug, 'name' => 'Unexportable Home' ] );
		$traveller = Character::create( [ 'name' => 'Loyal Retainer', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $home_slug, 'status' => 'active' ] );
		$uuid      = Character::find( $traveller )->uuid;
		$document  = Character_Exporter::export( $traveller, [ 'as_transfer' => true ] );
		preg_match( '/code=([A-Za-z0-9-]+)/', $document['xml'], $m );
		Character::delete( $traveller );

		$offered = $this->post( "/be/v1/{$this->slug}/transfers/inbound", [
			'payload' => $document['xml'], 'short_code' => $m[1], 'home_site' => home_url(),
			'home_slug' => $home_slug, 'home_chronicle' => 'Unexportable Home', 'character_uuid' => $uuid,
		] );
		$this->assertSame( 202, $offered->get_status(), wp_json_encode( $offered->get_data() ) );
		$review = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/transfers/" . Transfer::find_open( $uuid, 'inbound' )->id . '/review' ) );
		remove_filter( 'pre_http_request', [ $this, 'loopback' ], 10 );

		$this->assertSame( 200, $review->get_status(), wp_json_encode( $review->get_data() ) );
		$duplicate = $review->get_data()['preview']['duplicates'][0];
		$this->assertSame( 'name', $duplicate['matched_by'] );
		$this->assertArrayNotHasKey( 'changes', $duplicate );
	}

	public function test_verifying_a_code_whose_character_became_unexportable_reports_it_changed(): void {
		$vampire = Character::create( [ 'name' => 'Former Vampire', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'status' => 'active' ] );
		$code    = Character_Exporter::export( $vampire, [ 'verify' => true ] )['short_code'];
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_characters', [ 'stack_slug' => 'thread-ghoul' ], [ 'id' => $vampire ] );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/verify/{$code}" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['valid'] );
		$this->assertFalse( $response->get_data()['still_matches']['sheet'] );
		$this->assertNotNull( Attestation::resolve( $code ) );
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
}
