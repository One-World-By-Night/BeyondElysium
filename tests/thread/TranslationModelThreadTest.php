<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;
use WP_UnitTestCase;

/**
 * B2 (1.2.0 releases/1.2.0-design-workflow.md §4): Models\Translation's real CRUD and, most
 * load-bearing, upsert()'s fix for the gap TranslationTablesSchemaThreadTest (B1) pinned at the
 * raw-SQL layer - MySQL's UNIQUE key does not treat two NULLs as equal, so two default
 * (context-NULL) inserts for the same term+locale both succeed if nothing guards it.
 * upsert()'s own find_one() call is that guard; the tests below prove it holds, not just assert
 * that it should.
 *
 * @see BE_PROCESS/releases/1.2.0-design-workflow.md §4, §5.1
 */
class TranslationModelThreadTest extends WP_UnitTestCase {

	private function make_string( string $text = 'Translation Model Test Term' ): int {
		return (int) Translation_String::create( [ 'source_text' => $text ] );
	}

	public function test_create_defaults_context_null_and_status_draft(): void {
		$string_id = $this->make_string();
		$id = Translation::create( [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'translation' => 'X' ] );
		$row = Translation::find( (int) $id );
		$this->assertNull( $row->context );
		$this->assertSame( 'draft', $row->status );
	}

	public function test_create_rejects_an_unknown_status_by_falling_back_to_draft(): void {
		$string_id = $this->make_string();
		$id = Translation::create( [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'translation' => 'X', 'status' => 'bogus' ] );
		$row = Translation::find( (int) $id );
		$this->assertSame( 'draft', $row->status );
	}

	public function test_create_fails_without_a_string_id_or_locale(): void {
		$this->assertFalse( Translation::create( [ 'locale' => 'pt_BR', 'translation' => 'X' ] ) );
		$this->assertFalse( Translation::create( [ 'string_id' => $this->make_string(), 'translation' => 'X' ] ) );
	}

	public function test_find_one_null_context_and_explicit_context_are_genuinely_distinct(): void {
		$string_id = $this->make_string();
		$default_id = Translation::create( [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'translation' => 'Default' ] );
		$override_id = Translation::create( [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'context' => 'met-merits', 'translation' => 'Override' ] );

		$found_default = Translation::find_one( $string_id, 'pt_BR', null );
		$found_override = Translation::find_one( $string_id, 'pt_BR', 'met-merits' );

		$this->assertSame( (int) $default_id, (int) $found_default->id );
		$this->assertSame( (int) $override_id, (int) $found_override->id );
		$this->assertNotSame( $found_default->id, $found_override->id );
	}

	public function test_find_one_with_null_context_never_matches_a_row_that_has_a_context(): void {
		$string_id = $this->make_string();
		Translation::create( [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'context' => 'met-merits', 'translation' => 'Override Only' ] );
		$this->assertNull( Translation::find_one( $string_id, 'pt_BR', null ) );
	}

