<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/fixtures/fake-owc-oat-connection.php';

use BeyondElysium\Services\Oat_Client;
use BeyondElysium\Services\Oat_Connection;
use WP_Error;
use WP_UnitTestCase;

/**
 * A call to the Archivist Toolkit's gateway made as a person, against a fake transport.
 */
class OatClientThreadTest extends WP_UnitTestCase {

	private const KEY  = 'k-thread-secret-0123456789abcdef';
	private const BASE = 'https://archivist.example.test/wp-json/owbn/v1/';

	/** @var array<int,array{url:string,args:array<string,mixed>}> */
	private array $sent = [];

	/** @var callable|null */
	private $answer = null;

	public function setUp(): void {
		parent::setUp();
		global $be_test_oat;
		$be_test_oat = [ '*' => [ 'mode' => 'remote', 'base' => self::BASE, 'key' => self::KEY ] ];
		$this->sent   = [];
		$this->answer = static fn() => [ 'code' => 200, 'body' => '{"entry_id":33012,"status":"pending","current_step":"staff_review","created":true}', 'headers' => [] ];

		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			$this->sent[] = [ 'url' => $url, 'args' => $args ];
			$reply        = ( $this->answer )( $url, $args );
			if ( $reply instanceof WP_Error ) {
				return $reply;
			}
			return [ 'headers' => $reply['headers'], 'body' => $reply['body'], 'response' => [ 'code' => $reply['code'], 'message' => '' ], 'cookies' => [], 'filename' => null ];
		}, 10, 3 );
	}

	public function tearDown(): void {
		global $be_test_oat;
		$be_test_oat = [];
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	private function user( ?string $email = null, array $meta = [] ): \WP_User {
		$email = $email ?? 'u' . strtolower( wp_generate_password( 8, false ) ) . '@example.org';
		$id    = self::factory()->user->create( [ 'user_email' => $email, 'role' => 'subscriber' ] );
		foreach ( $meta as $key => $value ) {
			update_user_meta( $id, $key, $value );
		}
		return get_user_by( 'id', $id );
	}

	private function game( string $path = 'chronicle/kony' ): object {
		return (object) [ 'asc_role_path' => $path ];
	}

	public function test_a_chronicle_with_a_path_and_the_connection_can_send(): void {
		$status = Oat_Connection::status( $this->game() );

		$this->assertTrue( $status['available'] );
		$this->assertSame( 'archivist.example.test', $status['host'] );
		$this->assertSame( 'kony', $status['chronicle'] );
		$this->assertNull( $status['reason'] );
	}

	public function test_a_site_in_local_mode_cannot_send(): void {
		global $be_test_oat;
		$be_test_oat['*']['mode'] = 'local';

		$status = Oat_Connection::status( $this->game() );

		$this->assertFalse( $status['available'] );
		$this->assertSame( Oat_Connection::NO_CONNECTION, $status['reason'] );
	}

	public function test_a_connection_without_an_address_or_a_key_cannot_send(): void {
		global $be_test_oat;
		foreach ( [ 'base', 'key' ] as $missing ) {
			$be_test_oat['*'] = [ 'mode' => 'remote', 'base' => self::BASE, 'key' => self::KEY ];
			$be_test_oat['*'][ $missing ] = '';
			$this->assertSame( Oat_Connection::NO_CONNECTION, Oat_Connection::status( $this->game() )['reason'], $missing );
		}
	}

	public function test_a_game_without_a_chronicle_path_cannot_send(): void {
		$status = Oat_Connection::status( $this->game( '' ) );

		$this->assertFalse( $status['available'] );
		$this->assertSame( Oat_Connection::NO_PATH, $status['reason'] );
	}

	public function test_a_call_carries_the_key_and_the_persons_own_email_and_posts_to_the_route(): void {
		$alice  = $this->user( 'alice@example.org' );
		$result = Oat_Client::call( 'submit', [ 'domain' => 'character_lifecycle', 'meta' => [ 'a' => 'b' ] ], $alice );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 33012, $result['data']['entry_id'] );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( self::BASE . 'oat/submit', $this->sent[0]['url'] );
		$this->assertSame( self::KEY, $this->sent[0]['args']['headers']['x-api-key'] );
		$this->assertSame( 'alice@example.org', $this->sent[0]['args']['headers']['x-oat-user-email'] );
		$this->assertSame( [ 'domain' => 'character_lifecycle', 'meta' => [ 'a' => 'b' ] ], json_decode( $this->sent[0]['args']['body'], true ) );
	}

	public function test_another_persons_email_goes_with_their_own_call(): void {
		Oat_Client::call( 'entry/5', [], $this->user( 'bob@example.org' ) );

		$this->assertSame( self::BASE . 'oat/entry/5', $this->sent[0]['url'] );
		$this->assertSame( 'bob@example.org', $this->sent[0]['args']['headers']['x-oat-user-email'] );
	}

	public function test_the_player_id_goes_along_when_the_account_has_one(): void {
		Oat_Client::call( 'submit', [], $this->user( 'alice@example.org', [ 'player_id' => '4417' ] ) );
		Oat_Client::call( 'submit', [], $this->user( 'bob@example.org' ) );

		$this->assertSame( '4417', $this->sent[0]['args']['headers']['x-oat-player-id'] );
		$this->assertArrayNotHasKey( 'x-oat-player-id', $this->sent[1]['args']['headers'] );
	}

	public function test_the_toolkits_refusal_comes_back_word_for_word(): void {
		$this->answer = static fn() => [ 'code' => 422, 'body' => '{"code":"oat_character_not_yours","message":"You can\'t file this for that character."}', 'headers' => [] ];

		$result = Oat_Client::call( 'submit', [], $this->user() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'oat_character_not_yours', $result['code'] );
		$this->assertSame( "You can't file this for that character.", $result['message'] );
		$this->assertSame( 422, $result['status'] );
	}

	public function test_a_transport_failure_is_no_answer(): void {
		$this->answer = static fn() => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$result = Oat_Client::call( 'submit', [], $this->user() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'oat_unreachable', $result['code'] );
		$this->assertStringNotContainsString( 'cURL', $result['message'] );
	}

	public function test_a_hosting_challenge_is_flagged(): void {
		$this->answer = static fn() => [ 'code' => 202, 'body' => '<html>wait</html>', 'headers' => [ 'sg-captcha' => 'challenge' ] ];

		$result = Oat_Client::call( 'entry/5', [], $this->user() );

		$this->assertFalse( $result['ok'] );
		$this->assertTrue( $result['challenge'] );
	}

	public function test_a_site_with_no_connection_makes_no_call(): void {
		global $be_test_oat;
		$be_test_oat = [];

		$result = Oat_Client::call( 'submit', [], $this->user() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'oat_not_connected', $result['code'] );
		$this->assertSame( [], $this->sent );
	}

	public function test_an_account_with_no_usable_email_makes_no_call(): void {
		$user             = $this->user();
		$user->user_email = 'not-an-email';

		$result = Oat_Client::call( 'submit', [], $user );

		$this->assertSame( 'oat_no_email', $result['code'] );
		$this->assertSame( [], $this->sent );
	}

	public function test_the_key_is_in_no_result(): void {
		foreach ( [
			static fn() => [ 'code' => 200, 'body' => '{"ok":1}', 'headers' => [] ],
			static fn() => [ 'code' => 400, 'body' => '{"code":"x","message":"no"}', 'headers' => [] ],
			static fn() => [ 'code' => 200, 'body' => 'nope', 'headers' => [] ],
			static fn() => new WP_Error( 'http_request_failed', 'boom' ),
		] as $answer ) {
			$this->answer = $answer;
			$result       = Oat_Client::call( 'submit', [], $this->user() );
			$this->assertStringNotContainsString( self::KEY, (string) wp_json_encode( $result ) );
		}
		$status = Oat_Connection::status( $this->game() );
		$this->assertStringNotContainsString( self::KEY, (string) wp_json_encode( $status ) );
	}
}
