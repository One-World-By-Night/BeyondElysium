<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;
use WP_UnitTestCase;

/**
 * Models\Translation_String's real CRUD, its Name_Key-keyed lookups, upsert_from_scan()'s first_seen/last_seen
 * discipline, and list_for_review()/count_for_review()'s full filter vocabulary against real rows.
 */
class TranslationStringModelThreadTest extends WP_UnitTestCase {

	public function test_create_derives_source_key_from_source_text(): void {
		// A distinctive fixture name.
		$id  = Translation_String::create( [ 'source_text' => 'The Derived Key Fixture' ] );
		$row = Translation_String::find( (int) $id );
		$this->assertSame( 'derived key fixture', $row->source_key );
	}

	public function test_create_accepts_an_explicit_source_key(): void {
		$id  = Translation_String::create( [ 'source_text' => 'Custom Term', 'source_key' => 'custom-override' ] );
		$row = Translation_String::find( (int) $id );
		$this->assertSame( 'custom-override', $row->source_key );
	}

	public function test_find_decodes_used_in_to_an_array(): void {
		$id  = Translation_String::create( [
			'source_text' => 'Decode Test',
			'used_in'     => [ [ 'block' => 'met-merits', 'section_type' => 'trait_list', 'role' => 'item' ] ],
		] );
		$row = Translation_String::find( (int) $id );
		$this->assertIsArray( $row->used_in );
		$this->assertSame( 'met-merits', $row->used_in[0]->block );
	}

	public function test_find_by_source_key_and_find_by_source_text_agree(): void {
		Translation_String::create( [ 'source_text' => 'Agreement Test' ] );
		$by_key  = Translation_String::find_by_source_key( 'agreement test' );
		$by_text = Translation_String::find_by_source_text( 'The Agreement Test' );
		$this->assertNotNull( $by_key );
		$this->assertSame( $by_key->id, $by_text->id );
	}

	public function test_find_by_source_text_returns_null_when_nothing_matches(): void {
		$this->assertNull( Translation_String::find_by_source_text( 'Nothing Matches This' ) );
	}

	public function test_update_only_touches_the_fields_passed(): void {
		$id = Translation_String::create( [ 'source_text' => 'Partial Update', 'used_in' => [ 'a' ] ] );
		Translation_String::update( (int) $id, [ 'source_text' => 'Partial Update Renamed' ] );
		$row = Translation_String::find( (int) $id );
		$this->assertSame( 'Partial Update Renamed', $row->source_text );
		$this->assertNotEmpty( $row->used_in ); // untouched, not wiped to [] by the update call.
	}

	public function test_delete_removes_the_row(): void {
		$id = Translation_String::create( [ 'source_text' => 'Delete Me' ] );
		$this->assertTrue( Translation_String::delete( (int) $id ) );
		$this->assertNull( Translation_String::find( (int) $id ) );
	}

	public function test_upsert_from_scan_creates_on_first_call(): void {
		$id  = Translation_String::upsert_from_scan( 'Scan Created', [ 'x' ] );
		$row = Translation_String::find( (int) $id );
		$this->assertSame( 'Scan Created', $row->source_text );
		$this->assertNotEmpty( $row->used_in );
	}

	/**
	 * The real point of upsert_from_scan(): a second scan of an already-known term refreshes used_in/last_seen but never
	 * disturbs first_seen.
	 */
	public function test_upsert_from_scan_refreshes_an_existing_row_without_duplicating_it(): void {
		$first_id = Translation_String::upsert_from_scan( 'Rescan Me', [ 'block-a' ] );
		$before   = Translation_String::find( (int) $first_id );

		$second_id = Translation_String::upsert_from_scan( 'Rescan Me', [ 'block-a', 'block-b' ] );
		$after     = Translation_String::find( (int) $second_id );

		$this->assertSame( $first_id, $second_id, 'a rescan of an existing term must update, not duplicate' );
		$this->assertSame( $before->first_seen, $after->first_seen, 'first_seen must never move once set' );
		$this->assertCount( 2, $after->used_in, 'used_in must reflect the newest scan' );
	}

	public function test_upsert_from_scan_is_name_key_aware_not_literal(): void {
		Translation_String::upsert_from_scan( 'The Alias Case', [] );
		$second = Translation_String::upsert_from_scan( 'alias case', [ 'seen-again' ] );
		global $wpdb;
		$table = $wpdb->prefix . 'be_translation_strings';
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_key = %s", 'alias case' ) );
		$this->assertSame( 1, $count, 'Name_Key-equivalent spellings must resolve to the same row' );
	}

	public function test_list_for_review_left_joins_an_untranslated_term_with_nulls(): void {
		$id = Translation_String::create( [ 'source_text' => 'Untranslated Review Term' ] );
		$rows = Translation_String::list_for_review( 'pt_BR', [ 'search' => 'Untranslated Review Term' ] );
		$this->assertCount( 1, $rows );
		$this->assertSame( (int) $id, (int) $rows[0]->id );
		$this->assertNull( $rows[0]->translation );
		$this->assertNull( $rows[0]->status );
	}

