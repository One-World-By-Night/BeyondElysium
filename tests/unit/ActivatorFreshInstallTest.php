<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Activation runs the same whole upgrade an update runs, and that upgrade seeds the schema blocks, moves the install
 * onto the per-creature catalog, and only then seeds the creature stacks from it.
 */
class ActivatorFreshInstallTest extends TestCase {

	private string $activator;

	private string $upgrade;

	protected function setUp(): void {
		$this->activator = (string) file_get_contents( BE_PLUGIN_PATH . '/includes/Core/Activator.php' );
		$schema          = (string) file_get_contents( BE_PLUGIN_PATH . '/includes/Database/Schema.php' );
		$start           = strpos( $schema, 'private static function run_upgrade(' );
		$this->assertNotFalse( $start, 'the upgrade routine is gone' );
		$this->upgrade = substr( $schema, (int) $start, (int) strpos( $schema, "\n\t}\n", (int) $start ) - (int) $start );
	}

	public function test_activation_runs_the_whole_upgrade_and_leaves_recording_the_version_to_it(): void {
		$this->assertStringContainsString( 'Schema::maybe_upgrade()', $this->activator );
		$this->assertStringNotContainsString( 'update_option(', $this->activator, 'activation must not record a version the upgrade has not reached' );
	}

	public function test_the_upgrade_moves_the_install_after_the_blocks_and_before_the_stacks(): void {
		$blocks = strpos( $this->upgrade, 'Seeder::seed_schema_blocks()' );
		$move   = strpos( $this->upgrade, 'Catalog_Cutover::ensure_declared()' );
		$stacks = strpos( $this->upgrade, 'Seeder::seed_creature_stacks()' );

		$this->assertNotFalse( $blocks, 'the upgrade no longer seeds the schema blocks' );
		$this->assertNotFalse( $move, 'the upgrade never moves the install onto the per-creature catalog' );
		$this->assertNotFalse( $stacks, 'the upgrade no longer seeds the creature stacks' );
		$this->assertLessThan( $move, $blocks, 'the move needs the declared blocks to exist' );
		$this->assertLessThan( $stacks, $move, 'the stacks are seeded before the install is moved' );
	}

	public function test_a_refused_move_stops_the_upgrade_before_the_stacks(): void {
		$this->assertMatchesRegularExpression(
			'/throw new \\\\RuntimeException\(\s*\\\\BeyondElysium\\\\Services\\\\Catalog_Cutover::refusal_message\(\s*\$move\s*\)\s*\);\s*\}.*Seeder::seed_creature_stacks\(\)/s',
			$this->upgrade,
			'a refusal must stop the upgrade before the stacks are seeded'
		);
	}
}
