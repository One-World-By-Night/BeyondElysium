<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Cost_Engine;
use WP_UnitTestCase;

/**
 * workflow-0.9.md Step 0.5e - a chronicle's own customized schema block is a real
 * `schema_blocks` row scoped by `game_slug`, not a separate overlay table. Covers the new
 * model methods directly; the REST/service call sites that consume them
 * (Cost_Engine/Change_Engine/Import_Controller/Creature_Stack) are covered by their own
 * existing test files, extended in this same pass.
 */
class SchemaBlockGameScopingTest extends WP_UnitTestCase {

	private string $slug = 'thread-test-game-scoped-block';

	public function setUp(): void {
		parent::setUp();

		Schema_Block::create( [
			'slug'         => $this->slug,
			'name'         => 'Thread Test Block',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [ 'name' => 'Global Item' ] ], 'allow_custom' => true ],
			'is_system'    => 1,
		] );
	}

	public function test_find_by_slug_is_unaffected_by_a_forks_existence(): void {
		Schema_Block::find_or_create_fork_for_game( $this->slug, 'game-a' );

		$global = Schema_Block::find_by_slug( $this->slug );
		$this->assertSame( 'Global Item', $global->definition->items[0]->name );
	}

	public function test_find_for_game_prefers_the_fork_but_falls_back_to_global(): void {
		Schema_Block::find_or_create_fork_for_game( $this->slug, 'game-a' );
		Schema_Block::update( $this->slug, [
			'definition' => [ 'items' => [ [ 'name' => 'Game A Item' ] ], 'allow_custom' => true ],
		], 'game-a' );

		$for_a = Schema_Block::find_for_game( $this->slug, 'game-a' );
		$this->assertSame( 'Game A Item', $for_a->definition->items[0]->name );

		// A different, never-forked game falls back to the global row untouched.
		$for_b = Schema_Block::find_for_game( $this->slug, 'game-b' );
		$this->assertSame( 'Global Item', $for_b->definition->items[0]->name );
	}

	public function test_find_or_create_fork_for_game_is_idempotent(): void {
		$first  = Schema_Block::find_or_create_fork_for_game( $this->slug, 'game-a' );
		$second = Schema_Block::find_or_create_fork_for_game( $this->slug, 'game-a' );

		$this->assertSame( $first->id, $second->id, 'a second call must reuse the existing fork, not create a duplicate' );
	}

	public function test_a_new_fork_starts_as_a_copy_of_the_current_global_definition(): void {
		$fork = Schema_Block::find_or_create_fork_for_game( $this->slug, 'game-a' );

		$this->assertSame( 'Global Item', $fork->definition->items[0]->name );
		$this->assertSame( 0, (int) $fork->is_system, 'a fork is never is_system - it must survive the reseed wipe (Decisions 078/080)' );
	}

	public function test_forking_never_mutates_the_global_row(): void {
		Schema_Block::find_or_create_fork_for_game( $this->slug, 'game-a' );
		Schema_Block::update( $this->slug, [
			'definition' => [ 'items' => [ [ 'name' => 'Game A Item' ] ], 'allow_custom' => true ],
		], 'game-a' );

		$global = Schema_Block::find_by_slug( $this->slug );
		$this->assertSame( 'Global Item', $global->definition->items[0]->name );
	}

	public function test_update_with_no_game_slug_never_touches_a_forks_row(): void {
		Schema_Block::find_or_create_fork_for_game( $this->slug, 'game-a' );

		Schema_Block::update( $this->slug, [ 'name' => 'Renamed Globally' ] );

		$this->assertSame( 'Renamed Globally', Schema_Block::find_by_slug( $this->slug )->name );
		$this->assertSame( 'Thread Test Block', Schema_Block::find_for_game( $this->slug, 'game-a' )->name, 'game-a\'s own fork name is untouched by a global-only update' );
	}

	public function test_all_for_game_substitutes_the_fork_without_changing_the_row_count(): void {
		$before_count = count( Schema_Block::all( [ 'search' => 'Thread Test Block' ] ) );

		Schema_Block::find_or_create_fork_for_game( $this->slug, 'game-a' );
		Schema_Block::update( $this->slug, [
			'definition' => [ 'items' => [ [ 'name' => 'Game A Item' ] ], 'allow_custom' => true ],
		], 'game-a' );

		$for_game_a = Schema_Block::all_for_game( [ 'search' => 'Thread Test Block' ], 'game-a' );
		$this->assertCount( $before_count, $for_game_a, 'a fork replaces a row in the listing, it never adds one' );

		$found = null;
		foreach ( $for_game_a as $block ) {
			if ( $block->slug === $this->slug ) {
				$found = $block;
			}
		}
		$this->assertNotNull( $found );
		$this->assertSame( 'Game A Item', $found->definition->items[0]->name );

		// The base listing (no game context) is unaffected.
		$base = Schema_Block::all( [ 'search' => 'Thread Test Block' ] );
		foreach ( $base as $block ) {
			if ( $block->slug === $this->slug ) {
				$this->assertSame( 'Global Item', $block->definition->items[0]->name );
			}
		}
	}

	public function test_deleting_with_a_game_slug_removes_only_that_forks_row(): void {
		Schema_Block::find_or_create_fork_for_game( $this->slug, 'game-a' );

		$this->assertTrue( Schema_Block::delete( $this->slug, 'game-a' ) );
		$this->assertNotNull( Schema_Block::find_by_slug( $this->slug ), 'the global row must survive deleting only a fork' );

		// Falls back to global again now that the fork is gone.
		$this->assertSame( 'Global Item', Schema_Block::find_for_game( $this->slug, 'game-a' )->definition->items[0]->name );
	}

	/**
	 * The two real consumers this whole mechanism exists for - Cost_Engine and
	 * Change_Engine both resolve a block via `$character->owner_slug`, never a param
	 * threaded in separately, so a trait that only exists in a chronicle's own fork must
	 * price and approve correctly through a real character in that chronicle, not just
	 * through the model methods directly (already covered above).
	 */
	public function test_cost_engine_prices_a_chronicle_only_trait_via_the_forked_block(): void {
		Schema_Block::find_or_create_fork_for_game( $this->slug, 'game-a' );
		Schema_Block::update( $this->slug, [
			'definition' => [
				'items'        => [ [ 'name' => 'Chronicle-Only Item', 'cost' => '4' ] ],
				'allow_custom' => true,
			],
		], 'game-a' );

		$character_id = Character::create( [
			'name' => 'Fork Pricing Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => 'game-a',
		] );
		$character = Character::find( $character_id );

		$cost = Cost_Engine::cost_for_change( $character, [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->slug, 'trait' => [ 'name' => 'Chronicle-Only Item', 'count' => 1 ] ],
		] );

		$this->assertSame( 4, $cost, 'a trait that exists only in the chronicle fork must price from the fork, not fall back to 0 as an unknown block' );

		// A character in a DIFFERENT, never-forked game sees the global catalog instead -
		// this item genuinely does not exist there, so it must price as 0, not inherit game-a's fork.
		$other_character_id = Character::create( [
			'name' => 'No Fork Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => 'game-b',
		] );
		$other_character = Character::find( $other_character_id );
		$other_cost = Cost_Engine::cost_for_change( $other_character, [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->slug, 'trait' => [ 'name' => 'Chronicle-Only Item', 'count' => 1 ] ],
		] );
		$this->assertSame( 0, $other_cost, "a different game's character must never see game-a's own fork" );
	}

	public function test_change_engine_resolves_approval_against_the_forked_block(): void {
		Schema_Block::find_or_create_fork_for_game( $this->slug, 'game-a' );
		Schema_Block::update( $this->slug, [
			'definition' => [
				'items'        => [ [ 'name' => 'Chronicle-Only Item', 'approval' => 'coordinator' ] ],
				'allow_custom' => true,
			],
		], 'game-a' );

		$character_id = Character::create( [
			'name' => 'Fork Approval Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => 'game-a',
		] );
		$character = Character::find( $character_id );

		$resolved = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->slug, 'trait' => [ 'name' => 'Chronicle-Only Item' ] ],
		] );

		$this->assertSame( 'coordinator', $resolved['level'], 'a per-item approval override set only on the fork must be honored, not the global row (which has no such item)' );
	}
}
