<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Translation_String;
use BeyondElysium\Services\Catalog_Translator;
use WP_UnitTestCase;

/**
 * 1.3.2 item 7: `Catalog_Translator::rescan()` runs after ingestion, wired into
 * `Schema::run_upgrade()`. `reference/CATALOG-JSON-FORMAT.md` §4a's own risk statement:
 * translation keys are derived from a term's *current* canonical name
 * (`Services\Name_Key::for()`), so a declared file renaming or aliasing a term - the edition
 * variants' `<Family> (Dark Ages)` names, 43 rung aliases, 4 family aliases, 14 moved
 * families per the 1.3.1 doc - needs the index rebuilt against the name that is live now, or
 * that term's translation coverage looks like it silently dropped.
 *
 * This is a translation-*key* problem only, proven here: rescan() correctly indexes the
 * current declared name. Whether an existing translation of the *old* name should carry
 * forward automatically via the recorded alias is a separate, larger question - not built,
 * see the 1.3.2 release doc's owner questions.
 *
 * `be_translations`/`be_translation_strings` are install-persistent, like every other catalog
 * table (PLATFORM.md) - not wrapped by the per-test transaction. Both assertions below only
 * check that a specific term is indexed *after* a rescan, which holds regardless of whatever
 * else the table already carries, so this class does not touch either table's existing rows -
 * an earlier `setUp()`/`tearDown()` pair here unconditionally deleted from both, which
 * permanently emptied them for every later test in the same process (found live: it silently
 * broke `CatalogTranslatorThreadTest`'s "38 retired terms" measurement whenever this file ran
 * first, since there was nothing left to measure as retired).
 */
class CatalogRescanAfterIngestionThreadTest extends WP_UnitTestCase {

	/**
	 * `darkages-vampire_disciplines.json` names its family `Animalism (Dark Ages)` with
	 * `aliases: ["Animalism"]` and `split_from: "Animalism"` - a real declared rename/alias,
	 * not a synthetic one. Once seeded and rescanned, the *current* name must be indexed;
	 * before this release's wiring, `rescan()` only ever ran once at the 1.2.0 migration and
	 * never again on an ordinary upgrade, so a name introduced by a later reseed would never
	 * be indexed until someone happened to trigger the admin's on-demand rescan.
	 */
	public function test_a_renamed_declared_family_is_indexed_under_its_current_name(): void {
		Seeder::seed_schema_blocks();
		Catalog_Translator::rescan();

		$current = Translation_String::find_by_source_text( 'Animalism (Dark Ages)' );
		$this->assertNotNull( $current, 'the current declared name must be indexed after rescan' );
	}

	/** The same wiring, exercised the way `Schema::run_upgrade()` actually calls it. */
	public function test_rescan_runs_without_error_against_the_full_declared_catalog(): void {
		Seeder::seed_schema_blocks();

		$result = Catalog_Translator::rescan();

		$this->assertIsArray( $result );
		$this->assertNotNull( Translation_String::find_by_source_text( 'Celerity' ), 'an ordinary, unrenamed term is still indexed' );
	}
}
