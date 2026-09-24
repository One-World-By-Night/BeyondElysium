<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A new install has to be declared before anything is seeded: the stacks, the default templates and the
 * demo characters each read the switch while they are built, so a call that came after them would leave a
 * new site on the shared lists it was meant to skip. Activation cannot be run inside the test suite (it
 * creates tables, and a DDL statement ends the harness's transaction), so this holds the order in the
 * source, and the behaviour itself is in `FreshInstallDeclaredThreadTest`.
 */
class ActivatorFreshInstallTest extends TestCase {

	private string $source;

	protected function setUp(): void {
		$this->source = (string) file_get_contents( BE_PLUGIN_PATH . '/includes/Core/Activator.php' );
	}

	public function test_activation_declares_a_new_install_before_it_seeds_a_stack(): void {
		$declare = strpos( $this->source, 'Catalog_Cutover::declare_fresh_install()' );
		$stacks  = strpos( $this->source, 'Seeder::seed_creature_stacks()' );

		$this->assertNotFalse( $declare, 'activation never declares a new install' );
		$this->assertNotFalse( $stacks, 'activation no longer seeds the creature stacks' );
		$this->assertLessThan( $stacks, $declare, 'a new install is declared after its stacks are seeded' );
	}

	public function test_activation_declares_only_a_new_install(): void {
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*\$fresh_install\s*\)\s*\{\s*(?:\\\\?BeyondElysium\\\\Services\\\\)?Catalog_Cutover::declare_fresh_install\(\)/',
			$this->source,
			'the call must sit inside `if ( $fresh_install )`, or every re-activation would flip an existing install'
		);
	}
}
