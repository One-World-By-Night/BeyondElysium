<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Translation_String;
use BeyondElysium\Services\Catalog_Translator;
use WP_UnitTestCase;

/**
 * `Catalog_Translator::rescan()` runs after ingestion, wired into `Schema::run_upgrade()`.
 */
class CatalogRescanAfterIngestionThreadTest extends WP_UnitTestCase {

	/**
	 * `darkages-vampire_disciplines.json` names its family `Animalism (Dark Ages)` with `aliases: ["Animalism"]` and
	 * `split_from: "Animalism"`.
	 */
	public function test_a_renamed_declared_family_is_indexed_under_its_current_name(): void {
		Seeder::seed_schema_blocks();
		Catalog_Translator::rescan();

		$current = Translation_String::find_by_source_text( 'Animalism (Dark Ages)' );
		$this->assertNotNull( $current, 'the current declared name must be indexed after rescan' );
	}

	/**
	 * The same wiring, exercised the way `Schema::run_upgrade()` actually calls it.
	 */
	public function test_rescan_runs_without_error_against_the_full_declared_catalog(): void {
		Seeder::seed_schema_blocks();

		$result = Catalog_Translator::rescan();

		$this->assertIsArray( $result );
		$this->assertNotNull( Translation_String::find_by_source_text( 'Celerity' ), 'an ordinary, unrenamed term is still indexed' );
	}
}
