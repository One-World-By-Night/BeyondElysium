<?php

namespace BeyondElysium\Tests\Thread;

use WP_UnitTestCase;

/**
 * B1 (1.2.0 releases/1.2.0-design-workflow.md §4/§10): be_translation_strings and
 * be_translations exist with the shape the release document specifies, and the one real gap in
 * that shape - MySQL's UNIQUE key does not treat two NULL values as equal, so
 * UNIQUE(string_id, locale, context) does not by itself stop two context-less rows for the same
 * string+locale - is pinned here rather than left to be rediscovered. This is not a "watched
 * failing" test: the last two tests assert the gap's current, correct, documented behavior and
 * are expected to keep passing after B2 ships its own application-level guard.
 *
 * @see BE_PROCESS/releases/1.2.0-design-workflow.md §4
 */
class TranslationTablesSchemaThreadTest extends WP_UnitTestCase {

	private static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'be_' . $name;
	}

	public function test_both_tables_exist(): void {
		global $wpdb;
		foreach ( [ 'translation_strings', 'translations' ] as $name ) {
			$table = self::table( $name );
			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
				"$table does not exist"
			);
		}
	}

	public function test_translation_strings_has_the_documented_columns(): void {
		global $wpdb;
		$columns = $wpdb->get_col( 'DESCRIBE ' . self::table( 'translation_strings' ) );
		$this->assertEqualsCanonicalizing(
			[ 'id', 'source_key', 'source_text', 'used_in', 'first_seen', 'last_seen' ],
			$columns
		);
	}

	public function test_translations_has_the_documented_columns(): void {
		global $wpdb;
		$columns = $wpdb->get_col( 'DESCRIBE ' . self::table( 'translations' ) );
		$this->assertEqualsCanonicalizing(
			[ 'id', 'string_id', 'locale', 'context', 'translation', 'status', 'note', 'updated_by', 'updated_at' ],
			$columns
		);
	}

	public function test_source_key_is_unique(): void {
		global $wpdb;
		$table = self::table( 'translation_strings' );
		$wpdb->insert( $table, [ 'source_key' => 'thread-dupe-key', 'source_text' => 'Thread Dupe' ] );
		$first_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $first_id );

		$result = $wpdb->insert( $table, [ 'source_key' => 'thread-dupe-key', 'source_text' => 'Thread Dupe Again' ] );
		$this->assertFalse( $result, 'a second row with the same source_key must be rejected' );
	}

	public function test_string_locale_context_is_unique_when_context_is_set(): void {
		global $wpdb;
		$strings = self::table( 'translation_strings' );
		$wpdb->insert( $strings, [ 'source_key' => 'thread-context-key', 'source_text' => 'Thread Context' ] );
		$string_id = (int) $wpdb->insert_id;

		$translations = self::table( 'translations' );
		$wpdb->insert( $translations, [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'context' => 'met-merits', 'translation' => 'A' ] );
		$this->assertGreaterThan( 0, (int) $wpdb->insert_id );

		$result = $wpdb->insert( $translations, [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'context' => 'met-merits', 'translation' => 'B' ] );
		$this->assertFalse( $result, 'a second row with the same (string_id, locale, context) must be rejected' );
	}

	/**
	 * The documented gap, not a bug in this test: MySQL's UNIQUE key does not consider two NULLs
	 * equal, so two default (context IS NULL) rows for the same string+locale both insert
	 * successfully at the raw SQL level. Models\Translation's upsert (B2) must guard this with an
	 * explicit NULL-safe SELECT before insert - the schema comment says so and this pins the fact
	 * it is guarding against.
	 */
	public function test_two_null_context_rows_are_not_stopped_by_the_db_alone(): void {
		global $wpdb;
		$strings = self::table( 'translation_strings' );
		$wpdb->insert( $strings, [ 'source_key' => 'thread-null-context-key', 'source_text' => 'Thread Null Context' ] );
		$string_id = (int) $wpdb->insert_id;

		$translations = self::table( 'translations' );
		$wpdb->insert( $translations, [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'translation' => 'First' ] );
		$first = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $first );

		$wpdb->insert( $translations, [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'translation' => 'Second' ] );
		$second = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $second, 'documents that the DB layer permits this; the guard belongs in Models\Translation' );
		$this->assertNotSame( $first, $second );
	}

	public function test_status_defaults_to_draft(): void {
		global $wpdb;
		$strings = self::table( 'translation_strings' );
		$wpdb->insert( $strings, [ 'source_key' => 'thread-default-status-key', 'source_text' => 'Thread Default Status' ] );
		$string_id = (int) $wpdb->insert_id;

		$translations = self::table( 'translations' );
		$wpdb->insert( $translations, [ 'string_id' => $string_id, 'locale' => 'pt_BR', 'translation' => 'X' ] );
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $translations WHERE id = %d", $wpdb->insert_id ) );
		$this->assertSame( 'draft', $status );
	}
}
