<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Services\Catalog_Reader;
use WP_UnitTestCase;

/**
 * The demo characters hold each list on the block their creature type keeps it on.
 */
class SeederDemoFixturesThreadTest extends WP_UnitTestCase {

	/** @return array<string,mixed> */
	private function fixture( array $fixtures, string $name ): array {
		foreach ( $fixtures as $f ) {
			if ( $f['name'] === $name ) {
				return $f;
			}
		}
		$this->fail( "no demo fixture named \"{$name}\"" );
	}

	public function test_every_demo_character_holds_only_blocks_its_stack_declares(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}

		$declared = [];
		foreach ( Catalog_Reader::stacks_to_seed() as $slug => $stack ) {
			foreach ( $stack['stack_definition']['sections'] as $section ) {
				$declared[ $slug ][] = $section['block_slug'];
				if ( ! empty( $section['negative_block_slug'] ) ) {
					$declared[ $slug ][] = $section['negative_block_slug'];
				}
			}
		}

		$fixtures = Seeder::demo_fixtures();
		$this->assertCount( 22, $fixtures );
		foreach ( $fixtures as $f ) {
			$this->assertSame( [], array_values( array_diff( array_keys( $f['sheet_data'] ), $declared[ $f['stack_slug'] ] ) ), $f['name'] );
		}
	}

	public function test_a_character_holds_its_abilities_on_its_own_creature_types_block(): void {
		$rosa = $this->fixture( Seeder::demo_fixtures(), 'Detective Rosa Alvarez' );
		$this->assertArrayHasKey( 'mortal-abilities', $rosa['sheet_data'] );
		$this->assertArrayNotHasKey( 'met-abilities', $rosa['sheet_data'] );
		$this->assertContains( 'Investigation', array_column( $rosa['sheet_data']['mortal-abilities'], 'name' ) );
	}

	public function test_a_demon_holds_its_lores_as_specializations_of_the_abilities_block(): void {
		$meridian = $this->fixture( Seeder::demo_fixtures(), 'Meridian' );
		$this->assertArrayNotHasKey( 'demon-lores', $meridian['sheet_data'] );
		$this->assertArrayHasKey( 'demon-abilities', $meridian['sheet_data'] );

		$lore_specializations = array_column(
			array_filter( $meridian['sheet_data']['demon-abilities'], static fn( $item ) => $item['name'] === 'Lore' ),
			'specialization'
		);
		sort( $lore_specializations );
		$this->assertSame( [ 'Vampire', 'Werewolf' ], $lore_specializations );
	}

	public function test_a_mortal_holds_its_numina_on_its_own_block(): void {
		$samuel = $this->fixture( Seeder::demo_fixtures(), 'Samuel Ostrowski' );
		$this->assertArrayNotHasKey( 'mortal-numina', $samuel['sheet_data'] );
		$this->assertSame( [ 'Berserker' ], array_column( $samuel['sheet_data']['mortal-fomori'], 'name' ) );
	}
}
