<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Activation seeds the schema blocks, moves the install onto the per-creature catalog, and only then seeds the
 * creature stacks from it.
 */
class ActivatorFreshInstallTest extends TestCase {

	private string $source;

	protected function setUp(): void {
		$this->source = (string) file_get_contents( BE_PLUGIN_PATH . '/includes/Core/Activator.php' );
	}

	public function test_activation_moves_the_install_after_the_blocks_and_before_the_stacks(): void {
		$blocks = strpos( $this->source, 'Seeder::seed_schema_blocks()' );
		$move   = strpos( $this->source, 'Catalog_Cutover::ensure_declared()' );
		$stacks = strpos( $this->source, 'Seeder::seed_creature_stacks()' );

		$this->assertNotFalse( $blocks, 'activation no longer seeds the schema blocks' );
		$this->assertNotFalse( $move, 'activation never moves the install onto the per-creature catalog' );
		$this->assertNotFalse( $stacks, 'activation no longer seeds the creature stacks' );
		$this->assertLessThan( $move, $blocks, 'the move needs the declared blocks to exist' );
		$this->assertLessThan( $stacks, $move, 'the stacks are seeded before the install is moved' );
	}

	public function test_a_refused_move_stops_activation_before_the_stacks(): void {
		$this->assertMatchesRegularExpression(
			'/refusal_message\(\s*\$move\s*\)[^}]*return;\s*\}\s*Seeder::seed_creature_stacks\(\)/s',
			$this->source,
			'a refusal must return before the stacks are seeded'
		);
	}
}
