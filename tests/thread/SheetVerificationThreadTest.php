<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Services\Character_Exporter;
use BeyondElysium\Services\GEX_Xml_Parser;
use BeyondElysium\Services\Sheet_Verification;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Sheet_Verification::check() against a real verification code, resolved through a real internal REST dispatch via
 * `pre_http_request`.
 */
class SheetVerificationThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-sheet-verify';
	private int $character_id;
	private object $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Sheet Verify',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );

		$this->character_id = Character::create( [
			'name' => 'Verify Test Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'status' => 'active', 'notes' => '[ST]Only Storytellers should ever read this.[/ST]',
		] );
		$this->character = Character::find( $this->character_id );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Intercepts by URL substring.
	 */
	private function loopback(): callable {
		$callback = function ( $preempt, $parsed_args, $url ) {
			if ( strpos( $url, 'blackhole.invalid' ) !== false ) {
				return new WP_Error( 'http_request_failed', 'Simulated unreachable host.' );
			}
			if ( strpos( $url, '/verify/' ) === false ) {
				return $preempt;
			}
			$code     = rawurldecode( substr( $url, strrpos( $url, '/' ) + 1 ) );
			$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/verify/' . $code ) );
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

	/**
	 * Exports the test character exactly as a player would for their own verified sheet (hide_st true - the primary real
	 * case) and parses the resulting document back into the shape a Storyteller's review would hold it
	 * in.
	 *
	 * @return array{xml:string,character:array<string,mixed>}
	 */
	private function export_and_reparse(): array {
		$export    = Character_Exporter::export( $this->character_id, [ 'hide_st' => true, 'verify' => true ] );
		$parsed    = GEX_Xml_Parser::parse_string( $export['xml'] );
		return [ 'xml' => $export['xml'], 'character' => $parsed['characters'][0] ];
	}

	public function test_no_code_reads_status_none(): void {
		$result = Sheet_Verification::check( '<grapevine/>', [ 'name' => 'No Code' ] );
		$this->assertSame( [ 'status' => 'none' ], $result );
	}

	public function test_a_players_own_verified_export_with_st_notes_reads_unchanged(): void {
		$callback = $this->loopback();
		[ 'xml' => $xml, 'character' => $character ] = $this->export_and_reparse();

		$result = Sheet_Verification::check( $xml, $character );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 'unchanged', $result['status'] );
		$this->assertSame( 'document', $result['compared_with'] );
		$this->assertFalse( $result['home_changed'] );
		$this->assertTrue( $result['facts_match']['name'] );
		$this->assertTrue( $result['facts_match']['xp_earned'] );
		$this->assertTrue( $result['facts_match']['xp_unspent'] );
	}

	public function test_an_edited_file_reads_changed_with_the_mismatch_named(): void {
		$callback = $this->loopback();
		[ 'xml' => $xml ] = $this->export_and_reparse();

		$edited_xml       = str_replace( 'Verify Test Vampire', 'A Different Name Entirely', $xml );
		$edited_character = GEX_Xml_Parser::parse_string( $edited_xml )['characters'][0];

		$result = Sheet_Verification::check( $edited_xml, $edited_character );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 'changed', $result['status'] );
		$this->assertFalse( $result['facts_match']['name'] );
		$this->assertSame( 'A Different Name Entirely', $result['file']['name'] );
		$this->assertSame( 'Verify Test Vampire', $result['attested']['name'] );
	}

	public function test_a_revoked_code_reads_status_revoked(): void {
		$callback = $this->loopback();
		[ 'xml' => $xml, 'character' => $character ] = $this->export_and_reparse();

		$found = Sheet_Verification::code_from( $character );
		$this->assertNotNull( $found );
		$attestation = Attestation::resolve( $found['code'] );
		$this->assertNotNull( $attestation );
		Attestation::revoke( (int) $attestation->id );

		$result = Sheet_Verification::check( $xml, $character );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 'revoked', $result['status'] );
		$this->assertArrayNotHasKey( 'facts_match', $result );
	}

	public function test_an_unissued_code_reads_status_unknown(): void {
		$callback  = $this->loopback();
		$character = [ 'id' => home_url( '/be-verify/?code=ZZZZ-ZZZZ' ), 'name' => 'Nobody' ];

		$result = Sheet_Verification::check( '<grapevine/>', $character );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 'unknown', $result['status'] );
	}

	public function test_an_unreachable_host_reads_status_unreachable(): void {
		$callback  = $this->loopback();
		$character = [ 'id' => 'https://blackhole.invalid/be-verify/?code=ABCD-1234', 'name' => 'Nobody' ];

		$result = Sheet_Verification::check( '<grapevine/>', $character );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 'unreachable', $result['status'] );
	}

	/**
	 * The loopback resolves every /verify/ call against this same install regardless of the URL's own claimed host
	 * (matching TransfersControllerThreadTest's own "by path, not host" precedent).
	 */
	public function test_a_base_not_matching_the_real_issuer_reads_issuer_mismatch(): void {
		$callback = $this->loopback();
		[ 'character' => $real_character ] = $this->export_and_reparse();
		$found = Sheet_Verification::code_from( $real_character );
		$this->assertNotNull( $found );

		$character = $real_character;
		$character['id'] = 'https://not-the-real-site.example/be-verify/?code=' . $found['code'];

		$result = Sheet_Verification::check( '<grapevine/>', $character );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 'issuer_mismatch', $result['status'] );
	}

	public function test_a_pre_1_0_0_code_falls_back_to_sheet_hash_and_says_so(): void {
		$canonical  = Character_Exporter::export( $this->character_id, [ 'hide_st' => false ] );
		$sheet_hash = hash( 'sha256', $canonical['xml'] );

		$id = Manager::insert( 'character_attestations', [
			'character_uuid' => $this->character->uuid,
			'character_id'   => $this->character_id,
			'game_slug'      => $this->game_slug,
			'token'          => 'test-token-' . wp_generate_password( 12, false ),
			'short_code'     => 'OLD1-CODE',
			'kind'           => 'gex',
			'sheet_hash'     => $sheet_hash,
			'attested'       => wp_json_encode( [
				'name'       => $this->character->name,
				'stack'      => $this->character->stack_slug,
				'status'     => $this->character->status,
				'xp_earned'  => (int) $this->character->xp_earned,
				'xp_unspent' => (int) $this->character->xp_unspent,
				'sheet_hash' => $sheet_hash,
			] ),
			'issued_at'      => current_time( 'mysql', true ),
			'issued_by'      => get_current_user_id(),
			'expires_at'     => null,
		] );
		$this->assertNotFalse( $id );

		$callback = $this->loopback();
		$export   = Character_Exporter::export( $this->character_id, [ 'hide_st' => false ] );
		// The same canonical export, with the old code's URL spliced.
		$xml       = str_replace( ' id=""', ' id="' . home_url( '/be-verify/?code=OLD1-CODE' ) . '"', $export['xml'] );
		$character = GEX_Xml_Parser::parse_string( $xml )['characters'][0];

		$result = Sheet_Verification::check( $xml, $character );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 'unchanged', $result['status'] );
		$this->assertSame( 'sheet', $result['compared_with'] );
	}
}
