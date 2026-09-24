<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use WP_UnitTestCase;

/**
 * C6: `Catalog_Cutover::rewrite_templates()` - every template's own retired slugs, and
 * every `title_refs[].block_slug`, mapped onto the live replacement in place. Reaches a
 * chronicle's own forked template exactly as much as a global one, which is the whole
 * reason this is a separate sweep from `Schema::repair_stale_default_layouts()`
 * (`VampireTemplateRepairTest.php`), which deliberately skips non-system rows.
 */
class CatalogCutoverRewriteTemplatesThreadTest extends WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		Catalog_Cutover::reset_cache();
		parent::tearDown();
	}

	/** A minimal layout naming two real vampire retirements, one of them via title_refs. */
	private function layout_on_retired_slugs(): array {
		return [
			'version'  => 1,
			'columns'  => 6,
			'sections' => [
				[ 'block_slug' => 'vampire-identity', 'column' => 1, 'order' => 1, 'title' => 'Identity', 'display' => null, 'collapsed' => false, 'width' => 'half' ],
				[
					'block_slug' => 'met-abilities', 'column' => 2, 'order' => 1, 'title' => 'Abilities',
					'display' => 'multiplier_dot', 'collapsed' => false, 'width' => 'half',
					// Synthetic: no real title_refs entry points at a retired slug today, but
					// the mechanism must remap one if it ever did.
					'title_refs' => [ [ 'block_slug' => 'met-merits', 'field' => 'Some Field' ] ],
				],
			],
		];
	}

	public function test_rewrites_a_global_system_template(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		$id = Template::create( [
			'stack_slug'    => 'vampire',
			'template_type' => 'sheet_full',
			'name'          => 'Vampire Sheet',
			'layout'        => $this->layout_on_retired_slugs(),
			'is_system'     => 1,
		] );

		Catalog_Cutover::rewrite_templates();

		$by_slug = array_column( Template::find( $id )->layout['sections'], null, 'block_slug' );
		$this->assertArrayHasKey( 'vampire-abilities', $by_slug );
		$this->assertArrayNotHasKey( 'met-abilities', $by_slug );
		$this->assertSame( 'vampire-merits', $by_slug['vampire-abilities']['title_refs'][0]['block_slug'] );
		// Untouched fields survive exactly.
		$this->assertSame( 'multiplier_dot', $by_slug['vampire-abilities']['display'] );
		$this->assertSame( 'half', $by_slug['vampire-abilities']['width'] );
	}

	public function test_rewrites_a_chronicles_own_template_too(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		$game_id = Game::create( [
			'name' => 'Cutover Rewrite Fixture', 'slug' => 'cutover-rewrite-fixture', 'game_type' => 'met',
		] );

		$id = Template::create( [
			'game_id'       => $game_id,
			'stack_slug'    => 'vampire',
			'template_type' => 'sheet_full',
			'name'          => "Chronicle's Own Vampire Sheet",
			'layout'        => $this->layout_on_retired_slugs(),
			'is_system'     => 0,
		] );

		Catalog_Cutover::rewrite_templates();

		$slugs = array_column( Template::find( $id )->layout['sections'], 'block_slug' );
		$this->assertContains( 'vampire-abilities', $slugs );
		$this->assertNotContains( 'met-abilities', $slugs, 'a chronicle-owned template must be rewritten too, not only globals' );
	}

	public function test_a_second_run_writes_nothing(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		Template::create( [
			'stack_slug'    => 'vampire',
			'template_type' => 'sheet_full',
			'name'          => 'Vampire Sheet',
			'layout'        => $this->layout_on_retired_slugs(),
			'is_system'     => 1,
		] );

		$first  = Catalog_Cutover::rewrite_templates();
		$second = Catalog_Cutover::rewrite_templates();

		$this->assertNotEmpty( $first, 'precondition: the first run must have actually changed something' );
		$this->assertSame( [], $second );
	}

	public function test_a_template_on_no_retired_slug_is_left_alone(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		$id = Template::create( [
			'stack_slug'    => 'vampire',
			'template_type' => 'sheet_full',
			'name'          => 'Vampire Sheet',
			'layout'        => [
				'version' => 1, 'columns' => 6,
				'sections' => [
					[ 'block_slug' => 'vampire-identity', 'column' => 1, 'order' => 1, 'title' => 'Identity', 'display' => null, 'collapsed' => false, 'width' => 'half' ],
				],
			],
			'is_system'     => 1,
		] );

		$rewritten = Catalog_Cutover::rewrite_templates();

		// Other, real bootstrap-seeded templates also carry retired slugs and are correctly
		// swept in the same call - this asserts only that THIS fixture, which names no
		// retired slug, is not among them, not that the whole return value is empty.
		$this->assertNotContains( $id, array_column( $rewritten, 'id' ) );
		$this->assertSame( 'vampire-identity', Template::find( $id )->layout['sections'][0]['block_slug'] );
	}
}
