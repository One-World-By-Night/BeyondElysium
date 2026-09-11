<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Template;
use WP_UnitTestCase;

/**
 * `Schema::repair_stale_default_layouts()` is the one-time data correction for a template
 * already seeded before a `Seeder::default_template_sections()` fix shipped - seeding is
 * deliberately idempotent ("skip if a global template already exists"), so fixing the
 * source data alone never reaches an install that seeded before the fix. Two real
 * corrections have needed this: vampire's layout was missing Combo Disciplines and Ritae
 * entirely (found comparing the stack's own definition to what actually rendered), and
 * every stack's layout was still the old fixed-3-column shape, missing the `width` field
 * the real 6-track grid needs for a 50/50 or full-width row. Both found and fixed
 * 2026-09-09.
 */
class VampireTemplateRepairTest extends WP_UnitTestCase {

	/** A layout in the pre-width shape - every real template seeded before 2026-09-09
	 * looked exactly like this: real sections, but no `width` key anywhere. */
	private function stale_layout(): array {
		return [
			'version'  => 1,
			'columns'  => 3,
			'sections' => [
				[ 'block_slug' => 'vampire-identity', 'column' => 1, 'order' => 1, 'title' => 'Identity', 'display' => null, 'collapsed' => false ],
				[ 'block_slug' => 'vampire-disciplines', 'column' => 2, 'order' => 1, 'title' => 'Disciplines', 'display' => null, 'collapsed' => false ],
				[ 'block_slug' => 'vampire-backgrounds', 'column' => 3, 'order' => 1, 'title' => 'Backgrounds', 'display' => 'multiplier_dot', 'collapsed' => false ],
			],
		];
	}

	public function test_a_stale_system_template_gains_the_missing_sections(): void {
		$id = Template::create( [
			'stack_slug'    => 'vampire',
			'template_type' => 'sheet_full',
			'name'          => 'Vampire Sheet',
			'layout'        => $this->stale_layout(),
			'is_system'     => 1,
		] );

		Schema::repair_stale_default_layouts();

		$slugs = array_column( Template::find( $id )->layout['sections'], 'block_slug' );
		$this->assertContains( 'vampire-ritae', $slugs );
		$this->assertContains( 'vampire-combo-disciplines', $slugs );
	}

	public function test_repaired_sections_carry_a_real_width(): void {
		$id = Template::create( [
			'stack_slug'    => 'vampire',
			'template_type' => 'sheet_full',
			'name'          => 'Vampire Sheet',
			'layout'        => $this->stale_layout(),
			'is_system'     => 1,
		] );

		Schema::repair_stale_default_layouts();

		foreach ( Template::find( $id )->layout['sections'] as $section ) {
			$this->assertNotEmpty( $section['width'] ?? null, $section['block_slug'] . ' is missing a width after repair' );
		}
	}

	public function test_running_it_twice_does_not_duplicate_sections(): void {
		$id = Template::create( [
			'stack_slug'    => 'vampire',
			'template_type' => 'sheet_full',
			'name'          => 'Vampire Sheet',
			'layout'        => $this->stale_layout(),
			'is_system'     => 1,
		] );

		Schema::repair_stale_default_layouts();
		Schema::repair_stale_default_layouts();

		$slugs = array_column( Template::find( $id )->layout['sections'], 'block_slug' );
		$this->assertSame( 1, count( array_keys( $slugs, 'vampire-ritae', true ) ) );
	}

	public function test_a_chronicles_own_non_system_template_is_left_untouched(): void {
		$id = Template::create( [
			'stack_slug'    => 'vampire',
			'template_type' => 'sheet_full',
			'name'          => 'My Chronicle Vampire Sheet',
			'layout'        => $this->stale_layout(),
			'is_system'     => 0,
		] );

		Schema::repair_stale_default_layouts();

		$slugs = array_column( Template::find( $id )->layout['sections'], 'block_slug' );
		$this->assertNotContains( 'vampire-ritae', $slugs );
	}

	/**
	 * The detection signal is "every section already carries a real `width`" - a template
	 * that's already on the new shape (whether that's because it was already repaired, or
	 * because someone re-saved it through the structured editor after this field existed)
	 * must not be silently overwritten, custom title and all.
	 */
	public function test_a_template_already_on_the_new_shape_is_left_untouched(): void {
		$layout = [
			'version'  => 1,
			'columns'  => 6,
			'sections' => [
				[ 'block_slug' => 'vampire-identity', 'column' => 1, 'order' => 1, 'title' => 'Custom Identity', 'display' => null, 'collapsed' => false, 'width' => 'full' ],
			],
		];

		$id = Template::create( [
			'stack_slug'    => 'vampire',
			'template_type' => 'sheet_full',
			'name'          => 'Vampire Sheet',
			'layout'        => $layout,
			'is_system'     => 1,
		] );

		Schema::repair_stale_default_layouts();

		$titles = array_column( Template::find( $id )->layout['sections'], 'title', 'block_slug' );
		$this->assertSame( 'Custom Identity', $titles['vampire-identity'] );
	}
}
