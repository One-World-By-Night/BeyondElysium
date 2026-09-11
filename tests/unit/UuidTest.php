<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Utils\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * UUIDv7 generation and validation (Decision 024, RFC 9562).
 *
 * @see BE_PROCESS/workflow-0.2.1.md Step 3
 */
class UuidTest extends TestCase {

	private const CANONICAL = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	public function test_generates_canonical_36_character_form(): void {
		$uuid = Uuid::v7();

		$this->assertSame( 36, strlen( $uuid ) );
		$this->assertMatchesRegularExpression( self::CANONICAL, $uuid );
	}

	public function test_version_nibble_is_always_7(): void {
		for ( $i = 0; $i < 2000; $i++ ) {
			$uuid = Uuid::v7();
			$this->assertSame( '7', $uuid[14], "Version nibble was not 7 in {$uuid}" );
		}
	}

	public function test_variant_nibble_is_always_rfc_9562(): void {
		$seen = [];

		for ( $i = 0; $i < 2000; $i++ ) {
			$uuid          = Uuid::v7();
			$seen[ $uuid[19] ] = true;
			$this->assertContains(
				$uuid[19],
				[ '8', '9', 'a', 'b' ],
				"Variant nibble outside 8-b in {$uuid}"
			);
		}

		// Over 2000 draws every variant should appear; a generator stuck on one value
		// would pass the assertion above while throwing away two bits of entropy.
		$this->assertCount( 4, $seen, 'Variant nibble is not uniformly distributed.' );
	}

	public function test_no_collisions_over_many_generations(): void {
		$seen = [];

		for ( $i = 0; $i < 50000; $i++ ) {
			$uuid = Uuid::v7();
			$this->assertArrayNotHasKey( $uuid, $seen, "Collision on {$uuid}" );
			$seen[ $uuid ] = true;
		}

		$this->assertCount( 50000, $seen );
	}

	public function test_is_time_ordered_across_milliseconds(): void {
		$first = Uuid::v7();
		usleep( 3000 );
		$second = Uuid::v7();

		$this->assertLessThan(
			0,
			strcmp( $first, $second ),
			'UUIDv7 must sort by creation time once the millisecond has advanced.'
		);
	}

	public function test_timestamp_round_trips(): void {
		$before = (int) round( microtime( true ) * 1000 );
		$uuid   = Uuid::v7();
		$after  = (int) round( microtime( true ) * 1000 );

		$encoded = Uuid::timestamp_of( $uuid );

		$this->assertNotNull( $encoded );
		$this->assertGreaterThanOrEqual( $before - 1, $encoded );
		$this->assertLessThanOrEqual( $after + 1, $encoded );
	}

	public function test_timestamp_of_rejects_non_v7(): void {
		$this->assertNull( Uuid::timestamp_of( '01945a3c-8b2f-4d4e-9a1b-3c5d7e9f1a2b' ) );
		$this->assertNull( Uuid::timestamp_of( 'nonsense' ) );
	}

	public function test_accepts_its_own_output(): void {
		for ( $i = 0; $i < 500; $i++ ) {
			$uuid = Uuid::v7();
			$this->assertTrue( Uuid::is_valid( $uuid ) );
			$this->assertTrue( Uuid::is_v7( $uuid ) );
		}
	}

	/**
	 * @dataProvider invalid_uuids
	 */
	public function test_rejects_malformed_input( string $candidate, string $why ): void {
		$this->assertFalse( Uuid::is_valid( $candidate ), $why );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function invalid_uuids(): array {
		return [
			'empty'            => [ '', 'empty string' ],
			'not a uuid'       => [ 'not-a-uuid', 'arbitrary text' ],
			'truncated'        => [ '01945a3c-8b2f-7d4e-9a1b', 'missing the last group' ],
			'no hyphens'       => [ '01945a3c8b2f7d4e9a1b3c5d7e9f1a2b', 'unhyphenated' ],
			'version 0'        => [ '01945a3c-8b2f-0d4e-9a1b-3c5d7e9f1a2b', 'version nibble 0 is not a valid UUID version' ],
			'bad variant'      => [ '01945a3c-8b2f-7d4e-ca1b-3c5d7e9f1a2b', 'variant nibble c is outside 8-b' ],
			'non hex'          => [ '01945a3c-8b2f-7d4e-9a1b-3c5d7e9f1a2z', 'trailing non-hex character' ],
			'too long'         => [ '01945a3c-8b2f-7d4e-9a1b-3c5d7e9f1a2bb', 'one character too long' ],
		];
	}

	public function test_is_v7_rejects_other_versions(): void {
		// A well-formed v4 UUID must pass is_valid but fail is_v7.
		$v4 = '01945a3c-8b2f-4d4e-9a1b-3c5d7e9f1a2b';

		$this->assertTrue( Uuid::is_valid( $v4 ) );
		$this->assertFalse( Uuid::is_v7( $v4 ) );
	}

	public function test_validation_is_case_insensitive(): void {
		$uuid = Uuid::v7();

		$this->assertTrue( Uuid::is_valid( strtoupper( $uuid ) ) );
		$this->assertTrue( Uuid::is_v7( strtoupper( $uuid ) ) );
	}
}