	/**
	 * The fix, proven directly: two upsert() calls with identical (string_id, locale,
	 * context=null) must produce exactly one row, carrying the second call's translation - not
	 * two rows, which is what the equivalent raw $wpdb->insert() calls produce (pinned in
	 * TranslationTablesSchemaThreadTest::test_two_null_context_rows_are_not_stopped_by_the_db_alone).
	 */
	public function test_upsert_twice_with_null_context_updates_not_duplicates(): void {
		$string_id = $this->make_string();

		$first_id = Translation::upsert( $string_id, 'pt_BR', 'First Draft' );
		$second_id = Translation::upsert( $string_id, 'pt_BR', 'Corrected Draft' );

		$this->assertSame( $first_id, $second_id, 'the second upsert must update the same row, not create a new one' );

		global $wpdb;
		$table = $wpdb->prefix . 'be_translations';
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE string_id = %d AND locale = %s AND context IS NULL",
			$string_id,
			'pt_BR'
		) );
		$this->assertSame( 1, $count, 'exactly one context-NULL row must exist for this term+locale' );

		$row = Translation::find( (int) $second_id );
		$this->assertSame( 'Corrected Draft', $row->translation, 'the surviving row must carry the latest text' );
	}

	/** Same guarantee, for a context-specific override rather than the default row. */
	public function test_upsert_twice_with_the_same_context_updates_not_duplicates(): void {
		$string_id = $this->make_string();

		$first_id = Translation::upsert( $string_id, 'pt_BR', 'First Override', 'met-merits' );
		$second_id = Translation::upsert( $string_id, 'pt_BR', 'Second Override', 'met-merits' );

		$this->assertSame( $first_id, $second_id );

		global $wpdb;
		$table = $wpdb->prefix . 'be_translations';
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE string_id = %d AND locale = %s AND context = %s",
			$string_id,
			'pt_BR',
			'met-merits'
		) );
		$this->assertSame( 1, $count );
	}

	/** The homograph case (§4): a default upsert and a context-specific upsert for the SAME term+locale must coexist. */
	public function test_upsert_default_and_context_specific_coexist_for_the_same_term(): void {
		$string_id = $this->make_string();

		Translation::upsert( $string_id, 'pt_BR', 'Default Meaning' );
		Translation::upsert( $string_id, 'pt_BR', 'Merits Meaning', 'met-merits' );

		$default = Translation::find_one( $string_id, 'pt_BR', null );
		$override = Translation::find_one( $string_id, 'pt_BR', 'met-merits' );

		$this->assertSame( 'Default Meaning', $default->translation );
		$this->assertSame( 'Merits Meaning', $override->translation );
		$this->assertNotSame( $default->id, $override->id );
	}

	public function test_upsert_preserves_the_locale_isolation(): void {
		$string_id = $this->make_string();
		Translation::upsert( $string_id, 'pt_BR', 'Portuguese' );
		Translation::upsert( $string_id, 'es_ES', 'Spanish' );

		$pt = Translation::find_one( $string_id, 'pt_BR', null );
		$es = Translation::find_one( $string_id, 'es_ES', null );
		$this->assertSame( 'Portuguese', $pt->translation );
		$this->assertSame( 'Spanish', $es->translation );
	}

	public function test_update_only_touches_the_fields_passed(): void {
		$string_id = $this->make_string();
		$id = Translation::create( [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'translation' => 'Original', 'status' => 'draft' ] );

		Translation::update( (int) $id, [ 'status' => 'approved' ] );
		$row = Translation::find( (int) $id );
		$this->assertSame( 'Original', $row->translation, 'translation must survive a status-only update' );
		$this->assertSame( 'approved', $row->status );
	}

	public function test_update_rejects_an_unknown_status_leaving_the_existing_one_untouched(): void {
		$string_id = $this->make_string();
		$id = Translation::create( [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'translation' => 'X', 'status' => 'draft' ] );
		Translation::update( (int) $id, [ 'status' => 'not-a-real-status' ] );
		$row = Translation::find( (int) $id );
		$this->assertSame( 'draft', $row->status );
	}

	public function test_delete_removes_the_row(): void {
		$string_id = $this->make_string();
		$id = Translation::create( [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'translation' => 'X' ] );
		$this->assertTrue( Translation::delete( (int) $id ) );
		$this->assertNull( Translation::find( (int) $id ) );
	}

	public function test_map_for_locale_keys_on_source_key_not_string_id(): void {
		$string_id = $this->make_string( 'Map For Locale Term' );
		Translation::upsert( $string_id, 'pt_BR', 'Termo Mapeado' );

		$map = Translation::map_for_locale( 'pt_BR' );
		$this->assertArrayHasKey( 'map for locale term', $map );
		$this->assertSame( 'Termo Mapeado', $map['map for locale term'] );
	}

	public function test_map_for_locale_excludes_an_empty_translation(): void {
		$string_id = $this->make_string( 'Map Empty Translation Term' );
		Translation::upsert( $string_id, 'pt_BR', '' );
		$map = Translation::map_for_locale( 'pt_BR' );
		$this->assertArrayNotHasKey( 'map empty translation term', $map );
	}

	public function test_map_for_locale_excludes_a_context_specific_override(): void {
		$string_id = $this->make_string( 'Map Context Term' );
		Translation::upsert( $string_id, 'pt_BR', 'Should Not Appear', 'met-merits' );
		$map = Translation::map_for_locale( 'pt_BR' );
		$this->assertArrayNotHasKey( 'map context term', $map, 'map() is the default lookup - a context override is resolved separately' );
	}

	public function test_map_for_locale_excludes_a_different_locale(): void {
		$string_id = $this->make_string( 'Map Locale Isolation Term' );
		Translation::upsert( $string_id, 'es_ES', 'Spanish Only' );
		$map = Translation::map_for_locale( 'pt_BR' );
		$this->assertArrayNotHasKey( 'map locale isolation term', $map );
	}

	/**
	 * §4's status-vocabulary rule, stated explicitly: "Any non-empty translation renders,
	 * whatever its status." A 'conflict' row is exactly the case this exists to protect - the
	 * migration's own first-wins-with-a-conflict-flag rows must still render, not vanish.
	 */
	public function test_map_for_locale_includes_a_conflict_status_row(): void {
		$string_id = $this->make_string( 'Map Conflict Status Term' );
		Translation::create( [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'translation' => 'Conflicted But Rendered', 'status' => 'conflict' ] );
		$map = Translation::map_for_locale( 'pt_BR' );
		$this->assertSame( 'Conflicted But Rendered', $map['map conflict status term'] ?? null );
	}
}
