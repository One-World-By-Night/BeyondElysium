<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Template;
use WP_UnitTestCase;

/**
 * BM-9 (BE_PROCESS/0.99.2-workflow.md): getting `vampire-blood-magic` into an
 * already-seeded install's templates. Two separate, narrowly-scoped functions, following
 * the project's own established "one-off migration per specific gap" pattern rather than
 * generalizing repair_stale_default_layouts()'s width-based staleness check - a real
 * regression found running the full suite: generalizing that check to also flag a missing
 * *section* (not just a missing `width` field) broke
 * VampireTemplateRepairTest::test_a_template_already_on_the_new_shape_is_left_untouched,
 * which establishes that an is_system template genuinely missing sections the current code
 * defines - an admin's own deliberate trim via the structured editor looks identical - must
 * not be treated as stale on that basis alone.
 *
 *   - Schema::add_missing_blood_magic_template_section() - vampire's own sheet_full.
 *   - Schema::repair_stale_npc_layouts() - propagates that same addition into npc_full,
 *     which Seeder::seed_npc_templates() only ever builds once and never revisits.
 *
 * Tested here directly against the real, existing global vampire template rows, matching
 * AwakeningOfTheSteelDedupeTest's own established pattern for this class of test.
 *
 * No manual tearDown() - WP_UnitTestCase's own ambient transaction rolls back every write
 * this file makes, including to the real, shared vampire template rows.
 */
class BloodMagicLayoutRepairTest extends WP_UnitTestCase {

	private function real_template( string $type ): object {
		$rows = Template::globals( [ 'stack_slug' => 'vampire', 'template_type' => $type ] );
		$this->assertNotEmpty( $rows, "a real, seeded vampire {$type} template must already exist" );
		return $rows[0];
	}

	/** Removes one section from a layout in place, leaving everything else untouched. */
	private function without_section( array $layout, string $block_slug ): array {
		$layout['sections'] = array_values( array_filter(
			$layout['sections'],
			static fn( $s ) => $s['block_slug'] !== $block_slug
		) );
		return $layout;
	}

	public function test_add_missing_blood_magic_template_section_adds_it_right_after_disciplines(): void {
		$template = $this->real_template( 'sheet_full' );
		Template::update( (int) $template->id, [ 'layout' => $this->without_section( $template->layout, 'vampire-blood-magic' ) ] );

		Schema::add_missing_blood_magic_template_section();

		$sections = Template::find( (int) $template->id )->layout['sections'];
		$slugs    = array_column( $sections, 'block_slug' );
		$position = array_search( 'vampire-blood-magic', $slugs, true );
		$this->assertNotFalse( $position );
		$this->assertSame( 'vampire-disciplines', $slugs[ $position - 1 ], 'must land immediately after Disciplines' );
	}

	public function test_add_missing_blood_magic_template_section_is_idempotent(): void {
		$template = $this->real_template( 'sheet_full' );
		Schema::add_missing_blood_magic_template_section();
		$before = Template::find( (int) $template->id )->updated_at;

		sleep( 1 ); // MySQL's `updated_at` has one-second resolution.
		Schema::add_missing_blood_magic_template_section();

		$after = Template::find( (int) $template->id )->updated_at;
		$this->assertSame( $before, $after, 'a template that already has the section must not be rewritten' );
	}

	public function test_add_missing_blood_magic_template_section_never_touches_a_chronicles_own_customized_template(): void {
		$id = Template::create( [
			'stack_slug' => 'vampire', 'template_type' => 'sheet_full', 'name' => 'My Chronicle Vampire Sheet',
			'is_system'  => 0,
			'layout'     => [ 'version' => 1, 'columns' => 6, 'sections' => [
				[ 'block_slug' => 'vampire-identity', 'column' => 1, 'order' => 1, 'title' => 'Identity', 'display' => null, 'collapsed' => false, 'width' => 'full' ],
			] ],
		] );

		Schema::add_missing_blood_magic_template_section();

		$this->assertNotContains( 'vampire-blood-magic', array_column( Template::find( $id )->layout['sections'], 'block_slug' ) );
	}

	public function test_repair_stale_npc_layouts_appends_a_missing_section_before_the_notes_section(): void {
		Schema::add_missing_blood_magic_template_section(); // Ensure sheet_full is current first.

		$npc = $this->real_template( 'npc_full' );
		Template::update( (int) $npc->id, [ 'layout' => $this->without_section( $npc->layout, 'vampire-blood-magic' ) ] );
		$this->assertNotContains( 'vampire-blood-magic', array_column( Template::find( (int) $npc->id )->layout['sections'], 'block_slug' ) );

		Schema::repair_stale_npc_layouts();

		$updated = Template::find( (int) $npc->id );
		$slugs   = array_column( $updated->layout['sections'], 'block_slug' );
		$this->assertContains( 'vampire-blood-magic', $slugs );
		$this->assertSame( 'npc-roleplaying-notes', end( $updated->layout['sections'] )['block_slug'], 'notes must stay last' );
	}

	public function test_repair_stale_npc_layouts_is_a_no_op_once_nothing_is_missing(): void {
		Schema::add_missing_blood_magic_template_section();

		$npc = $this->real_template( 'npc_full' );
		Schema::repair_stale_npc_layouts(); // Once, to reach a known "already current" state.
		$before = Template::find( (int) $npc->id )->updated_at;

		sleep( 1 );
		Schema::repair_stale_npc_layouts();

		$after = Template::find( (int) $npc->id )->updated_at;
		$this->assertSame( $before, $after, 'an up-to-date npc_full must not be rewritten' );
	}
}
