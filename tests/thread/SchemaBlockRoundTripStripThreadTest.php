<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;
use BeyondElysium\Services\Catalog_Translator;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * An admin editor's GET (decorated), edit and PUT round trip never persists translation keys into the stored
 * definition.
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

		// The client's own PUT, echoing that decorated definition straight back.
		$request = new WP_REST_Request( 'PUT', '/be/v1/schema-blocks/roundtrip-fixture' );
		$request->set_body_params( [ 'definition' => $fetched->definition ] );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), 'the PUT itself must succeed, not merely be rejected' );

		// The real, raw, undecorated database row.
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
	 * The third real write path (found executing this box, not named in its own text): a chronicle's first edit forks the
	 * global block via find_or_create_fork_for_game().
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
