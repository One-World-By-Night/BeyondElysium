<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use WP_UnitTestCase;

/**
 * `is_declared()`/`live_slug()` both read `get_option()`, which the pure-unit suite has no
 * polyfill for at all (confirmed directly - unlike `Catalog_Translator`'s own identical
 * shape, this class's option-backed half is covered only here, never in
 * `tests/unit/CatalogCutoverTest.php`). `replacement_map()`/`reset_cache()` are unit-tested
 * already, since they carry no WordPress dependency.
 */
class CatalogCutoverThreadTest extends WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		Catalog_Cutover::reset_cache();
		parent::tearDown();
	}

	public function test_is_declared_is_false_with_the_option_absent(): void {
		$this->assertFalse( Catalog_Cutover::is_declared() );
	}

	public function test_is_declared_is_true_once_the_option_is_set(): void {
		update_option( Catalog_Cutover::OPTION, 'declared' );
		$this->assertTrue( Catalog_Cutover::is_declared() );
	}

	public function test_is_declared_is_false_for_any_other_option_value(): void {
		// e.g. a future in-progress state written by apply() before it commits - only the
		// literal 'declared' means declared.
		update_option( Catalog_Cutover::OPTION, 'pending' );
		$this->assertFalse( Catalog_Cutover::is_declared() );
	}

	public function test_live_slug_is_the_identity_function_in_legacy(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		// A real retirement exists (met-abilities -> vampire-abilities), but with the
		// option absent this install is legacy and must not apply it.
		$this->assertSame( 'met-abilities', Catalog_Cutover::live_slug( 'vampire', 'met-abilities' ) );
	}

	public function test_live_slug_returns_the_real_replacement_once_declared(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		update_option( Catalog_Cutover::OPTION, 'declared' );
		$this->assertSame( 'vampire-abilities', Catalog_Cutover::live_slug( 'vampire', 'met-abilities' ) );
	}

	public function test_live_slug_leaves_an_unretired_slug_unchanged_once_declared(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		update_option( Catalog_Cutover::OPTION, 'declared' );
		$this->assertSame( 'vampire-identity', Catalog_Cutover::live_slug( 'vampire', 'vampire-identity' ) );
	}
}
