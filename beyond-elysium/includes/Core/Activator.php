<?php

namespace BeyondElysium\Core;

use BeyondElysium\Database\Schema;
use BeyondElysium\Database\Seeder;
use BeyondElysium\Services\Catalog_Cutover;

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
		// Read before create_tables() records the schema version.
		$fresh_install = get_option( Schema::VERSION_OPTION ) === false;

		Schema::create_tables();
		Capabilities::register();

		// A new install starts on the per-creature catalog. Only one that predates 1.3.4 is ever on the
		// shared lists, and it stays there until `wp be cutover apply`. Declared before anything is
		// seeded, because the stacks, the default templates and the demo characters each read the
		// switch as they are built.
		if ( $fresh_install ) {
			Catalog_Cutover::declare_fresh_install();
		}

		Seeder::seed_schema_blocks();
		Seeder::seed_creature_stacks();

		// Reconciles stacks so no stack references an unseeded block.
		Seeder::reconcile_stack_blocks();

		Seeder::seed_default_templates();

		// Must run after seed_default_templates(), whose sheet_full layout it builds on.
		Seeder::seed_npc_templates();

		// Repairs any default template layouts that drifted from current definitions.
		Schema::repair_stale_default_layouts();

		// Completes every full sheet from its own stack, so a fresh install shows a block the
		// hand-written default layout never listed - Health Levels above all (1.2.11 D92).
		Schema::complete_full_sheet_templates();

		// Seeds demo characters into the be-demo game, on a fresh install only.
		Seeder::seed_demo_characters( $fresh_install );

		// Fires the post-upgrade action that provisions default pages.
		do_action( 'be_after_upgrade' );

		// Recorded last: an activation that fails partway leaves the version unrecorded, so the
		// next request's upgrade runs every step again (1.0.0-review F-064).
		update_option( Schema::VERSION_OPTION, Schema::DB_VERSION );
	}
}
