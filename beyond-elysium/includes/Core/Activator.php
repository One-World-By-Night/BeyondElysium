<?php

namespace BeyondElysium\Core;

use BeyondElysium\Database\Schema;
use BeyondElysium\Database\Seeder;
use BeyondElysium\Services\Catalog_Cutover;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin activation entry point.
 */
class Activator {

	/**
	 * Runs the full activation sequence: creates database tables, registers capabilities, and seeds schema blocks,
	 * creature stacks, templates, and demo character data.
	 */
	public static function activate(): void {
		$fresh_install = get_option( Schema::VERSION_OPTION ) === false;

		Schema::create_tables();
		Capabilities::register();

		Seeder::seed_schema_blocks();

		// Moves an install still on the shared lists onto the per-creature catalog.
		$move = Catalog_Cutover::ensure_declared();
		if ( ! in_array( $move['status'], [ 'already_declared', 'marked', 'applied' ], true ) ) {
			error_log( 'Beyond Elysium: ' . Catalog_Cutover::refusal_message( $move ) );
			return;
		}

		Seeder::seed_creature_stacks();

		// Reconciles stacks so no stack references an unseeded block.
		Seeder::reconcile_stack_blocks();

		Seeder::seed_default_templates();

		Seeder::seed_npc_templates();

		// Repairs any default template layouts that drifted from current definitions.
		Schema::repair_stale_default_layouts();

		// Completes every full sheet from its own stack.
		Schema::complete_full_sheet_templates();

		// Seeds demo characters into the be-demo game, on a fresh install only.
		Seeder::seed_demo_characters( $fresh_install );

		// Fires the post-upgrade action that provisions default pages.
		do_action( 'be_after_upgrade' );

		// Records the installed schema version.
		update_option( Schema::VERSION_OPTION, Schema::DB_VERSION );
	}
}
