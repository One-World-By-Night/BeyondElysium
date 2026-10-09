<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Keep_Current;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The host's own side of a kept-current delivery: `from-home`'s `update` type.
 */
class KeepCurrentApplyThreadTest extends WP_UnitTestCase {

	private string $home_slug = 'thread-keep-apply-home';
	private string $host_slug = 'thread-keep-apply-host';
	private int $host_character_id;
	private object $host_character;
	private int $visit_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->host_slug, 'name' => 'Keep Apply Host',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );

		$this->host_character_id = Character::create( [
			'name' => 'Visiting Copy', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->host_slug, 'status' => 'active',
			'sheet_data' => [ 'vampire-disciplines' => [ [ 'name' => 'Celerity', 'level' => 1, 'custom' => false ] ] ],
			'xp_earned' => 10, 'xp_unspent' => 5,
		] );
		$this->host_character = Character::find( $this->host_character_id );

		$this->visit_id = Transfer::create( [
			'character_uuid' => $this->host_character->uuid, 'character_id' => $this->host_character_id,
			'direction' => 'inbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Keep Apply Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Keep Apply Host',
			'payload_hash' => str_repeat( 'a', 64 ), 'initiated_by' => 1,
		] );
		Transfer::transition( $this->visit_id, 'visiting', [ 'keep_current' => 1, 'keep_current_accepted' => 1 ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function post( string $path, array $params = [] ) {
		$request = new WP_REST_Request( 'POST', $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A valid `/verify/` response naming the real issuer and the given hash.
	 */
	private function valid_verify_response( string $hash ): callable {
		$callback = function ( $preempt, $args, $url ) use ( $hash ) {
			if ( strpos( $url, '/verify/' ) === false ) {
				return $preempt;
			}
			return [
				'response' => [ 'code' => 200, 'message' => '' ],
				'body'     => wp_json_encode( [
					'valid' => true, 'revoked' => false, 'kind' => 'transfer',
					'issuer' => [ 'site' => home_url() ],
					'attested' => [ 'sheet_hash' => $hash ],
				] ),
				'headers' => [], 'cookies' => [], 'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );
		return $callback;
	}

	private function update_body( array $overrides = [] ): array {
		return array_merge( [
			'type'       => 'update',
			'home_site'  => home_url(),
			'home_slug'  => $this->home_slug,
			'code'       => 'WHATEVER-CODE',
			'uuid'       => $this->host_character->uuid,
			'name'       => 'Visiting Copy',
			'sheet_data' => [ 'vampire-disciplines' => [ [ 'name' => 'Celerity', 'level' => 2, 'custom' => false ] ] ],
			'xp_earned'  => 10,
			'xp_unspent' => 5,
			'sequence'   => 1,
		], $overrides );
	}

	public function test_an_older_sequence_is_refused(): void {
		Transfer::set_delivered_sequence( $this->visit_id, 5 );

		$body     = $this->update_body( [ 'sequence' => 3 ] );
		$callback = $this->valid_verify_response( Keep_Current::canonical_hash( $body ) );
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/{$this->host_character->uuid}/from-home", $body );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'stale_sequence', $response->as_error()->get_error_code() );
		$this->assertSame( 1, (int) Character::find( $this->host_character_id )->sheet_data['vampire-disciplines'][0]['level'], 'nothing applied' );
	}

	public function test_a_revoked_code_is_refused(): void {
		$body     = $this->update_body();
		$callback = function ( $preempt, $args, $url ) {
			if ( strpos( $url, '/verify/' ) === false ) {
				return $preempt;
			}
			return [
				'response' => [ 'code' => 200, 'message' => '' ],
				'body'     => wp_json_encode( [ 'valid' => false, 'revoked' => true, 'kind' => 'transfer' ] ),
				'headers' => [], 'cookies' => [], 'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/{$this->host_character->uuid}/from-home", $body );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'verify_failed', $response->as_error()->get_error_code() );
	}

	public function test_an_update_naming_another_home_site_is_refused(): void {
		$body = $this->update_body( [ 'home_site' => 'https://not-the-real-home.example' ] );
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/{$this->host_character->uuid}/from-home", $body );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'transfer_not_found', $response->as_error()->get_error_code() );
	}

	public function test_host_owned_connections_are_untouched(): void {
		$host_game_id = (int) Game::find_by_slug( $this->host_slug )->id;
		Connection::create( [ 'game_id' => $host_game_id, 'source_type' => 'character', 'source_id' => $this->host_character_id, 'target_type' => 'tag', 'label' => 'A Host Tag' ] );

		$body     = $this->update_body();
		$callback = $this->valid_verify_response( Keep_Current::canonical_hash( $body ) );
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/{$this->host_character->uuid}/from-home", $body );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$connections = Connection::for_entity( 'character', $this->host_character_id );
		$labels      = array_column( $connections, 'label' );
		$this->assertContains( 'A Host Tag', $labels, 'the host-owned connection survives untouched' );
	}

	public function test_a_custom_entry_is_recorded_in_the_log(): void {
		$body = $this->update_body( [
			'sheet_data' => [ 'vampire-disciplines' => [ [ 'name' => 'Not A Real Discipline At All', 'level' => 1, 'custom' => false ] ] ],
		] );
		$callback = $this->valid_verify_response( Keep_Current::canonical_hash( $body ) );
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/{$this->host_character->uuid}/from-home", $body );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$held = Character::find( $this->host_character_id )->sheet_data['vampire-disciplines'][0];
		$this->assertTrue( $held['custom'], 'the host catalog does not have it, so it lands custom here' );

		$log = Transfer::find( $this->visit_id )->update_log;
		$this->assertStringContainsString( 'Not A Real Discipline At All', $log[0]['custom'][0] );
		$this->assertSame( [ 'vampire-disciplines' ], $log[0]['changed'] );
	}

	public function test_a_snapshot_is_taken_before_the_update(): void {
		$before_count = Snapshot::count_for_character( $this->host_character_id );

		$body     = $this->update_body();
		$callback = $this->valid_verify_response( Keep_Current::canonical_hash( $body ) );
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/{$this->host_character->uuid}/from-home", $body );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $before_count + 1, Snapshot::count_for_character( $this->host_character_id ) );
	}

	public function test_markup_in_an_update_is_cleaned_and_plain_text_is_left_alone(): void {
		$body = $this->update_body( [
			'name'       => 'Visiting <b>Copy</b><script>alert(1)</script>',
			'sheet_data' => [
				'vampire-disciplines' => [ [ 'name' => 'Celerity', 'level' => 2, 'custom' => false ] ],
				'npc-quick-stats'     => [ 'notes' => '<p>Calm.</p><script>alert(1)</script><img src=x onerror=alert(1)>' ],
				'vampire-merits'      => [ [ 'name' => 'Arts & Letters', 'custom' => true ] ],
			],
		] );
		$callback = $this->valid_verify_response( Keep_Current::canonical_hash( $body ) );
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/{$this->host_character->uuid}/from-home", $body );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$character = Character::find( $this->host_character_id );
		$this->assertSame( 'Visiting Copy', $character->name );
		$notes = (string) $character->sheet_data['npc-quick-stats']['notes'];
		$this->assertStringStartsWith( '<p>Calm.</p>', $notes );
		$this->assertStringNotContainsString( '<script', $notes );
		$this->assertStringNotContainsString( 'onerror', $notes );
		$this->assertSame( 'Arts & Letters', $character->sheet_data['vampire-merits'][0]['name'] );
	}

	public function test_a_matching_catalog_entry_stays_non_custom(): void {
		$body     = $this->update_body();
		$callback = $this->valid_verify_response( Keep_Current::canonical_hash( $body ) );
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/{$this->host_character->uuid}/from-home", $body );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$held = Character::find( $this->host_character_id )->sheet_data['vampire-disciplines'][0];
		$this->assertSame( 2, (int) $held['level'] );
		$this->assertFalse( $held['custom'] );
		$this->assertSame( 1, (int) Transfer::find( $this->visit_id )->delivered_sequence );
	}
}
