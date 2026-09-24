<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Catalog_Reader;
use WP_UnitTestCase;

/**
 * The default sheet layout each creature type is seeded with: its sections name that type's own blocks and match the
 * declared template files, and the repair of stale layouts leaves a layout that already names them as it is.
 */
class SeederTemplateSectionsThreadTest extends WP_UnitTestCase {

	public function test_the_default_layout_names_the_creature_types_own_blocks_and_keeps_title_refs(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}

		$layout      = Seeder::rebuild_default_layout_for_stack( 'vampire' );
		$layout_by   = array_column( $layout['sections'], null, 'block_slug' );
		$this->assertArrayHasKey( 'vampire-abilities', $layout_by );
		$this->assertArrayNotHasKey( 'met-abilities', $layout_by );

		// The Virtues row reads the Morality Path and its rating from two other blocks.
		$virtues_refs = $layout_by['vampire-virtues']['title_refs'] ?? null;
		$this->assertNotNull( $virtues_refs );
		$this->assertCount( 2, $virtues_refs );
		$this->assertSame( 'vampire-identity', $virtues_refs[0]['block_slug'] );
		$this->assertSame( 'Morality Path', $virtues_refs[0]['field'] );
	}

	/**
	 * Every section of a default layout is one the declared `sheet_full` template also carries, with the same width and
	 * display, in the same order.
	 */
	public function test_every_default_layout_agrees_with_the_declared_sheet_template(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}

		$templates = Catalog_Reader::templates_to_seed();
		foreach ( array_keys( Catalog_Reader::stacks_to_seed() ) as $stack ) {
			$layout   = Seeder::rebuild_default_layout_for_stack( $stack );
			$declared = $templates[ "{$stack}.sheet_full" ]['layout']['sections'] ?? [];
			$this->assertNotNull( $layout, $stack );
			$this->assertNotEmpty( $declared, $stack );

			$declared_by = array_column( $declared, null, 'block_slug' );
			$last        = -1;
			foreach ( $layout['sections'] as $section ) {
				$slug = $section['block_slug'];
				$this->assertArrayHasKey( $slug, $declared_by, "{$stack}: {$slug} is not in the declared sheet" );
				$this->assertSame( $declared_by[ $slug ]['width'] ?? null, $section['width'], "{$stack}: {$slug} width" );
				$this->assertSame( $declared_by[ $slug ]['display'] ?? null, $section['display'] ?? null, "{$stack}: {$slug} display" );
				$position = array_search( $slug, array_column( $declared, 'block_slug' ), true );
				$this->assertGreaterThan( $last, $position, "{$stack}: {$slug} is out of order" );
				$last = $position;
			}
		}
	}

	public function test_the_repair_leaves_a_template_that_names_the_current_blocks_as_it_is(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}

		$id = Template::create( [
			'stack_slug'    => 'vampire',
			'template_type' => 'sheet_full',
			'name'          => 'Vampire Sheet',
			'layout'        => Seeder::rebuild_default_layout_for_stack( 'vampire' ),
			'is_system'     => 1,
		] );

		Schema::repair_stale_default_layouts();

		$slugs = array_column( Template::find( $id )->layout['sections'], 'block_slug' );
		$this->assertContains( 'vampire-abilities', $slugs );
		$this->assertNotContains( 'met-abilities', $slugs );
	}
}
