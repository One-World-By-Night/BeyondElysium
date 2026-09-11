<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use WP_UnitTestCase;

/**
 * Template::resolve() against a real be_templates table.
 *
 * tests/unit/TemplateResolveTest.php covers the decision logic (resolve_from_rows()) with
 * no database at all. This covers the two SQL fetches resolve() builds on top of that -
 * in particular that a null $game_id can never accidentally match a row whose game_id
 * happens to be 0, which is exactly the kind of thing that passes locally and breaks on a
 * chronicle whose game row id is 0-adjacent in some other query.
 *
 * @see BE_PROCESS/workflow-0.3.md Step 1b, 1j
 */
class TemplateResolveThreadTest extends WP_UnitTestCase {

	private string $stack = 'thread-test-stack';
	private string $type  = 'sheet_full';
	private string $block_slug;

	public function setUp(): void {
		parent::setUp();

		$this->block_slug = 'thread-test-resolve-block';
		Schema_Block::create( [
			'slug'         => $this->block_slug,
			'name'         => 'Thread Test Resolve Block',
			'section_type' => 'identity_field',
			'definition'   => [ 'fields' => [ [ 'name' => 'Test Field' ] ] ],
		] );
	}

	private function layout( int $columns ): array {
		return [
			'version'  => 1,
			'columns'  => $columns,
			'sections' => [
				[ 'block_slug' => $this->block_slug, 'column' => 1, 'order' => 1, 'title' => 'X', 'display' => null, 'collapsed' => false ],
			],
		];
	}

	public function test_resolve_is_null_when_nothing_exists(): void {
		$this->assertNull( Template::resolve( $this->stack, $this->type, 42 ) );
	}

	public function test_global_resolves_for_any_game(): void {
		Template::create( [ 'stack_slug' => $this->stack, 'name' => 'Global', 'template_type' => $this->type, 'layout' => $this->layout( 3 ) ] );

		$resolved = Template::resolve( $this->stack, $this->type, 42 );
		$this->assertNotNull( $resolved );
		$this->assertNull( $resolved->game_id );
	}

	public function test_game_override_wins_over_global_for_its_own_game_only(): void {
		Template::create( [ 'stack_slug' => $this->stack, 'name' => 'Global', 'template_type' => $this->type, 'layout' => $this->layout( 3 ) ] );
		Template::create( [ 'game_id' => 42, 'stack_slug' => $this->stack, 'name' => 'Override', 'template_type' => $this->type, 'layout' => $this->layout( 2 ) ] );

		$for_42 = Template::resolve( $this->stack, $this->type, 42 );
		$this->assertSame( 2, $for_42->layout['columns'], 'game 42 must see its own override' );

		$for_99 = Template::resolve( $this->stack, $this->type, 99 );
		$this->assertSame( 3, $for_99->layout['columns'], 'a different game must see the global, not game 42\'s override' );
	}

	public function test_game_id_zero_row_never_satisfies_a_null_game_id_lookup(): void {
		// Manually insert a row with a literal game_id of 0 - not achievable through
		// Template::create() (which treats an unset game_id as NULL), but a defensive
		// regression guard against a future caller passing 0 instead of null.
		Manager::insert( 'templates', [
			'game_id'       => 0,
			'stack_slug'    => $this->stack,
			'name'          => 'Zero game_id row',
			'template_type' => $this->type,
			'layout'        => wp_json_encode( $this->layout( 1 ) ),
			'created_by'    => 1,
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		] );

		$this->assertNull(
			Template::resolve( $this->stack, $this->type, null ),
			'a game_id of 0 must not be treated as a global (game_id IS NULL) row'
		);
	}

	public function test_deleting_an_override_falls_back_to_global(): void {
		Template::create( [ 'stack_slug' => $this->stack, 'name' => 'Global', 'template_type' => $this->type, 'layout' => $this->layout( 3 ) ] );
		$override_id = Template::create( [ 'game_id' => 42, 'stack_slug' => $this->stack, 'name' => 'Override', 'template_type' => $this->type, 'layout' => $this->layout( 2 ) ] );

		Template::delete( $override_id );

		$resolved = Template::resolve( $this->stack, $this->type, 42 );
		$this->assertSame( 3, $resolved->layout['columns'] );
	}

	public function test_deleting_a_system_global_is_refused(): void {
		$id = Template::create( [ 'stack_slug' => $this->stack, 'name' => 'System Global', 'template_type' => $this->type, 'layout' => $this->layout( 3 ), 'is_system' => 1 ] );

		$this->assertFalse( Template::delete( $id ) );
		$this->assertNotNull( Template::find( $id ) );
	}

	public function test_for_game_marks_overrides(): void {
		Template::create( [ 'stack_slug' => $this->stack, 'name' => 'Global', 'template_type' => $this->type, 'layout' => $this->layout( 3 ) ] );
		Template::create( [ 'game_id' => 42, 'stack_slug' => $this->stack, 'name' => 'Override', 'template_type' => $this->type, 'layout' => $this->layout( 2 ) ] );

		$rows = Template::for_game( 42, [ 'stack_slug' => $this->stack ] );
		$this->assertCount( 1, $rows, 'the override must replace the global, not sit alongside it' );
		$this->assertTrue( $rows[0]->is_override );
	}
}
