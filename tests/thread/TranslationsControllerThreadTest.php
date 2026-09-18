<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * B7 (1.2.0 releases/1.2.0-design-workflow.md §6): Translations_Controller's ten routes,
 * dispatched through the real REST server - not called as plain PHP methods, since a
 * permission_callback wiring bug or a route-registration typo is invisible to a direct method
 * call. T10 and T12 are named exactly as §9 states them; every other route gets its own
 * coverage since none of them existed before this box.
 *
 * @see BE_PROCESS/releases/1.2.0-design-workflow.md §6, §9
 */
class TranslationsControllerThreadTest extends WP_UnitTestCase {

	private const LOCALE = 'pt_BR';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
	}

	public function tearDown(): void {
		delete_option( 'be_translations_version' );
		parent::tearDown();
	}

	private function make_admin(): int {
		$id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * A translation id guaranteed not to exist - MySQL's AUTO_INCREMENT is not transactional
	 * (it survives a rolled-back INSERT), so a hardcoded "surely too large" sentinel eventually
	 * stops being one: found live, this exact test, after enough of this release's own real
	 * migration tests (each inserting thousands of rows per test method) pushed the real test
	 * database's counter past a hardcoded 999999. Create then delete a real row instead, the
	 * same technique test_delete_removes_the_row() already uses to prove a row is gone.
	 */
	private function missing_translation_id(): int {
		$string_id = (int) Translation_String::create( [ 'source_text' => 'Missing Id Fixture Term' ] );
		$id        = (int) Translation::create( [ 'string_id' => $string_id, 'locale' => self::LOCALE, 'translation' => 'X' ] );
		Translation::delete( $id );
		return $id;
	}

	private function dispatch( string $method, string $route, array $params = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) {
			foreach ( $params as $k => $v ) {
				$request->set_param( $k, $v );
			}
		} else {
			$request->set_body_params( $params );
		}
		return rest_get_server()->dispatch( $request );
	}

	// -------------------------------------------------------------------------- permission ----

	public function test_every_route_denies_a_user_without_the_capability(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $player );

		$response = $this->dispatch( 'GET', '/be/v1/translations', [ 'locale' => self::LOCALE ] );
		$this->assertSame( 403, $response->get_status() );
	}

	/** be_manage_schemas alone is not enough (§5.7's whole point - see Capabilities.php). */
	public function test_be_manage_schemas_alone_does_not_grant_access(): void {
		// editor holds both by default; simulate "schemas only" by checking the denial path
		// directly rather than trying to strip an inherited role capability (see
		// TranslationsCapabilityThreadTest's own docblock for why remove_cap() cannot do this).
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $player );
		$this->assertFalse( user_can( $player, 'be_manage_translations' ) );

		$response = $this->dispatch( 'GET', '/be/v1/translations', [ 'locale' => self::LOCALE ] );
		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------------- list ----

	public function test_list_requires_locale(): void {
		$this->make_admin();
		$response = $this->dispatch( 'GET', '/be/v1/translations' );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * T10 exactly: per_page=100 returns 100 rows and a correct total, not the shared
	 * get_pagination() default of 20 - the whole reason B7's own MAX_PER_PAGE exists.
	 */
	public function test_t10_per_page_100_returns_100_rows_and_a_correct_total(): void {
		$this->make_admin();
		for ( $i = 0; $i < 130; $i++ ) {
			Translation_String::create( [ 'source_text' => "T10 Fixture Term {$i}" ] );
		}

		$response = $this->dispatch( 'GET', '/be/v1/translations', [
			'locale' => self::LOCALE, 'search' => 'T10 Fixture Term', 'per_page' => 100,
		] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 100, $response->get_data() );
		$this->assertSame( '130', $response->get_headers()['X-WP-Total'] );
	}

	public function test_list_omitting_per_page_still_uses_the_shared_default_of_20(): void {
		$this->make_admin();
		for ( $i = 0; $i < 25; $i++ ) {
			Translation_String::create( [ 'source_text' => "Default Page Size Term {$i}" ] );
		}
		$response = $this->dispatch( 'GET', '/be/v1/translations', [ 'locale' => self::LOCALE, 'search' => 'Default Page Size Term' ] );
		$this->assertCount( 20, $response->get_data(), 'the 500 cap must not become the new default' );
	}

	public function test_list_status_filter_through_the_real_route(): void {
		$this->make_admin();
		$id = (int) Translation_String::create( [ 'source_text' => 'Route Status Filter Term' ] );
		Translation::create( [ 'string_id' => $id, 'locale' => self::LOCALE, 'translation' => 'X', 'status' => 'approved' ] );

		$response = $this->dispatch( 'GET', '/be/v1/translations', [
			'locale' => self::LOCALE, 'status' => 'approved', 'search' => 'Route Status Filter Term',
		] );
		$this->assertCount( 1, $response->get_data() );
	}

	// ------------------------------------------------------------------------------- stats ----

	public function test_stats_requires_locale(): void {
		$this->make_admin();
		$response = $this->dispatch( 'GET', '/be/v1/translations/progress' );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_stats_reports_totals_and_status_breakdown(): void {
		$this->make_admin();
		$id1 = (int) Translation_String::create( [ 'source_text' => 'Stats Fixture Draft' ] );
		Translation::create( [ 'string_id' => $id1, 'locale' => self::LOCALE, 'translation' => 'X', 'status' => 'draft' ] );
		$id2 = (int) Translation_String::create( [ 'source_text' => 'Stats Fixture Approved' ] );
		Translation::create( [ 'string_id' => $id2, 'locale' => self::LOCALE, 'translation' => 'Y', 'status' => 'approved' ] );

		$response = $this->dispatch( 'GET', '/be/v1/translations/progress', [ 'locale' => self::LOCALE ] );
		$data = $response->get_data();

		$this->assertGreaterThanOrEqual( 2, $data['total'] );
		$this->assertGreaterThanOrEqual( 1, $data['by_status']['draft'] );
		$this->assertGreaterThanOrEqual( 1, $data['by_status']['approved'] );
	}

	public function test_stats_by_block_counts_a_term_once_per_block_not_once_per_occurrence(): void {
		$this->make_admin();
		Translation_String::create( [
			'source_text' => 'Stats By Block Fixture',
			'used_in'     => [
				[ 'block' => 'stats-fixture-block', 'section_type' => 'trait_list', 'role' => 'item' ],
				[ 'block' => 'stats-fixture-block', 'section_type' => 'trait_list', 'role' => 'item' ], // duplicate on purpose
			],
		] );
		$response = $this->dispatch( 'GET', '/be/v1/translations/progress', [ 'locale' => self::LOCALE ] );
		$this->assertSame( 1, $response->get_data()['by_block']['stats-fixture-block']['total'] );
	}

	// ----------------------------------------------------------------------------- locales ----

	public function test_locales_lists_locales_with_real_rows(): void {
		$this->make_admin();
		$id = (int) Translation_String::create( [ 'source_text' => 'Locales Fixture Term' ] );
		Translation::create( [ 'string_id' => $id, 'locale' => 'es_ES', 'translation' => 'X' ] );

		$response = $this->dispatch( 'GET', '/be/v1/translations/locales' );
		$this->assertContains( 'es_ES', $response->get_data()['with_rows'] );
	}

	// ------------------------------------------------------------------------------ create ----

	public function test_create_via_source_text_makes_a_new_string_row_if_needed(): void {
		$this->make_admin();
		$response = $this->dispatch( 'POST', '/be/v1/translations', [
			'locale' => self::LOCALE, 'source_text' => 'Create Via Source Text Term', 'translation' => 'Criado',
		] );
		$this->assertSame( 201, $response->get_status() );
		$this->assertNotNull( Translation_String::find_by_source_text( 'Create Via Source Text Term' ) );
	}

	public function test_create_via_string_id(): void {
		$this->make_admin();
		$id = (int) Translation_String::create( [ 'source_text' => 'Create Via String Id Term' ] );
		$response = $this->dispatch( 'POST', '/be/v1/translations', [
			'locale' => self::LOCALE, 'string_id' => $id, 'translation' => 'Criado',
		] );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_create_requires_locale_and_translation(): void {
		$this->make_admin();
		$response = $this->dispatch( 'POST', '/be/v1/translations', [ 'source_text' => 'Missing Params Term' ] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_create_requires_either_string_id_or_source_text(): void {
		$this->make_admin();
		$response = $this->dispatch( 'POST', '/be/v1/translations', [ 'locale' => self::LOCALE, 'translation' => 'X' ] );
		$this->assertSame( 400, $response->get_status() );
	}

	/** create_item() is really upsert() underneath - a second POST for the same term updates, not duplicates. */
	public function test_create_twice_for_the_same_term_updates_not_duplicates(): void {
		$this->make_admin();
		$this->dispatch( 'POST', '/be/v1/translations', [ 'locale' => self::LOCALE, 'source_text' => 'Create Upsert Term', 'translation' => 'Primeiro' ] );
		$this->dispatch( 'POST', '/be/v1/translations', [ 'locale' => self::LOCALE, 'source_text' => 'Create Upsert Term', 'translation' => 'Segundo' ] );

		$row = Translation_String::find_by_source_text( 'Create Upsert Term' );
		$translation = Translation::find_one( (int) $row->id, self::LOCALE, null );
		$this->assertSame( 'Segundo', $translation->translation );
	}

	// ------------------------------------------------------------------------------ update ----

	public function test_update_edits_translation_and_status(): void {
		$this->make_admin();
		$string_id = (int) Translation_String::create( [ 'source_text' => 'Update Route Term' ] );
		$id = (int) Translation::create( [ 'string_id' => $string_id, 'locale' => self::LOCALE, 'translation' => 'Original' ] );

		$response = $this->dispatch( 'PATCH', "/be/v1/translations/{$id}", [ 'translation' => 'Corrigido', 'status' => 'approved' ] );
		$this->assertSame( 200, $response->get_status() );
		$row = Translation::find( $id );
		$this->assertSame( 'Corrigido', $row->translation );
		$this->assertSame( 'approved', $row->status );
	}

	public function test_update_a_missing_id_returns_404(): void {
		$this->make_admin();
		$id       = $this->missing_translation_id();
		$response = $this->dispatch( 'PATCH', "/be/v1/translations/{$id}", [ 'translation' => 'X' ] );
		$this->assertSame( 404, $response->get_status() );
	}

	// ------------------------------------------------------------------------------ delete ----

	public function test_delete_removes_the_row(): void {
		$this->make_admin();
		$string_id = (int) Translation_String::create( [ 'source_text' => 'Delete Route Term' ] );
		$id = (int) Translation::create( [ 'string_id' => $string_id, 'locale' => self::LOCALE, 'translation' => 'X' ] );

		$response = $this->dispatch( 'DELETE', "/be/v1/translations/{$id}" );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( Translation::find( $id ) );
	}

	public function test_delete_a_missing_id_returns_404(): void {
		$this->make_admin();
		$id       = $this->missing_translation_id();
		$response = $this->dispatch( 'DELETE', "/be/v1/translations/{$id}" );
		$this->assertSame( 404, $response->get_status() );
	}

	// -------------------------------------------------------------------------------- bulk ----

	public function test_bulk_applies_every_valid_row_and_skips_the_rest(): void {
		$this->make_admin();
		Translation_String::create( [ 'source_text' => 'Bulk Route Term One' ] );
		Translation_String::create( [ 'source_text' => 'Bulk Route Term Two' ] );

		$response = $this->dispatch( 'POST', '/be/v1/translations/bulk', [
			'locale' => self::LOCALE,
			'rows'   => [
				[ 'source_text' => 'Bulk Route Term One', 'translation' => 'Um' ],
				[ 'source_text' => 'Bulk Route Term Two', 'translation' => 'Dois' ],
				[ 'source_text' => '', 'translation' => 'Invalid Empty Source' ],
			],
		] );

		$data = $response->get_data();
		$this->assertSame( 2, $data['updated'] );
		$this->assertSame( 1, $data['skipped'] );
	}

	// ------------------------------------------------------------------------------ export ----

	public function test_export_returns_csv_bytes_and_a_filename(): void {
		$this->make_admin();
		$id = (int) Translation_String::create( [ 'source_text' => 'Export Route Term' ] );
		Translation::create( [ 'string_id' => $id, 'locale' => self::LOCALE, 'translation' => 'Exportado' ] );

		$response = $this->dispatch( 'GET', '/be/v1/translations/export', [ 'locale' => self::LOCALE, 'search' => 'Export Route Term' ] );
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'translations-pt_BR.csv', $data['filename'] );
		$this->assertStringContainsString( 'Export Route Term', $data['bytes'] );
		$this->assertStringContainsString( 'Exportado', $data['bytes'] );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $data['bytes'], 'UTF-8 BOM must lead the file' );
	}

	// ------------------------------------------------------------------------------ import ----

	/**
	 * T12 exactly: a dry-run reports counts and writes nothing; the same import without
	 * dry_run writes exactly those counts.
	 */
	public function test_t12_dry_run_reports_counts_and_writes_nothing_then_the_real_run_matches(): void {
		$this->make_admin();
		Translation_String::create( [ 'source_text' => 'Import Fixture Added' ] );
		$existing_id = (int) Translation_String::create( [ 'source_text' => 'Import Fixture Updated' ] );
		Translation::create( [ 'string_id' => $existing_id, 'locale' => self::LOCALE, 'translation' => 'Velho' ] );

		$csv  = "source_text,translation,status\n";
		$csv .= "Import Fixture Added,Adicionado,draft\n";
		$csv .= "Import Fixture Updated,Novo,draft\n";
		$csv .= "Import Fixture Nowhere,Sem Correspondencia,draft\n";

		$tmp = tempnam( sys_get_temp_dir(), 'be-import-test' );
		file_put_contents( $tmp, $csv );

		$request = new WP_REST_Request( 'POST', '/be/v1/translations/import-csv' );
		$request->set_param( 'locale', self::LOCALE );
		$request->set_param( 'dry_run', '1' );
		$request->set_file_params( [ 'file' => [ 'tmp_name' => $tmp, 'error' => 0, 'size' => strlen( $csv ) ] ] );
		$dry = rest_get_server()->dispatch( $request );
		$dry_data = $dry->get_data();

		$this->assertSame( 1, $dry_data['added'] );
		$this->assertSame( 1, $dry_data['updated'] );
		$this->assertSame( 1, $dry_data['unmatched'] );
		$this->assertTrue( $dry_data['dry_run'] );

		// Nothing written: the "added" term still has no translation row at all.
		$added_row = Translation_String::find_by_source_text( 'Import Fixture Added' );
		$this->assertNull( Translation::find_one( (int) $added_row->id, self::LOCALE, null ), 'dry_run must not write' );
		$this->assertSame( 'Velho', Translation::find_one( $existing_id, self::LOCALE, null )->translation, 'dry_run must not overwrite the existing translation either' );

		// The real run.
		$request2 = new WP_REST_Request( 'POST', '/be/v1/translations/import-csv' );
		$request2->set_param( 'locale', self::LOCALE );
		$request2->set_file_params( [ 'file' => [ 'tmp_name' => $tmp, 'error' => 0, 'size' => strlen( $csv ) ] ] );
		$real = rest_get_server()->dispatch( $request2 );
		$real_data = $real->get_data();

		$this->assertSame( $dry_data['added'], $real_data['added'], 'the real run must write exactly what the dry run predicted' );
		$this->assertSame( $dry_data['updated'], $real_data['updated'] );
		$this->assertFalse( $real_data['dry_run'] );

		$this->assertSame( 'Adicionado', Translation::find_one( (int) $added_row->id, self::LOCALE, null )->translation );
		$this->assertSame( 'Novo', Translation::find_one( $existing_id, self::LOCALE, null )->translation );

		unlink( $tmp );
	}

	public function test_import_requires_a_file(): void {
		$this->make_admin();
		$response = $this->dispatch( 'POST', '/be/v1/translations/import-csv', [ 'locale' => self::LOCALE ] );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * The real bug T12 caught before this pinned it: a CSV row disagreeing with the DATABASE'S
	 * current value is an ordinary update, not a conflict - importing corrections is the whole
	 * point. A conflict is the FILE disagreeing with ITSELF, first value wins, matching §8's
	 * migration use of the word for an internally-inconsistent source.
	 */
	public function test_import_conflict_is_the_file_disagreeing_with_itself_not_with_the_database(): void {
		$this->make_admin();

		$existing_id = (int) Translation_String::create( [ 'source_text' => 'Correction Fixture Term' ] );
		Translation::create( [ 'string_id' => $existing_id, 'locale' => self::LOCALE, 'translation' => 'Valor Antigo' ] );

		$csv  = "source_text,translation,status\n";
		$csv .= "Correction Fixture Term,Valor Corrigido,draft\n"; // disagrees with the DB - an update.
		$csv .= "Intra File Fixture Term,Primeiro,draft\n";
		$csv .= "Intra File Fixture Term,Segundo,draft\n"; // disagrees with the ROW ABOVE - a real conflict.

		$tmp = tempnam( sys_get_temp_dir(), 'be-import-conflict-test' );
		file_put_contents( $tmp, $csv );

		$request = new WP_REST_Request( 'POST', '/be/v1/translations/import-csv' );
		$request->set_param( 'locale', self::LOCALE );
		$request->set_param( 'dry_run', '1' );
		$request->set_file_params( [ 'file' => [ 'tmp_name' => $tmp, 'error' => 0, 'size' => strlen( $csv ) ] ] );
		$response = rest_get_server()->dispatch( $request );
		$data = $response->get_data();

		$this->assertSame( 1, $data['updated'], 'the DB-disagreement row must count as updated' );
		$this->assertSame( 1, $data['conflicts'], 'only the intra-file disagreement counts as a conflict' );

		unlink( $tmp );
	}

	// ------------------------------------------------------------------------------ rescan ----

	public function test_rescan_through_the_real_route_actually_runs(): void {
		$this->make_admin();
		$response = $this->dispatch( 'POST', '/be/v1/translations/rescan' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'added', $data );
		$this->assertArrayHasKey( 'updated', $data );
		$this->assertArrayHasKey( 'orphaned', $data );
	}
}
