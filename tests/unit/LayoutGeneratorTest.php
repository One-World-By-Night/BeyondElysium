<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Layout_Generator;
use PHPUnit\Framework\TestCase;

/**
 * generate() takes an already-resolved stack + blocks map, so it needs no database - the
 * $wpdb-dependent lookup lives in generate_for_stack(), covered separately in the thread
 * layer.
 *
 * @see BE_PROCESS/workflow-0.3.md Step 4f, 8b-ii
 */
class LayoutGeneratorTest extends TestCase {

	private function block( string $slug, string $section_type, array $definition = [] ): object {
		return (object) [
			'slug'         => $slug,
			'name'         => ucfirst( $slug ),
			'section_type' => $section_type,
			'definition'   => (object) $definition,
		];
	}

	private function stack( array $sections ): object {
		return (object) [
			'slug'             => 'test-stack',
			'stack_definition' => (object) [
				'sections' => array_map( static fn( array $s ): object => (object) $s, $sections ),
			],
		];
	}

	public function test_identity_and_resource_pool_blocks_go_in_column_1_in_section_order(): void {
		$stack = $this->stack( [
			[ 'block_slug' => 'resources', 'display_order' => 80, 'label' => 'Resources' ],
			[ 'block_slug' => 'identity', 'display_order' => 1, 'label' => 'Identity' ],
		] );

		$blocks = [
			'identity'  => $this->block( 'identity', 'identity_field' ),
			'resources' => $this->block( 'resources', 'resource_pool' ),
		];

		$layout = Layout_Generator::generate( $stack, $blocks );

		$this->assertSame( 1, $layout['version'] );
		$this->assertSame( 3, $layout['columns'] );

		$col1 = array_values( array_filter( $layout['sections'], static fn( $s ) => $s['column'] === 1 ) );
		$this->assertCount( 2, $col1 );
		$this->assertSame( 'identity', $col1[0]['block_slug'], 'identity must come before resource pools' );
		$this->assertSame( 'resources', $col1[1]['block_slug'] );
		$this->assertSame( 1, $col1[0]['order'] );
		$this->assertSame( 2, $col1[1]['order'] );
	}

	public function test_trait_lists_and_tiered_powers_distribute_across_columns_2_and_3(): void {
		$stack = $this->stack( [
			[ 'block_slug' => 'abilities',   'display_order' => 30, 'label' => 'Abilities' ],
			[ 'block_slug' => 'disciplines', 'display_order' => 60, 'label' => 'Disciplines' ],
		] );

		$blocks = [
			'abilities'   => $this->block( 'abilities', 'trait_list', [ 'items' => [ (object) [ 'name' => 'a' ] ] ] ),
			'disciplines' => $this->block( 'disciplines', 'tiered_power', [ 'powers' => [ (object) [ 'name' => 'b' ] ] ] ),
		];

		$layout = Layout_Generator::generate( $stack, $blocks );
		$slugs_by_column = [];
		foreach ( $layout['sections'] as $section ) {
			$slugs_by_column[ $section['column'] ][] = $section['block_slug'];
		}

		$this->assertArrayHasKey( 2, $slugs_by_column );
		$this->assertArrayHasKey( 3, $slugs_by_column );
		$this->assertSame( [ 'abilities' ], $slugs_by_column[2] );
		$this->assertSame( [ 'disciplines' ], $slugs_by_column[3] );
	}

	public function test_balances_columns_by_running_item_count_not_just_alternating(): void {
		// A big block first, then two small ones - a naive alternator would put the two
		// small ones on opposite sides; balancing by load keeps both with the light column.
		$stack = $this->stack( [
			[ 'block_slug' => 'big',    'display_order' => 10 ],
			[ 'block_slug' => 'small1', 'display_order' => 20 ],
			[ 'block_slug' => 'small2', 'display_order' => 30 ],
		] );

		$blocks = [
			'big'    => $this->block( 'big', 'trait_list', [ 'items' => array_fill( 0, 10, (object) [ 'name' => 'x' ] ) ] ),
			'small1' => $this->block( 'small1', 'trait_list', [ 'items' => [ (object) [ 'name' => 'x' ] ] ] ),
			'small2' => $this->block( 'small2', 'trait_list', [ 'items' => [ (object) [ 'name' => 'x' ] ] ] ),
		];

		$layout = Layout_Generator::generate( $stack, $blocks );
		$slugs_by_column = [];
		foreach ( $layout['sections'] as $section ) {
			$slugs_by_column[ $section['column'] ][] = $section['block_slug'];
		}

		$this->assertSame( [ 'big' ], $slugs_by_column[2] );
		$this->assertSame( [ 'small1', 'small2' ], $slugs_by_column[3] );
	}

	public function test_unknown_block_slug_in_stack_definition_is_skipped(): void {
		$stack = $this->stack( [
			[ 'block_slug' => 'ghost', 'display_order' => 1 ],
			[ 'block_slug' => 'real', 'display_order' => 2 ],
		] );

		$blocks = [ 'real' => $this->block( 'real', 'identity_field' ) ];

		$layout = Layout_Generator::generate( $stack, $blocks );

		$this->assertCount( 1, $layout['sections'] );
		$this->assertSame( 'real', $layout['sections'][0]['block_slug'] );
	}

	public function test_section_title_falls_back_to_block_name_when_no_label(): void {
		$stack  = $this->stack( [ [ 'block_slug' => 'identity', 'display_order' => 1 ] ] );
		$blocks = [ 'identity' => $this->block( 'identity', 'identity_field' ) ];

		$layout = Layout_Generator::generate( $stack, $blocks );

		$this->assertSame( 'Identity', $layout['sections'][0]['title'] );
	}

	public function test_generated_layout_passes_schema_shape( ): void {
		$stack  = $this->stack( [ [ 'block_slug' => 'identity', 'display_order' => 1, 'label' => 'Identity' ] ] );
		$blocks = [ 'identity' => $this->block( 'identity', 'identity_field' ) ];

		$layout = Layout_Generator::generate( $stack, $blocks );

		$this->assertSame( 1, $layout['version'] );
		$this->assertIsInt( $layout['columns'] );
		foreach ( $layout['sections'] as $section ) {
			$this->assertArrayHasKey( 'block_slug', $section );
			$this->assertArrayHasKey( 'column', $section );
			$this->assertArrayHasKey( 'order', $section );
			$this->assertArrayHasKey( 'title', $section );
			$this->assertArrayHasKey( 'display', $section );
			$this->assertArrayHasKey( 'collapsed', $section );
			$this->assertNull( $section['display'] );
			$this->assertFalse( $section['collapsed'] );
		}
	}
}
