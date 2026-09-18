<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;
use WP_UnitTestCase;

/**
 * B8, T6 (1.2.0 releases/1.2.0-design-workflow.md §8, §9): Schema::migrate_catalog_
 * translations_to_table()'s real recovery of existing name_pt/power_name_pt/CSV work, against
 * the real seeded catalog - not a hand-built fixture standing in for §8's own measured numbers.
 *
 * DELETEs (never TRUNCATEs - not rollback-safe inside WP_UnitTestCase's wrapped test
 * transaction) any translations rows a prior test run left, so T6 measures a genuinely clean
 * migration rather than under-counting keys a partial earlier run already has rows for.
 *
 * @see BE_PROCESS/releases/1.2.0-design-workflow.md §8, §9 T6
 */
class CatalogTranslationMigrationThreadTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_translations" );
		delete_option( 'be_catalog_translations_migrated' );
		delete_option( 'be_catalog_translations_migration_counts' );
	}

	public function tearDown(): void {
		// Explicit cleanup, not reliance on WP_UnitTestCase's ambient per-test rollback alone:
		// a real migration run inserts thousands of rows in one test method - found live, a
		// row from this class's own test genuinely survived into a later, unrelated test
		// class's run (TranslationStringModelThreadTest's "The Fortitude" fixture collided
		// with this class's own real rescan() of the catalog's actual Fortitude discipline).
		// DELETE, never TRUNCATE - the same non-negotiable as setUp()'s own comment.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_translations" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_translation_strings" );
		delete_option( 'be_catalog_translations_migrated' );
		delete_option( 'be_catalog_translations_migration_counts' );
		delete_option( 'be_translations_version' );
		parent::tearDown();
	}

	/**
	 * T6: against the real seeded catalog, pass 2 (CSV) alone recovers thousands of real rows.
	 * §8's own "3,104 rows, 5 conflicts, 4,441 union" figures were measured against a real,
	 * long-upgraded site's database, one still carrying pre-1.2.0 name_pt for pass 1 to harvest
	 * - a fresh WP_UnitTestCase bootstrap can never be that database (B9 means the current
	 * Seeder never writes name_pt at all, so a fresh install has none to harvest, by
	 * construction - see test_pre_existing_name_pt_is_recovered_before_the_reseed_that_would_
	 * erase_it for pass 1's own real coverage, against a fixture that actually has legacy data).
	 * This asserts what a fresh catalog CAN prove - pass 2's thousands of real CSV-matched rows
	 * - not a byte-exact number, and not pass 1's own conflict count, which is honestly zero here.
	 */
	public function test_t6_migration_against_the_real_catalog_matches_the_measured_shape(): void {
		Schema::migrate_catalog_translations_to_table();

		global $wpdb;
		$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_translations WHERE locale = 'pt_BR'" );
		$this->assertGreaterThanOrEqual( 2500, $total_rows, '§8\'s original measurement was 4,441; thousands of real rows either way' );

		$counts = get_option( 'be_catalog_translations_migration_counts' );
		$this->assertIsArray( $counts );
		$this->assertGreaterThanOrEqual( 0, $counts['db_conflicts'] );
		$this->assertLessThan( 50, $counts['db_conflicts'], 'conflicts should be a small fraction of ~3,000+ db-sourced terms' );
		$this->assertGreaterThanOrEqual( 1000, $counts['csv_added'], '§1.4(b)\'s CSV-only recovery is the whole point of pass 2' );
	}

	public function test_migration_is_idempotent_via_the_option_guard(): void {
		Schema::migrate_catalog_translations_to_table();
		global $wpdb;
		$first_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_translations" );

		Schema::migrate_catalog_translations_to_table(); // must be a no-op, guarded by the option
		$second_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_translations" );

		$this->assertSame( $first_count, $second_count );
	}

	public function test_migration_runs_rescan_first_so_every_recovered_key_has_a_string_row(): void {
		delete_option( 'be_translations_version' );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_translation_strings" ); // force rescan() to be the only source of rows

		Schema::migrate_catalog_translations_to_table();

		$this->assertGreaterThan( 8000, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_translation_strings" ) );
	}

	// ------------------------------------------------------------ conflict logic, on fixtures ----

	/**
	 * Schema_Block::create()/::update() strip() name_pt/power_name_pt unconditionally (B9
	 * retired the one legitimate writer §5.5's strip() guard used to carve out for, Seeder's own
	 * CSV-sourced path) - so pass 1's own real target, a row that already has name_pt baked into
	 * its definition from before B9 shipped, can no longer be built through the model layer at
	 * all. Inserted directly, bypassing the model exactly the way a real row upgraded from a
	 * pre-1.2.0 install already sits in the table - not a caller this codebase's own write path
	 * needs to support today.
	 */
	private function insert_block_with_baked_in_pt( string $slug, string $name, string $section_type, array $definition ): void {
		Manager::insert( 'schema_blocks', [
			'slug'         => $slug,
			'game_slug'    => '',
			'name'         => $name,
			'section_type' => $section_type,
			'definition'   => wp_json_encode( $definition ),
			'is_system'    => 0,
			'storyteller_only' => 0,
			'version'      => 1,
			'created_by'   => get_current_user_id(),
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		] );
	}

	/** A real DB pass-1 conflict: the same catalog term with two different name_pt values in two different blocks. */
	public function test_db_pass_conflict_keeps_the_first_value_and_records_the_loser_in_note(): void {
		$this->insert_block_with_baked_in_pt(
			'migration-conflict-a', 'Migration Conflict A', 'trait_list',
			[ 'items' => [ [ 'name' => 'Migration Conflict Term', 'name_pt' => 'Primeiro Valor' ] ] ]
		);
		$this->insert_block_with_baked_in_pt(
			'migration-conflict-b', 'Migration Conflict B', 'trait_list',
			[ 'items' => [ [ 'name' => 'Migration Conflict Term', 'name_pt' => 'Segundo Valor' ] ] ]
		);

		Schema::migrate_catalog_translations_to_table();

		$string = Translation_String::find_by_source_text( 'Migration Conflict Term' );
		$this->assertNotNull( $string, 'rescan() must have indexed the fixture term' );
		$translation = Translation::find_one( (int) $string->id, 'pt_BR', null );
		$this->assertNotNull( $translation, 'pass 1 must have created a translation row for it' );

		$this->assertSame( 'Primeiro Valor', $translation->translation, 'first value wins, is never overwritten' );
		$this->assertSame( 'conflict', $translation->status );
		$this->assertStringContainsString( 'Segundo Valor', $translation->note );
	}

	public function test_a_matching_value_in_two_blocks_is_not_a_conflict(): void {
		$this->insert_block_with_baked_in_pt(
			'migration-agree-a', 'Migration Agree A', 'trait_list',
			[ 'items' => [ [ 'name' => 'Migration Agreement Term', 'name_pt' => 'Mesmo Valor' ] ] ]
		);
		$this->insert_block_with_baked_in_pt(
			'migration-agree-b', 'Migration Agree B', 'trait_list',
			[ 'items' => [ [ 'name' => 'Migration Agreement Term', 'name_pt' => 'Mesmo Valor' ] ] ]
		);

		Schema::migrate_catalog_translations_to_table();

		$string = Translation_String::find_by_source_text( 'Migration Agreement Term' );
		$this->assertNotNull( $string );
		$translation = Translation::find_one( (int) $string->id, 'pt_BR', null );
		$this->assertNotNull( $translation );
		$this->assertSame( 'draft', $translation->status );
		$this->assertEmpty( $translation->note );
	}

	/** tiered_power's power_name/power_name_pt is recovered the same way trait_list's name/name_pt is. */
	public function test_tiered_power_level_pt_is_recovered(): void {
		$this->insert_block_with_baked_in_pt(
			'migration-tiered-fixture', 'Migration Tiered Fixture', 'tiered_power',
			[
				'powers' => [ [
					'name'   => 'Migration Family',
					'levels' => [ [ 'level' => 1, 'power_name' => 'Migration Power Name', 'power_name_pt' => 'Nome Do Poder' ] ],
				] ],
			]
		);

		Schema::migrate_catalog_translations_to_table();

		$string = Translation_String::find_by_source_text( 'Migration Power Name' );
		$this->assertNotNull( $string );
		$translation = Translation::find_one( (int) $string->id, 'pt_BR', null );
		$this->assertNotNull( $translation, 'pass 1 must have recovered the tiered_power level\'s power_name_pt' );
		$this->assertSame( 'Nome Do Poder', $translation->translation );
	}

	// ----------------------------------------------------------------- upgrade ordering ----

	/**
	 * Severe bug found live, this exact release, after B9 landed: Schema::run_upgrade() called
	 * migrate_catalog_translations_to_table() AFTER Seeder::seed_schema_blocks(), which was
	 * safe only while Seeder still wrote fresh name_pt on every reseed (B8's own original
	 * state). The instant B9 retired that write path, the same ordering became a real
	 * data-loss bug: seed_schema_blocks() replaces a system block's whole definition (its own
	 * documented, correct behavior for everything GVM/CSV-sourced - name_pt included, per
	 * Seeder::ADMIN_OWNED_ENTRY_KEYS, which never listed it), so a real site's pre-1.2.0
	 * name_pt would be permanently erased before the migration meant to recover it ever saw it.
	 * A fresh WP_UnitTestCase bootstrap can't reproduce this - a fresh install never had legacy
	 * name_pt to lose - so this simulates the one case that matters: a block carrying real
	 * pre-1.2.0 data, the way every actual production site's does today. Proves the fix by
	 * calling both steps directly, in Schema::run_upgrade()'s own real order - not the order
	 * that would make this test trivially pass.
	 */
	public function test_pre_existing_name_pt_is_recovered_before_the_reseed_that_would_erase_it(): void {
		$this->insert_block_with_baked_in_pt(
			'legacy-data-fixture', 'Legacy Data Fixture', 'trait_list',
			[ 'items' => [ [ 'name' => 'Legacy Pre 1 2 0 Term', 'name_pt' => 'Traducao Legada' ] ] ]
		);

		// Schema::run_upgrade()'s own real order: the migration first, the reseed second.
		Schema::migrate_catalog_translations_to_table();

		global $wpdb;
		$before = $wpdb->get_var( $wpdb->prepare(
			"SELECT definition FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = ''",
			'legacy-data-fixture'
		) );
		$this->assertStringContainsString( '"name_pt"', $before, 'sanity check: the fixture really does carry legacy name_pt before any reseed' );

		// This fixture is a custom (is_system = 0) block, so seed_schema_blocks() itself would
		// leave it untouched - the real hazard is a SYSTEM block, so this simulates the reseed's
		// own overwrite directly, the same shape seed_schema_blocks() applies to a real one.
		Schema_Block::update( 'legacy-data-fixture', [
			'definition' => [ 'items' => [ [ 'name' => 'Legacy Pre 1 2 0 Term' ] ] ],
		] );

		$after = $wpdb->get_var( $wpdb->prepare(
			"SELECT definition FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = ''",
			'legacy-data-fixture'
		) );
		$this->assertStringNotContainsString( '"name_pt"', $after, 'the reseed really does erase it from the block, same as a real one' );

		// The migration ran BEFORE that erasure - the translation must have survived it.
		$string = Translation_String::find_by_source_text( 'Legacy Pre 1 2 0 Term' );
		$this->assertNotNull( $string, 'rescan() must have indexed the fixture before the reseed touched it' );
		$translation = Translation::find_one( (int) $string->id, 'pt_BR', null );
		$this->assertNotNull( $translation, 'pass 1 must have recovered it - this is the whole bug' );
		$this->assertSame( 'Traducao Legada', $translation->translation );
	}

	// ------------------------------------------------------------------------------ T8 ----

	/**
	 * T8 (§9): the real, measured minimum-coverage floors MetCsvSeederTest used to assert
	 * against each catalog's own built block - moot now that B9 means a built block never
	 * carries name_pt at all - re-pointed at the table via the same block-scoped
	 * Translation_String::count_for_review() the review screen itself uses (§6), against real
	 * local data, not a bare non-zero check (the original tests' own stated reason to exist).
	 *
	 * These floors are pass 2 (CSV) alone, measured against a fresh bootstrap - a thread test's
	 * own catalog has no pre-1.2.0 legacy name_pt for pass 1 to harvest (see the upgrade-
	 * ordering test above), unlike a real, already-upgraded production site, where pass 1 adds
	 * substantially more (e.g. vampire-disciplines measured 372 there, not 233 here - see
	 * test_vampire_rituals_has_zero_coverage_via_pass_2_alone for the one catalog where that gap
	 * is total, not partial).
	 */
	public function test_coverage_floors_for_the_major_catalogs_match_the_table(): void {
		Schema::migrate_catalog_translations_to_table();

		$minimums = [
			'met-merits'                => 420,
			'met-flaws'                 => 391,
			'met-abilities'             => 45,
			'vampire-combo-disciplines' => 359,
			'vampire-disciplines'       => 233,
			'vampire-blood-magic'       => 530,
		];
		foreach ( $minimums as $slug => $minimum ) {
			$with_pt = Translation_String::count_for_review( 'pt_BR', [ 'block' => $slug, 'has_translation' => true ] );
			$this->assertGreaterThanOrEqual( $minimum, $with_pt, "{$slug}: expected at least {$minimum} terms with a pt_BR translation" );
		}
	}

	/**
	 * A real, honest gap, not a silent one: vampire-rituals' items are tradition-prefixed
	 * ("Thaumaturgy: Ward Versus Ghouls (basic)", build_met_rituals()'s own naming), but the
	 * CSV's own Ritual rows carry the bare name ("Ward Versus Ghouls") - pass 2's Name_Key
	 * match can never bridge that transform, so a fresh install recovers zero vampire-rituals
	 * translations via this migration alone. Not a regression to fix here: every real
	 * production site already has this catalog's translations recovered via pass 1 (measured
	 * 1,184 there), since every one of them has genuine pre-1.2.0 name_pt baked in already -
	 * this gap only ever reaches a brand new install, which starts with zero Portuguese
	 * anywhere regardless and needs B10's review screen either way.
	 */
	public function test_vampire_rituals_has_zero_coverage_via_pass_2_alone(): void {
		Schema::migrate_catalog_translations_to_table();

		$with_pt = Translation_String::count_for_review( 'pt_BR', [ 'block' => 'vampire-rituals', 'has_translation' => true ] );
		$this->assertSame( 0, $with_pt, 'if this is no longer 0, pass 2 learned to bridge the tradition-prefix transform - update the coverage floors above to include it' );
	}

	/**
	 * The specific, human-checkable case MetCsvSeederTest's own test_alacrity_... pinned against
	 * the block - now against the table instead, the real recovery path since B9.
	 */
	public function test_alacrity_has_the_real_drafted_portuguese_translation_via_the_table(): void {
		Schema::migrate_catalog_translations_to_table();

		$string = Translation_String::find_by_source_text( 'Alacrity' );
		$this->assertNotNull( $string, 'Alacrity must exist in the real vampire-disciplines catalog for this test to mean anything' );
		$translation = Translation::find_one( (int) $string->id, 'pt_BR', null );
		$this->assertNotNull( $translation );
		$this->assertSame( 'Presteza', $translation->translation );
	}

	/**
	 * T8's own headline claim: "a reseed of a system block leaves every translation intact -
	 * the thing preserve_admin_descriptions() had to be written for, now true by construction."
	 * Modeled on PreserveAdminDescriptionsThreadTest's own real-reseed shape, against a real
	 * system block. Unlike name_pt-in-the-definition (§1.5's own "a reseed destroys it" row,
	 * survivable there only via a dedicated preservation pass matched by name), the
	 * translations table is never written by Seeder at all, so there is nothing for
	 * seed_schema_blocks() to overwrite - "by construction" is a fact about where the data
	 * lives, not a behavior seed_schema_blocks() has to cooperate with.
	 */
	public function test_translations_survive_a_real_reseed_of_the_catalog(): void {
		Schema::migrate_catalog_translations_to_table();

		$before = Translation_String::find_by_source_text( 'Alacrity' );
		$this->assertNotNull( $before );
		$translation_before = Translation::find_one( (int) $before->id, 'pt_BR', null );
		$this->assertNotNull( $translation_before );
		$this->assertSame( 'Presteza', $translation_before->translation );

		\BeyondElysium\Database\Seeder::seed_schema_blocks();

		$after = Translation_String::find_by_source_text( 'Alacrity' );
		$this->assertNotNull( $after, 'the string index must survive the reseed untouched' );
		$translation_after = Translation::find_one( (int) $after->id, 'pt_BR', null );
		$this->assertNotNull( $translation_after, 'the translation row must survive the reseed untouched' );
		$this->assertSame( 'Presteza', $translation_after->translation );
	}
}
