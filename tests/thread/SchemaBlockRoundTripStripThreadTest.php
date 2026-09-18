<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;
use BeyondElysium\Services\Catalog_Translator;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * B5, T4 (1.2.0 releases/1.2.0-design-workflow.md §5.5, §9): the round-trip hazard. An admin
 * editor does GET (decorated) -> user edits -> PUT the whole definition back. Without a strip()
 * on the write side, the editor faithfully PUTs the `_pt` keys it was decorated with straight
 * back into the stored definition - not a crash, a silent, permanent, per-fork duplication of
 * exactly the data this release exists to stop duplicating.
 *
 * test_a_decorated_definition_put_through_the_real_controller_is_not_persisted_with_pt_keys is
 * T4 exactly as named in §9 - written first and run against the pre-fix code, where it fails,
 * before Schema_Block::create()/::update() gained their strip() calls.
 *
 * @see BE_PROCESS/releases/1.2.0-design-workflow.md §5.5
 */
class SchemaBlockRoundTripStripThreadTest extends WP_UnitTestCase {

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
	 * T4. Real GET through decode_row() (decorated), the client's own edit simulated by taking
	 * that exact decorated JSON and PUTting it back unchanged (what an editor that round-trips
	 * the whole definition actually does), through the real REST controller - not a hand-built
	 * fixture standing in for one.
	 */
	public function test_a_decorated_definition_put_through_the_real_controller_is_not_persisted_with_pt_keys(): void {
		$this->make_admin();

		Schema_Block::create( [
			'slug' => 'roundtrip-fixture', 'name' => 'Roundtrip Fixture', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [ [ 'name' => 'Roundtrip Term' ] ] ],
		] );
		$string_id = (int) Translation_String::create( [ 'source_text' => 'Roundtrip Term' ] );
		Translation::create( [ 'string_id' => $string_id, 'locale' => get_locale(), 'translation' => 'Termo De Ida E Volta' ] );
		Catalog_Translator::bust_cache();

		// The real GET, decorated.
		$fetched = Schema_Block::find_by_slug( 'roundtrip-fixture' );
		$this->assertSame( 'Termo De Ida E Volta', $fetched->definition->items[0]->name_pt, 'sanity check: GET really is decorated' );

		// The client's own PUT, echoing that decorated definition straight back - exactly what
		// an editor that reads the whole definition and writes the whole definition back does.
		$request = new WP_REST_Request( 'PUT', '/be/v1/schema-blocks/roundtrip-fixture' );
		$request->set_body_params( [ 'definition' => $fetched->definition ] );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), 'the PUT itself must succeed, not merely be rejected' );

		// The real, raw, undecorated database row - not another decorated read, which would
		// mask the bug by decorating right back over whatever was actually persisted.
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT definition FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = ''",
			'roundtrip-fixture'
		) );
		$this->assertStringNotContainsString( '"name_pt"', $raw, 'a _pt key survived the round trip into permanent storage' );
	}

	public function test_create_never_persists_a_pt_key_even_if_the_caller_supplies_one(): void {
		Schema_Block::create( [
			'slug' => 'create-strip-fixture', 'name' => 'Create Strip Fixture', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [ [ 'name' => 'Created With Pt', 'name_pt' => 'Should Not Survive' ] ] ],
		] );

		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT definition FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = ''",
			'create-strip-fixture'
		) );
		$this->assertStringNotContainsString( '"name_pt"', $raw );
		$this->assertStringContainsString( 'Created With Pt', $raw, 'the canonical name must still be saved' );
	}

	public function test_update_never_persists_a_pt_key_even_if_the_caller_supplies_one(): void {
		Schema_Block::create( [
			'slug' => 'update-strip-fixture', 'name' => 'Update Strip Fixture', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [ [ 'name' => 'Updated Term' ] ] ],
		] );
		Schema_Block::update( 'update-strip-fixture', [
			'definition' => [ 'items' => [ [ 'name' => 'Updated Term', 'name_pt' => 'Should Not Survive Either' ] ] ],
		] );

		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT definition FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = ''",
			'update-strip-fixture'
		) );
		$this->assertStringNotContainsString( '"name_pt"', $raw );
	}

	/**
	 * The third real write path (found executing this box, not named in its own text): a
	 * chronicle's first edit forks the global block via find_or_create_fork_for_game(), which
	 * copies find_by_slug()'s own DECORATED definition into the new fork row. Without its own
	 * strip(), the fork would be born with baked-in _pt keys on its very first row.
	 */
	public function test_a_new_fork_is_not_born_with_pt_keys_from_the_decorated_global_block(): void {
		Schema_Block::create( [
			'slug' => 'fork-strip-fixture', 'name' => 'Fork Strip Fixture', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [ [ 'name' => 'Fork Strip Term' ] ] ],
		] );
		$string_id = (int) Translation_String::create( [ 'source_text' => 'Fork Strip Term' ] );
		Translation::create( [ 'string_id' => $string_id, 'locale' => get_locale(), 'translation' => 'Termo Do Fork' ] );
		Catalog_Translator::bust_cache();

		Schema_Block::find_or_create_fork_for_game( 'fork-strip-fixture', 'thread-fork-game' );

		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT definition FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = %s",
			'fork-strip-fixture',
			'thread-fork-game'
		) );
		$this->assertNotNull( $raw, 'the fork must actually have been created' );
		$this->assertStringNotContainsString( '"name_pt"', $raw );
	}

	/**
	 * B9 retired the one legitimate writer §5.5's strip() guard used to carve an exception out
	 * for (Seeder's own CSV-sourced name_pt, via the now-removed skip_pt_strip parameter) - this
	 * pins that the exception is genuinely gone, not merely unused: data shaped exactly like what
	 * Seeder used to write is stripped like any other caller's, with no escape hatch left to
	 * reach for by mistake.
	 */
	public function test_no_caller_can_persist_a_pt_key_the_skip_pt_strip_escape_hatch_is_gone(): void {
		Schema_Block::create( [
			'slug' => 'no-escape-hatch-fixture', 'name' => 'No Escape Hatch Fixture', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [ [ 'name' => 'No Escape Hatch Term', 'name_pt' => 'Nao Deve Sobreviver' ] ] ],
		] );

		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT definition FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = ''",
			'no-escape-hatch-fixture'
		) );
		$this->assertStringNotContainsString( '"name_pt"', $raw );

		Schema_Block::update( 'no-escape-hatch-fixture', [
			'definition' => [ 'items' => [ [ 'name' => 'No Escape Hatch Term', 'name_pt' => 'Tambem Nao' ] ] ],
		] );

		$raw_after = $wpdb->get_var( $wpdb->prepare(
			"SELECT definition FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = ''",
			'no-escape-hatch-fixture'
		) );
		$this->assertStringNotContainsString( '"name_pt"', $raw_after );
	}
}
