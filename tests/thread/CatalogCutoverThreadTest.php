<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Services\Catalog_Cutover;
use WP_UnitTestCase;

/**
 * Whether an install counts as on the per-creature catalog depends on the `be_catalog_cutover` option holding exactly
 * `declared`.
 */
class CatalogCutoverThreadTest extends WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		parent::tearDown();
	}

	public function test_is_declared_is_false_with_the_option_absent(): void {
		delete_option( Catalog_Cutover::OPTION );
		$this->assertFalse( Catalog_Cutover::is_declared() );
	}

	public function test_is_declared_is_true_once_the_option_is_set(): void {
		update_option( Catalog_Cutover::OPTION, 'declared' );
		$this->assertTrue( Catalog_Cutover::is_declared() );
	}

	public function test_is_declared_is_false_for_any_other_option_value(): void {
		update_option( Catalog_Cutover::OPTION, 'pending' );
		$this->assertFalse( Catalog_Cutover::is_declared() );
	}
}
