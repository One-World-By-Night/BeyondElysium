<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use PHPUnit\Framework\TestCase;

/**
 * `is_declared()`/`live_slug()` both read `get_option()`, which does not exist at all in
 * this suite's pure-unit process (confirmed directly - no WordPress polyfill defines it
 * here, unlike `wp_kses_post()`/`get_locale()`/etc in tests/bootstrap.php) - calling
 * either would fatal, not fail cleanly. `Catalog_Translator` (also option-backed) has the
 * identical shape and is covered only by a thread test for the same reason; `live_slug()`'s
 * option-backed behavior is `CatalogCutoverThreadTest`'s job, not this file's.
 *
 * `replacement_map()` has no such dependency - it only reads `Catalog_Reader`, which is
 * pure - so it and `reset_cache()` are covered here against the real shipped catalog.
 */
class CatalogCutoverTest extends TestCase {

	protected function tearDown(): void {
		Catalog_Cutover::reset_cache();
		parent::tearDown();
	}

	public function test_replacement_map_reads_the_real_vampire_retirements(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		$this->assertSame(
			[
				'met-abilities' => 'vampire-abilities',
				'met-merits'    => 'vampire-merits',
				'met-flaws'     => 'vampire-flaws',
			],
			Catalog_Cutover::replacement_map( 'vampire' )
		);
	}

	public function test_replacement_map_is_per_stack_not_global(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		// werewolf-rites is retired for Fera/Bete but stays live for Werewolf itself -
		// the C1 finding (a `replaces` slug can also be a real, currently-seeded block for
		// a different stack). The map must reflect that asymmetry, not a global retirement.
		$this->assertSame( 'fera-rites', Catalog_Cutover::replacement_map( 'fera' )['werewolf-rites'] );
		$this->assertArrayNotHasKey(
			'werewolf-rites',
			Catalog_Cutover::replacement_map( 'werewolf' ),
			'werewolf-rites is not retired for the werewolf stack itself'
		);
	}

	public function test_replacement_map_is_empty_for_a_stack_with_no_replaces(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		$this->assertSame( [], Catalog_Cutover::replacement_map( 'no-such-stack' ) );
	}

	public function test_reset_cache_clears_catalog_readers_own_cache(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		Catalog_Cutover::replacement_map( 'vampire' ); // populates Catalog_Reader's private $cache
		$cache_property = new \ReflectionProperty( Catalog_Reader::class, 'cache' );
		$cache_property->setAccessible( true );
		$this->assertNotEmpty( $cache_property->getValue(), 'precondition: Catalog_Reader cached something' );

		Catalog_Cutover::reset_cache();

		$this->assertSame( [], $cache_property->getValue(), 'reset_cache() must clear Catalog_Reader\'s cache too' );
	}
}
