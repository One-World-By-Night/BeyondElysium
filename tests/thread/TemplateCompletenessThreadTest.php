<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Template;
use WP_UnitTestCase;

/**
 * (seeded side): a block a stack declares but no template shows never renders.
 */
class TemplateCompletenessThreadTest extends WP_UnitTestCase {

	/**
	 * Every block slug a stack declares, in its own declared order.
	 */
	private function declared_blocks( string $stack_slug ): array {
		$stack = Creature_Stack::find_by_slug( $stack_slug );
		$slugs = [];
		foreach ( $stack->stack_definition->sections ?? [] as $section ) {
			if ( ! empty( $section->block_slug ) ) {
				$slugs[] = (string) $section->block_slug;
			}
		}
		return $slugs;
	}

	/**
	 * The block slugs one template actually shows.
	 */
	private function shown_blocks( int $template_id ): array {
		return array_column( Template::find( $template_id )->layout['sections'] ?? [], 'block_slug' );
	}

	/**
	 * One template's layout as a stable, comparable value.
	 */
	private function layout_fingerprint( int $template_id ): string {
		$sections = Template::find( $template_id )->layout['sections'] ?? [];
		$rows     = [];
		foreach ( $sections as $section ) {
			ksort( $section );
			$rows[] = $section;
		}
		return (string) wp_json_encode( $rows );
	}

	/**
	 * The requirement itself, across all eleven stacks: after the repair, no full sheet is missing anything its own stack
	 * declares.
	 */
	public function test_every_system_full_template_shows_every_block_its_stack_declares(): void {
		Schema::complete_full_sheet_templates();

		$incomplete = [];
		foreach ( Creature_Stack::all() as $stack ) {
			$declared = $this->declared_blocks( $stack->slug );
			foreach ( [ 'sheet_full', 'npc_full' ] as $type ) {
				foreach ( Template::globals( [ 'stack_slug' => $stack->slug, 'template_type' => $type ] ) as $template ) {
					if ( empty( $template->is_system ) ) {
						continue;
					}
					$missing = array_diff( $declared, $this->shown_blocks( (int) $template->id ) );
					if ( $missing ) {
						$incomplete[] = "{$stack->slug}/{$type}: " . implode( ', ', $missing );
					}
				}
			}
		}

		$this->assertSame( [], $incomplete, "Full sheets still missing declared blocks:\n" . implode( "\n", $incomplete ) );
	}

	public function test_a_stripped_health_section_is_restored_beside_its_own_kind(): void {
		$template = Template::globals( [ 'stack_slug' => 'vampire', 'template_type' => 'sheet_full' ] )[0];
		$layout   = $template->layout;

		$layout['sections'] = array_values( array_filter(
			$layout['sections'],
			static fn( $section ) => $section['block_slug'] !== 'vampire-health'
		) );
		Template::update( (int) $template->id, [ 'layout' => $layout ] );
		$this->assertNotContains( 'vampire-health', $this->shown_blocks( (int) $template->id ) );

		Schema::complete_full_sheet_templates();

		$slugs = $this->shown_blocks( (int) $template->id );
		$this->assertContains( 'vampire-health', $slugs );
		// Declared immediately after vampire-virtues.
		$this->assertSame(
			array_search( 'vampire-virtues', $slugs, true ) + 1,
			array_search( 'vampire-health', $slugs, true ),
			'Health was appended somewhere arbitrary instead of beside its own kind.'
		);
	}

	/**
	 * A restored section carries a real width and a real title.
	 */
	public function test_a_restored_section_is_fully_formed(): void {
		Schema::complete_full_sheet_templates();

		$template = Template::globals( [ 'stack_slug' => 'vampire', 'template_type' => 'sheet_full' ] )[0];
		$health   = null;
		foreach ( Template::find( (int) $template->id )->layout['sections'] as $section ) {
			if ( $section['block_slug'] === 'vampire-health' ) {
				$health = $section;
			}
		}

		$this->assertNotNull( $health );
		$this->assertNotEmpty( $health['width'] );
		$this->assertSame( 'Health', $health['title'] );
		$this->assertArrayHasKey( 'order', $health );
		$this->assertSame( 'multiplier_dot', $health['display'] );
	}

