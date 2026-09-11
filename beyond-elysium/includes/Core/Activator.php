<?php

namespace BeyondElysium\Core;

use BeyondElysium\Database\Schema;
use BeyondElysium\Database\Seeder;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin activation entry point.
 *
 * Creates the plugin's database tables, registers its capabilities, and
 * seeds default schema blocks, creature stacks, templates, and demo
 * character data. Runs once when the plugin is activated in wp-admin.
 */
class Activator {

	/**
	 * Runs the full activation sequence: creates database tables, registers
	 * capabilities, and seeds schema blocks, creature stacks, templates, and
	 * demo character data. Fires the be_after_upgrade action once seeding
	 * completes.
	 */
	public static function activate(): void {
		Schema::create_tables();
		Capabilities::register();
		Seeder::seed_schema_blocks();
		Seeder::seed_creature_stacks();

		// Reconciles stacks so no stack references an unseeded block.
		Seeder::reconcile_stack_blocks();

		Seeder::seed_default_templates();

		// Must run after seed_default_templates(), whose sheet_full layout it builds on.
		Seeder::seed_npc_templates();

		// Repairs any default template layouts that drifted from current definitions.
		Schema::repair_stale_default_layouts();

		// Seeds demo characters into the be-demo game.
		Seeder::seed_demo_characters();

		// Fires the post-upgrade action that provisions default pages.
		do_action( 'be_after_upgrade' );
	}
}
