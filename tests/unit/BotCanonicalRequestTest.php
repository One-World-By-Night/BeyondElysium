<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Core\Bot_Signing;
use PHPUnit\Framework\TestCase;

/**
 * Bot_Signing builds the canonical string a bot's signed request is checked against, and the signature over it.
 */
class BotCanonicalRequestTest extends TestCase {

	/**
	 * @return array<int,array{0:array<string,mixed>}>
	 */
	public static function vectors(): array {
		$path    = BE_PLUGIN_ROOT . '/tests/fixtures/bot-signing-vectors.json';
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		return array_map( static fn( array $vector ) => [ $vector ], $decoded );
	}

	/**
	 * @dataProvider vectors
	 * @param array<string,mixed> $vector
	 */
	public function test_a_fixture_vector_reproduces_its_own_canonical_string_and_signature( array $vector ): void {
		$query = Bot_Signing::canonical_query( (array) $vector['query'] );
		$this->assertNotNull( $query, $vector['name'] );

		$canonical = Bot_Signing::canonical_string(
			$vector['method'],
			$vector['route'],
			(string) $query,
			$vector['timestamp'],
			$vector['nonce'],
			$vector['connection_id'],
			$vector['discord_user_id'],
			$vector['body']
		);

		$this->assertSame( $vector['canonical'], $canonical, $vector['name'] );

		$secret = Bot_Signing::base64url_decode( $vector['secret'] );
		$this->assertSame( $vector['signature'], Bot_Signing::sign( $canonical, $secret ), $vector['name'] );
		$this->assertTrue( Bot_Signing::verify( $canonical, $secret, $vector['signature'] ), $vector['name'] );
	}

	public function test_an_array_valued_query_parameter_is_refused(): void {
		$this->assertNull( Bot_Signing::canonical_query( [ 'ids' => [ '1', '2' ] ] ) );
	}

	public function test_rest_route_is_never_part_of_the_canonical_query(): void {
		$this->assertSame(
			'per_page=10',
			Bot_Signing::canonical_query( [ 'rest_route' => '/be/v1/kony/characters', 'per_page' => '10' ] )
		);
	}

	public function test_a_space_and_an_ampersand_in_a_value_are_percent_encoded(): void {
		$this->assertSame( 'q=a%20b%26c', Bot_Signing::canonical_query( [ 'q' => 'a b&c' ] ) );
	}

	public function test_base64url_round_trips_a_value_that_would_pad_under_plain_base64(): void {
		$bytes = random_bytes( 31 );
		$encoded = Bot_Signing::base64url_encode( $bytes );
		$this->assertStringNotContainsString( '=', $encoded );
		$this->assertStringNotContainsString( '+', $encoded );
		$this->assertStringNotContainsString( '/', $encoded );
		$this->assertSame( $bytes, Bot_Signing::base64url_decode( $encoded ) );
	}

	public function test_a_tampered_signature_does_not_verify(): void {
		$canonical = Bot_Signing::canonical_string( 'GET', '/be/v1/kony/bot/status', '', '1700000000', 'n', '7', '', '' );
		$secret    = random_bytes( 32 );
		$signature = Bot_Signing::sign( $canonical, $secret );
		$tampered  = substr( $signature, 0, -1 ) . ( $signature[ -1 ] === '0' ? '1' : '0' );
		$this->assertFalse( Bot_Signing::verify( $canonical, $secret, $tampered ) );
	}
}
