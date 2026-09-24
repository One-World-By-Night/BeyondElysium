<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;
use WP_UnitTestCase;

/**
 * Schema::migrate_catalog_ translations_to_table()'s real recovery of existing name_pt/power_name_pt/CSV work,
 * against the real seeded catalog.
 */
class CatalogTranslationMigrationThreadTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS be_translations_thread_backup" );
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS be_translation_strings_thread_backup" );
		$wpdb->query( "CREATE TEMPORARY TABLE be_translations_thread_backup AS SELECT * FROM {$wpdb->prefix}be_translations" );
		$wpdb->query( "CREATE TEMPORARY TABLE be_translation_strings_thread_backup AS SELECT * FROM {$wpdb->prefix}be_translation_strings" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_translations" );
		delete_option( 'be_catalog_translations_migrated' );
		delete_option( 'be_catalog_translations_migration_counts' );
	}

	public function tearDown(): void {
		// DELETE, never TRUNCATE - the same non-negotiable as setUp()'s own comment.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_translations" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_translation_strings" );
		$wpdb->query( "INSERT INTO {$wpdb->prefix}be_translation_strings SELECT * FROM be_translation_strings_thread_backup" );
		$wpdb->query( "INSERT INTO {$wpdb->prefix}be_translations SELECT * FROM be_translations_thread_backup" );
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS be_translations_thread_backup" );
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS be_translation_strings_thread_backup" );
		delete_option( 'be_catalog_translations_migrated' );
		delete_option( 'be_catalog_translations_migration_counts' );
		delete_option( 'be_translations_version' );
		parent::tearDown();
	}

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

	/**
	 * A real DB pass-1 conflict: the same catalog term with two different name_pt values in two different blocks.
	 */
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

	/**
	 * tiered_power's power_name/power_name_pt is recovered the same way trait_list's name/name_pt is.
	 */
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

		// This fixture is a custom (is_system = 0) block.
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

	public function test_coverage_floors_for_the_major_catalogs_match_the_table(): void {
		Schema::migrate_catalog_translations_to_table();

		$minimums = [
			'vampire-merits'            => 418,
			'vampire-flaws'             => 387,
			'vampire-abilities'         => 44,
			'vampire-combo-disciplines' => 359,
			'vampire-disciplines'       => 146,
			// Same install-persistent-table contamination class as vampire-disciplines above.
			'vampire-blood-magic'       => 513,
		];
		foreach ( $minimums as $slug => $minimum ) {
			$with_pt = Translation_String::count_for_review( 'pt_BR', [ 'block' => $slug, 'has_translation' => true ] );
			$this->assertGreaterThanOrEqual( $minimum, $with_pt, "{$slug}: expected at least {$minimum} terms with a pt_BR translation" );
		}
	}

	/**
	 * A real, honest gap, not a silent one: vampire-rituals' items are tradition-prefixed ("Thaumaturgy: Ward Versus
	 * Ghouls (basic)", build_met_rituals()'s own naming).
	 */
	public function test_vampire_rituals_has_zero_coverage_via_pass_2_alone(): void {
		Schema::migrate_catalog_translations_to_table();

		$with_pt = Translation_String::count_for_review( 'pt_BR', [ 'block' => 'vampire-rituals', 'has_translation' => true ] );
		$this->assertSame( 0, $with_pt, 'if this is no longer 0, pass 2 learned to bridge the tradition-prefix transform - update the coverage floors above to include it' );
	}

	/**
	 * The specific, human-checkable case MetCsvSeederTest's own test_alacrity_... pinned against the block.
	 */
	public function test_alacrity_has_the_real_drafted_portuguese_translation_via_the_table(): void {
		Schema::migrate_catalog_translations_to_table();

		$string = Translation_String::find_by_source_text( 'Alacrity' );
		$this->assertNotNull( $string, 'Alacrity must exist in the real vampire-disciplines catalog for this test to mean anything' );
		$translation = Translation::find_one( (int) $string->id, 'pt_BR', null );
		$this->assertNotNull( $translation );
		$this->assertSame( 'Presteza', $translation->translation );
	}

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