	public function test_list_for_review_attaches_the_matching_locales_translation(): void {
		$id = Translation_String::create( [ 'source_text' => 'Translated Review Term' ] );
		Translation::create( [ 'string_id' => $id, 'locale' => 'pt_BR', 'translation' => 'Termo Traduzido', 'status' => 'approved' ] );

		$rows = Translation_String::list_for_review( 'pt_BR', [ 'search' => 'Translated Review Term' ] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Termo Traduzido', $rows[0]->translation );
		$this->assertSame( 'approved', $rows[0]->status );
	}

	public function test_list_for_review_does_not_attach_a_different_locales_translation(): void {
		$id = Translation_String::create( [ 'source_text' => 'Locale Isolation Term' ] );
		Translation::create( [ 'string_id' => $id, 'locale' => 'es_ES', 'translation' => 'Termino Traducido' ] );

		$rows = Translation_String::list_for_review( 'pt_BR', [ 'search' => 'Locale Isolation Term' ] );
		$this->assertCount( 1, $rows );
		$this->assertNull( $rows[0]->translation, 'a pt_BR review must never surface an es_ES row' );
	}

	public function test_list_for_review_does_not_attach_a_context_specific_override(): void {
		$id = Translation_String::create( [ 'source_text' => 'Context Isolation Term' ] );
		Translation::create( [ 'string_id' => $id, 'locale' => 'pt_BR', 'context' => 'met-merits', 'translation' => 'Overridden' ] );

		$rows = Translation_String::list_for_review( 'pt_BR', [ 'search' => 'Context Isolation Term' ] );
		$this->assertCount( 1, $rows );
		$this->assertNull( $rows[0]->translation, 'the review list is the default (context-NULL) view, not a context override' );
	}

	public function test_status_filter_untranslated_finds_a_string_with_no_row(): void {
		Translation_String::create( [ 'source_text' => 'Filter Untranslated Term' ] );
		$rows = Translation_String::list_for_review( 'pt_BR', [ 'status' => 'untranslated', 'search' => 'Filter Untranslated Term' ] );
		$this->assertCount( 1, $rows );
	}

	public function test_status_filter_untranslated_excludes_a_translated_string(): void {
		$id = Translation_String::create( [ 'source_text' => 'Filter Translated Term' ] );
		Translation::create( [ 'string_id' => $id, 'locale' => 'pt_BR', 'translation' => 'X' ] );
		$rows = Translation_String::list_for_review( 'pt_BR', [ 'status' => 'untranslated', 'search' => 'Filter Translated Term' ] );
		$this->assertCount( 0, $rows );
	}

	public function test_status_filter_by_real_status_value(): void {
		$id = Translation_String::create( [ 'source_text' => 'Filter Conflict Term' ] );
		Translation::create( [ 'string_id' => $id, 'locale' => 'pt_BR', 'translation' => 'X', 'status' => 'conflict' ] );
		$rows = Translation_String::list_for_review( 'pt_BR', [ 'status' => 'conflict', 'search' => 'Filter Conflict Term' ] );
		$this->assertCount( 1, $rows );

		$rows_wrong_status = Translation_String::list_for_review( 'pt_BR', [ 'status' => 'approved', 'search' => 'Filter Conflict Term' ] );
		$this->assertCount( 0, $rows_wrong_status );
	}

	public function test_block_filter_matches_inside_the_used_in_json(): void {
		Translation_String::create( [
			'source_text' => 'Block Filter Term',
			'used_in'     => [ [ 'block' => 'vampire-blood-magic', 'section_type' => 'tiered_power', 'role' => 'level' ] ],
		] );
		$rows = Translation_String::list_for_review( 'pt_BR', [ 'block' => 'vampire-blood-magic', 'search' => 'Block Filter Term' ] );
		$this->assertCount( 1, $rows );

		$rows_wrong_block = Translation_String::list_for_review( 'pt_BR', [ 'block' => 'met-merits', 'search' => 'Block Filter Term' ] );
		$this->assertCount( 0, $rows_wrong_block );
	}

	public function test_search_filter_is_case_insensitive_substring(): void {
		Translation_String::create( [ 'source_text' => 'Searchable Unique Phrase' ] );
		$rows = Translation_String::list_for_review( 'pt_BR', [ 'search' => 'unique phrase' ] );
		$this->assertCount( 1, $rows );
	}

	public function test_has_translation_filter_true_and_false(): void {
		$untranslated_id = Translation_String::create( [ 'source_text' => 'HasTranslation False Term' ] );
		$translated_id    = Translation_String::create( [ 'source_text' => 'HasTranslation True Term' ] );
		Translation::create( [ 'string_id' => $translated_id, 'locale' => 'pt_BR', 'translation' => 'X' ] );

		$has = Translation_String::list_for_review( 'pt_BR', [ 'has_translation' => true, 'search' => 'HasTranslation True Term' ] );
		$this->assertCount( 1, $has );

		$has_not = Translation_String::list_for_review( 'pt_BR', [ 'has_translation' => false, 'search' => 'HasTranslation False Term' ] );
		$this->assertCount( 1, $has_not );

		$cross = Translation_String::list_for_review( 'pt_BR', [ 'has_translation' => false, 'search' => 'HasTranslation True Term' ] );
		$this->assertCount( 0, $cross, 'a translated term must not appear under has_translation=false' );
	}

	public function test_count_for_review_matches_list_for_review_under_the_same_filters(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Translation_String::create( [ 'source_text' => "Count Match Term {$i}" ] );
		}
		$filters = [ 'search' => 'Count Match Term' ];
		$count   = Translation_String::count_for_review( 'pt_BR', $filters );
		$rows    = Translation_String::list_for_review( 'pt_BR', $filters, 100 );
		$this->assertSame( 5, $count );
		$this->assertCount( 5, $rows );
	}

	public function test_list_for_review_pagination_per_page_and_offset(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			Translation_String::create( [ 'source_text' => "Pagination Term {$i}" ] );
		}
		$page_1 = Translation_String::list_for_review( 'pt_BR', [ 'search' => 'Pagination Term' ], 2, 0 );
		$page_2 = Translation_String::list_for_review( 'pt_BR', [ 'search' => 'Pagination Term' ], 2, 2 );
		$this->assertCount( 2, $page_1 );
		$this->assertCount( 1, $page_2 );
		$this->assertNotSame( $page_1[0]->id, $page_2[0]->id );
	}
}
