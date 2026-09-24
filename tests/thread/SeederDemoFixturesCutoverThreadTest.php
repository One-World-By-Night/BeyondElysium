<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use WP_UnitTestCase;

/**
 * C8: `Seeder::demo_fixtures()` maps every fixture's `sheet_data` keys through
 * `Catalog_Cutover::live_slug()`. Two fixture entries are not a 1:1 rename `live_slug()`
 * can follow at all - `demon-lores` (retired outright, D91) and the old `mortal-numina`
 * (split into eight real blocks, D90) - and were moved by hand directly in
 * `demo-characters.php`; this suite proves both landed somewhere real, not that
 * `live_slug()` did the moving.
 */
class SeederDemoFixturesCutoverThreadTest extends WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		Catalog_Cutover::reset_cache();
		parent::tearDown();
	}

	/** @return array<string,mixed> */
	private function fixture( array $fixtures, string $name ): array {
		foreach ( $fixtures as $f ) {
			if ( $f['name'] === $name ) {
				return $f;
			}
		}
		$this->fail( "no demo fixture named \"{$name}\"" );
	}

	public function test_generic_slugs_are_left_alone_in_legacy(): void {
		$rosa = $this->fixture( Seeder::demo_fixtures(), 'Detective Rosa Alvarez' );
		$this->assertArrayHasKey( 'met-abilities', $rosa['sheet_data'] );
	}

	public function test_generic_slugs_map_to_the_live_block_once_declared(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		update_option( Catalog_Cutover::OPTION, 'declared' );

		$rosa = $this->fixture( Seeder::demo_fixtures(), 'Detective Rosa Alvarez' );
		$this->assertArrayHasKey( 'mortal-abilities', $rosa['sheet_data'] );
		$this->assertArrayNotHasKey( 'met-abilities', $rosa['sheet_data'] );
		// Investigation, an ordinary item, survived the key rename untouched.
		$names = array_column( $rosa['sheet_data']['mortal-abilities'], 'name' );
		$this->assertContains( 'Investigation', $names );
	}

	public function test_the_hand_moved_demon_lores_content_lands_under_the_live_abilities_block(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		update_option( Catalog_Cutover::OPTION, 'declared' );

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

	public function test_the_hand_moved_mortal_numina_content_lands_under_its_own_real_block(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		update_option( Catalog_Cutover::OPTION, 'declared' );

		$samuel = $this->fixture( Seeder::demo_fixtures(), 'Samuel Ostrowski' );
		$this->assertArrayNotHasKey( 'mortal-numina', $samuel['sheet_data'] );
		$this->assertSame( [ 'Berserker' ], array_column( $samuel['sheet_data']['mortal-fomori'], 'name' ) );
	}
}
