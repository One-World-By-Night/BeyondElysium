<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The lists of names and ratings a character keeps (Bonds, Guanxi) are declared unpriced and numbers-only in the shipped
 * catalog; the lists that are bought with XP are not.
 */
class UnpricedListsDeclaredTest extends TestCase {

	/** @return array<string,mixed> */
	private function definition( string $slug ): array {
		$path = __DIR__ . '/../../beyond-elysium/data/catalog/blocks/' . $slug . '.json';
		$data = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $data, "{$slug}.json must decode" );
		return $data['definition'];
	}

	/** @return array<string,array{0:string}> */
	public static function unpriced_lists(): array {
		return [ 'Vampire Bonds' => [ 'vampire-bonds' ], 'Kuei-Jin Guanxi' => [ 'kueijin-guanxi' ] ];
	}

	/** @dataProvider unpriced_lists */
	public function test_a_list_of_names_and_ratings_is_declared_unpriced_and_numbers_only( string $slug ): void {
		$definition = $this->definition( $slug );

		$this->assertTrue( $definition['unpriced'] ?? null, "{$slug} is never bought with XP" );
		$this->assertFalse( $definition['print_rings'] ?? null, "{$slug} shows numbers, never circles" );
		$this->assertTrue( $definition['allow_custom'] ?? null, "{$slug} takes any name" );
		$this->assertSame( [], $definition['items'], "{$slug} has no catalog of names" );
		$this->assertSame( 'multiplier', $definition['display'] );
	}

	/** @return array<string,array{0:string}> */
	public static function priced_lists(): array {
		return [ 'Vampire Merits' => [ 'vampire-merits' ], 'Vampire Backgrounds' => [ 'vampire-backgrounds' ], 'Vampire Abilities' => [ 'vampire-abilities' ] ];
	}

	/** @dataProvider priced_lists */
	public function test_a_list_bought_with_xp_is_not_declared_unpriced( string $slug ): void {
		$this->assertArrayNotHasKey( 'unpriced', $this->definition( $slug ) );
	}
}
