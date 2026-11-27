<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Oat_Client;
use BeyondElysium\Services\Oat_Connection;
use PHPUnit\Framework\TestCase;

/**
 * How an answer from the Archivist Toolkit's gateway reads, what BE sends with a call, and which chronicle a game is.
 */
class OatClientTest extends TestCase {

	public function test_a_200_with_an_object_is_an_answer(): void {
		$result = Oat_Client::interpret( 200, '{"entry_id":33012,"status":"pending","current_step":"staff_review","created":true}' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 33012, $result['data']['entry_id'] );
		$this->assertFalse( $result['challenge'] );
	}

	public function test_a_200_with_a_list_is_an_answer(): void {
		$result = Oat_Client::interpret( 200, '[{"uuid":"a","character_name":"Isolde"},{"uuid":"b","character_name":"Isolda"}]' );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 2, $result['data'] );
	}

	public function test_the_toolkits_own_refusal_is_kept_word_for_word(): void {
		$body   = '{"code":"oat_coordinator_required","message":"Tick the coordinator(s) who must approve this."}';
		$result = Oat_Client::interpret( 400, $body );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'oat_coordinator_required', $result['code'] );
		$this->assertSame( 'Tick the coordinator(s) who must approve this.', $result['message'] );
		$this->assertSame( 400, $result['status'] );
	}

	public function test_a_refusal_for_a_character_that_is_not_theirs_keeps_its_message(): void {
		$result = Oat_Client::interpret( 422, '{"code":"oat_character_not_yours","message":"You can\'t file this for that character."}' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'oat_character_not_yours', $result['code'] );
		$this->assertSame( "You can't file this for that character.", $result['message'] );
	}

	public function test_a_json_error_from_the_gateway_is_a_refusal_not_a_silence(): void {
		$result = Oat_Client::interpret( 502, '{"code":"oat_sso_error","message":"SSO verification failed."}' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'oat_sso_error', $result['code'] );
		$this->assertSame( 'SSO verification failed.', $result['message'] );
	}

	public function test_a_refusal_with_no_message_still_says_something(): void {
		$result = Oat_Client::interpret( 403, '{"code":"rest_forbidden"}' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'rest_forbidden', $result['code'] );
		$this->assertNotSame( '', $result['message'] );
	}

	public function test_a_hosting_challenge_reads_as_no_answer_and_is_flagged(): void {
		$result = Oat_Client::interpret( 202, '<html><body>Please wait</body></html>', [ 'sg-captcha' => 'challenge' ] );

		$this->assertFalse( $result['ok'] );
		$this->assertTrue( $result['challenge'] );
		$this->assertSame( 'oat_unreachable', $result['code'] );
		$this->assertStringContainsString( "didn't answer", $result['message'] );
	}

	public function test_a_202_without_the_header_is_still_a_challenge(): void {
		$result = Oat_Client::interpret( 202, '' );

		$this->assertFalse( $result['ok'] );
		$this->assertTrue( $result['challenge'] );
	}

	public function test_a_page_that_is_not_json_is_no_answer(): void {
		$result = Oat_Client::interpret( 200, '<html>maintenance</html>' );

		$this->assertFalse( $result['ok'] );
		$this->assertFalse( $result['challenge'] );
		$this->assertSame( 'oat_not_json', $result['code'] );
		$this->assertStringContainsString( "didn't answer", $result['message'] );
	}

	public function test_json_that_is_not_an_object_or_list_is_no_answer(): void {
		foreach ( [ 'null', '"ok"', '12', 'true' ] as $body ) {
			$result = Oat_Client::interpret( 200, $body );
			$this->assertFalse( $result['ok'], $body );
			$this->assertSame( 'oat_not_json', $result['code'], $body );
		}
	}

	public function test_a_server_error_page_is_no_answer(): void {
		$result = Oat_Client::interpret( 500, '<h1>Error</h1>' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'oat_unreachable', $result['code'] );
	}

	public function test_nothing_back_at_all_is_no_answer(): void {
		$result = Oat_Client::interpret( 0, '' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'oat_unreachable', $result['code'] );
	}

	public function test_a_call_names_the_site_by_key_and_the_person_by_email(): void {
		$headers = Oat_Client::request_headers( 'k-secret-key', 'Alice@Example.org', '' );

		$this->assertSame( 'k-secret-key', $headers['x-api-key'] );
		$this->assertSame( 'Alice@Example.org', $headers['x-oat-user-email'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertArrayNotHasKey( 'x-oat-player-id', $headers );
	}

	public function test_a_player_id_is_sent_when_the_account_has_one(): void {
		$headers = Oat_Client::request_headers( 'k', 'alice@example.org', '4417' );

		$this->assertSame( '4417', $headers['x-oat-player-id'] );
	}

	public function test_the_chronicle_is_the_slug_after_chronicle_in_its_access_path(): void {
		$this->assertSame( 'kony', Oat_Connection::chronicle_slug( (object) [ 'asc_role_path' => 'chronicle/kony' ] ) );
		$this->assertSame( 'kony', Oat_Connection::chronicle_slug( (object) [ 'asc_role_path' => 'Chronicle/KONY/' ] ) );
		$this->assertSame( 'wtw', Oat_Connection::chronicle_slug( (object) [ 'asc_role_path' => ' chronicle/wtw ' ] ) );
	}

	public function test_a_game_with_no_chronicle_path_cannot_send(): void {
		$status = Oat_Connection::status( (object) [ 'asc_role_path' => '' ] );

		$this->assertFalse( $status['available'] );
		$this->assertSame( Oat_Connection::NO_PATH, $status['reason'] );
		$this->assertNull( $status['chronicle'] );
	}

	public function test_a_chronicle_with_a_path_but_no_owbn_core_cannot_send(): void {
		if ( function_exists( 'owc_get_mode' ) ) {
			$this->markTestSkipped( 'owbn-core is loaded in this process.' );
		}

		$status = Oat_Connection::status( (object) [ 'asc_role_path' => 'chronicle/kony' ] );

		$this->assertFalse( $status['available'] );
		$this->assertSame( Oat_Connection::NO_CORE, $status['reason'] );
		$this->assertSame( 'kony', $status['chronicle'] );
	}

	public function test_a_game_with_no_chronicle_path_has_no_chronicle(): void {
		foreach ( [ '', 'coordinator/brujah', 'chronicle', 'chronicle/', 'chronicle/kony/hst', 'chronicle/ko ny' ] as $path ) {
			$this->assertNull( Oat_Connection::chronicle_slug( (object) [ 'asc_role_path' => $path ] ), $path );
		}
		$this->assertNull( Oat_Connection::chronicle_slug( (object) [] ) );
	}
}
