<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;
use BeyondElysium\Services\Catalog_Translator;
use WP_UnitTestCase;

/**
 * B4 (1.2.0 releases/1.2.0-design-workflow.md §5.2): Catalog_Translator::decorate() wired into
 * Schema_Block::decode_row(), the single choke point every read of a block passes through.
 * Verified live against a real REST response before this file existed (200 status, the real
 * marker translation and a real name_pt key both present in the raw JSON, then cleaned up out
 * of the real dev database) - this pins that same guarantee as a repeatable regression test,
 * and additionally proves the "single choke point" claim by exercising find_by_slugs() and
 * find_by_slugs_for_game(), not just find_by_slug() alone.
 *
 * @see BE_PROCESS/releases/1.2.0-design-workflow.md §5.2
 */
class SchemaBlockDecorationThreadTest extends WP_UnitTestCase {

	private function make_translated_block( string $slug, string $term, string $translation ): void {
		Schema_Block::create( [
			'slug'         => $slug,
			'name'         => $slug,
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [ 'name' => $term ] ] ],
		] );
		$string_id = (int) Translation_String::create( [ 'source_text' => $term ] );
		Translation::create( [ 'string_id' => $string_id, 'locale' => get_locale(), 'translation' => $translation ] );
		Catalog_Translator::bust_cache();
	}

	public function test_find_by_slug_decorates(): void {
		$this->make_translated_block( 'decoration-fixture-a', 'Decorated Via Find By Slug', 'Decorado' );
		$block = Schema_Block::find_by_slug( 'decoration-fixture-a' );
		$this->assertSame( 'Decorado', $block->definition->items[0]->name_pt );
		$this->assertSame( 'Decorated Via Find By Slug', $block->definition->items[0]->name, 'canonical name must be untouched' );
	}

	public function test_find_by_slugs_decorates_every_block_in_the_batch(): void {
		$this->make_translated_block( 'decoration-fixture-b1', 'Decorated Batch Term One', 'Um' );
		$this->make_translated_block( 'decoration-fixture-b2', 'Decorated Batch Term Two', 'Dois' );

		$blocks = Schema_Block::find_by_slugs( [ 'decoration-fixture-b1', 'decoration-fixture-b2' ] );

		$this->assertSame( 'Um', $blocks['decoration-fixture-b1']->definition->items[0]->name_pt ?? null );
		$this->assertSame( 'Dois', $blocks['decoration-fixture-b2']->definition->items[0]->name_pt ?? null );
	}

	public function test_find_by_slugs_for_game_decorates(): void {
		$this->make_translated_block( 'decoration-fixture-c', 'Decorated Game Scoped Term', 'Traduzido' );
		$blocks = Schema_Block::find_by_slugs_for_game( [ 'decoration-fixture-c' ], '' );
		$this->assertSame( 'Traduzido', $blocks['decoration-fixture-c']->definition->items[0]->name_pt ?? null );
	}

	/**
	 * The steady-state case, and the one §5.1 says must be cheap: a block whose terms carry no
	 * translation for the current locale reads back completely unchanged - no name_pt key at
	 * all, not an empty string, matching every other consumer's English-fallback rule.
	 */
	public function test_an_untranslated_block_is_unchanged(): void {
		Schema_Block::create( [
			'slug' => 'decoration-fixture-untranslated', 'name' => 'Untranslated Fixture', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [ [ 'name' => 'Never Given A Translation' ] ] ],
		] );
		$block = Schema_Block::find_by_slug( 'decoration-fixture-untranslated' );
		$this->assertObjectNotHasProperty( 'name_pt', $block->definition->items[0] );
	}

	/** Decoration must not disturb the pre-existing is_system/storyteller_only boolean cast (D51/D53). */
	public function test_decoration_does_not_disturb_the_existing_boolean_casts(): void {
		$this->make_translated_block( 'decoration-fixture-bool', 'Decorated Bool Check Term', 'X' );
		$block = Schema_Block::find_by_slug( 'decoration-fixture-bool' );
		$this->assertIsBool( $block->is_system );
		$this->assertIsBool( $block->storyteller_only );
	}
}
