<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * GX-7: the public `GET /be/v1/verify/{code}` route - the plugin's first
 * unauthenticated REST endpoint. Confirms every response shape in §6.3
 * (valid, revoked, unknown, expired, malformed, rate-limited), that
 * `still_matches` is computed from a fresh canonical export rather than the
 * verify-embedded one (the two necessarily differ, since each carries its
 * own unique code), and that no request here requires being logged in.
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-7, §6.3
 */
class VerifyControllerThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-verify-game';
	private int $character_id;
	private object $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Verify Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [ 'st_comment_start' => '[ST]', 'st_comment_end' => '[/ST]' ] ),
		] );

		$this->character_id = Character::create( [
			'name' => 'Verify Test Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'status' => 'active', 'notes' => 'Public notes [ST]secret ST-only notes[/ST] end.',
		] );
		$this->character = Character::find( $this->character_id );

		// Every request in this suite is anonymous - proves the route needs no login at all.
		wp_set_current_user( 0 );
	}

	private function dispatch( string $code ) {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/verify/' . $code ) );
	}

	public function test_a_valid_code_returns_the_full_contract_shape(): void {
		$row = Attestation::issue( $this->character, 'gex', hash( 'sha256', 'canonical' ) );

		$response = $this->dispatch( $row->short_code );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['valid'] );
		$this->assertFalse( $data['revoked'] );
		$this->assertSame( 'Thread Test Verify Game', $data['issuer']['chronicle'] );
		$this->assertSame( $this->game_slug, $data['issuer']['slug'] );
		$this->assertSame( 'gex', $data['kind'] );
		$this->assertSame( 'Verify Test Vampire', $data['attested']['name'] );
		$this->assertArrayHasKey( 'still_matches', $data );
		$this->assertArrayHasKey( 'as_of', $data );
	}

	public function test_still_matches_is_computed_from_a_fresh_canonical_export_not_the_verify_document(): void {
		// This is the load-bearing property of the whole hash design: the embedded-URL
		// document minted by export(verify:true) is NEVER what gets hashed for the
		// attestation, since every re-export mints a fresh code/URL and would never
		// equal itself again. The canonical (id-empty) export is what's hashed both
		// at issue time and at check time.
		$result = \BeyondElysium\Services\Character_Exporter::export( $this->character_id, [ 'verify' => true ] );
		preg_match( '/code=([A-Za-z0-9-]+)/', $result['xml'], $m );
		$this->assertNotEmpty( $m[1] ?? null, 'the exported id field must carry a real verification code' );

		$response = $this->dispatch( $m[1] );
		$data     = $response->get_data();

		$this->assertTrue( $data['valid'] );
		$this->assertTrue( $data['still_matches']['name'] );
		$this->assertTrue( $data['still_matches']['status'] );
		$this->assertTrue( $data['still_matches']['xp_earned'] );
		$this->assertTrue( $data['still_matches']['xp_unspent'] );
		$this->assertTrue( $data['still_matches']['sheet'], 'the stored hash must match a fresh canonical re-export' );
	}

	public function test_still_matches_is_unaffected_by_the_exporting_players_own_hide_st_choice(): void {
		// A traveling player exporting their OWN character (the primary real-world case
		// this endpoint exists for) always exports with hide_st true, since they are never
		// a manager of their own sheet. still_matches() always re-checks with hide_st false
		// (Verify_Controller has no way to know what the original caller chose) - if the
		// attested hash were computed from the redacted document, this would permanently
		// mismatch with nothing having actually changed. Confirms the fix, not the design.
		$result = \BeyondElysium\Services\Character_Exporter::export( $this->character_id, [ 'hide_st' => true, 'verify' => true ] );
		$this->assertStringNotContainsString( 'secret ST-only notes', $result['xml'], 'the exporting player\'s own copy is still genuinely redacted' );

		preg_match( '/code=([A-Za-z0-9-]+)/', $result['xml'], $m );
		$data = $this->dispatch( $m[1] )->get_data();

		$this->assertTrue( $data['still_matches']['sheet'], 'hide_st must not affect what sheet_hash represents' );
	}

	public function test_still_matches_reports_false_after_the_character_changes(): void {
		$row = Attestation::issue( $this->character, 'gex', hash( 'sha256', 'stale-canonical' ) );

		global $wpdb;
		$wpdb->update( \BeyondElysium\Database\Manager::table( 'characters' ), [ 'name' => 'Renamed Later' ], [ 'id' => $this->character_id ] );

		$data = $this->dispatch( $row->short_code )->get_data();

		$this->assertFalse( $data['still_matches']['name'] );
		$this->assertFalse( $data['still_matches']['sheet'] );
	}

	public function test_still_matches_reports_every_field_false_when_the_character_was_deleted(): void {
		$row = Attestation::issue( $this->character, 'gex', 'whatever' );
		Character::delete( $this->character_id );

		$data = $this->dispatch( $row->short_code )->get_data();

		$this->assertTrue( $data['valid'] );
		$this->assertSame( [ 'name' => false, 'status' => false, 'xp_earned' => false, 'xp_unspent' => false, 'sheet' => false ], $data['still_matches'] );
	}

	public function test_a_revoked_attestation_reports_revoked_true_and_omits_still_matches(): void {
		$row = Attestation::issue( $this->character, 'gex', 'abc123' );
		Attestation::revoke( (int) $row->id );

		$response = $this->dispatch( $row->short_code );

		$this->assertSame( 200, $response->get_status(), 'revoked is a real 200, not a 404 - a receiver must tell "void" from "bogus"' );
		$data = $response->get_data();
		$this->assertFalse( $data['valid'] );
		$this->assertTrue( $data['revoked'] );
		$this->assertArrayNotHasKey( 'still_matches', $data );
	}

	public function test_an_unknown_code_is_a_bare_404(): void {
		$response = $this->dispatch( 'ZZZZ-ZZZZ' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( '', $response->as_error()->get_error_message() );
	}

	public function test_a_malformed_code_is_the_same_bare_404_as_unknown(): void {
		$response = $this->dispatch( 'TOOLONG12345' );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_an_expired_code_is_a_404_even_though_the_row_still_exists(): void {
		$row = Attestation::issue( $this->character, 'gex', 'abc123', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		$response = $this->dispatch( $row->short_code );

		$this->assertSame( 404, $response->get_status() );
		$this->assertNotNull( Attestation::find( (int) $row->id ), 'expiry is enforced at check time, not by deleting the row' );
	}

	public function test_the_response_never_discloses_the_character_uuid_or_local_id(): void {
		$row = Attestation::issue( $this->character, 'gex', 'abc123' );

		$data = $this->dispatch( $row->short_code )->get_data();
		$body = wp_json_encode( $data );

		$this->assertStringNotContainsString( $this->character->uuid, $body );
		foreach ( [ 'id', 'character_id', 'uuid', 'wp_user_id', 'sheet_data' ] as $forbidden_key ) {
			$this->assertArrayNotHasKey( $forbidden_key, $data );
			$this->assertArrayNotHasKey( $forbidden_key, $data['attested'] );
		}
	}

	public function test_get_is_rate_limited_after_thirty_requests_per_minute(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		$key = 'be_verify_rl_' . sha1( '203.0.113.77' );
		delete_transient( $key );

		for ( $i = 0; $i < 30; $i++ ) {
			$response = $this->dispatch( 'ZZZZ-ZZZZ' );
			$this->assertNotSame( 429, $response->get_status(), "request {$i} should not be rate limited yet" );
		}

		$response = $this->dispatch( 'ZZZZ-ZZZZ' );
		$this->assertSame( 429, $response->get_status() );

		delete_transient( $key );
		unset( $_SERVER['REMOTE_ADDR'] );
	}
}