	/**
	 * An atomic list is name-only by declaration.
	 */
	public function test_a_restored_atomic_list_stays_name_only(): void {
		Schema::complete_full_sheet_templates();

		$template = Template::globals( [ 'stack_slug' => 'werewolf', 'template_type' => 'sheet_full' ] )[0];
		foreach ( Template::find( (int) $template->id )->layout['sections'] as $section ) {
			if ( $section['block_slug'] === 'met-derangements' ) {
				$this->assertNull( $section['display'] );
				return;
			}
		}
		$this->fail( 'met-derangements was never restored to werewolf/sheet_full.' );
	}

	/**
	 * `npc_quick` is exempt by design: all ten are identity + `npc-quick-stats` + `npc-roleplaying-notes`, a reference
	 * card.
	 */
	public function test_npc_quick_templates_are_left_alone(): void {
		$before = [];
		foreach ( Creature_Stack::all() as $stack ) {
			foreach ( Template::globals( [ 'stack_slug' => $stack->slug, 'template_type' => 'npc_quick' ] ) as $template ) {
				$before[ (int) $template->id ] = $this->layout_fingerprint( (int) $template->id );
			}
		}
		$this->assertNotEmpty( $before, 'No npc_quick templates seeded - this test would prove nothing.' );

		Schema::complete_full_sheet_templates();

		foreach ( $before as $id => $layout ) {
			$this->assertSame( $layout, $this->layout_fingerprint( $id ), "npc_quick template {$id} was modified." );
		}
	}

	/**
	 * A chronicle's own template is never touched.
	 */
	public function test_a_chronicles_own_template_keeps_its_arrangement(): void {
		$trimmed = [
			'version'  => 1,
			'columns'  => 3,
			'sections' => [
				[ 'block_slug' => 'vampire-identity', 'column' => 1, 'order' => 1, 'title' => 'Identity', 'display' => null, 'collapsed' => false, 'width' => 'full' ],
				[ 'block_slug' => 'vampire-disciplines', 'column' => 1, 'order' => 2, 'title' => 'Powers', 'display' => null, 'collapsed' => false, 'width' => 'half' ],
			],
		];
		$id = Template::create( [
			'stack_slug'    => 'vampire',
			'template_type' => 'sheet_full',
			'name'          => "A Chronicle's Own Sheet",
			'layout'        => $trimmed,
			'is_system'     => 0,
		] );

		Schema::complete_full_sheet_templates();

		$expected = [];
		foreach ( $trimmed['sections'] as $section ) {
			ksort( $section );
			$expected[] = $section;
		}
		$this->assertSame( (string) wp_json_encode( $expected ), $this->layout_fingerprint( $id ) );
	}

	/**
	 * Idempotent: the second run is a no-op, byte for byte.
	 */
	public function test_running_it_twice_changes_nothing(): void {
		Schema::complete_full_sheet_templates();

		$after_first = [];
		foreach ( Creature_Stack::all() as $stack ) {
			foreach ( [ 'sheet_full', 'npc_full' ] as $type ) {
				foreach ( Template::globals( [ 'stack_slug' => $stack->slug, 'template_type' => $type ] ) as $template ) {
					$after_first[ (int) $template->id ] = $this->layout_fingerprint( (int) $template->id );
				}
			}
		}
		$this->assertNotEmpty( $after_first );

		Schema::complete_full_sheet_templates();

		foreach ( $after_first as $id => $layout ) {
			$this->assertSame( $layout, $this->layout_fingerprint( $id ), "Template {$id} changed on the second run." );
		}
	}
}
