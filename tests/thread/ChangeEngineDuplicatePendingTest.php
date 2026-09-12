<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Change_Engine;
use WP_UnitTestCase;

/**
 * BE_PROCESS/0.99.2-workflow.md, "Resubmitting creates duplicate pending changes": a player
 * who edits the same trait twice before a Storyteller reviews it used to leave two
 * identical pending rows in the queue. submit() now overwrites the one existing pending row
 * targeting the same block/trait-or-field instead of inserting a second.
 *
 * No manual tearDown() - WP_UnitTestCase's own ambient transaction rolls back every write.
 */
class ChangeEngineDuplicatePendingTest extends WP_UnitTestCase {

	private string $trait_list_slug = 'thread-test-dup-trait-list';

	public function setUp(): void {
		parent::setUp();

		Schema_Block::create( [
			'slug'         => $this->trait_list_slug,
			'name'         => 'Thread Test Dup Trait List',
			'section_type' => 'trait_list',
			'definition'   => [
				'items' => [
					[ 'name' => 'Occult' ],
					[ 'name' => 'Melee' ],
				],
			],
			'is_system'    => 1,
		] );
	}

	private function make_character(): object {
		$game_slug = 'thread-test-dup-pending-game';
		if ( ! Game::find_by_slug( $game_slug ) ) {
			Game::create( [ 'name' => 'Dup Pending Test Game', 'slug' => $game_slug ] );
		}
		$id = Character::create( [
			'name' => 'Dup Pending Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $game_slug,
		] );
		return Character::find( $id );
	}

	private function submit_occult( object $character, int $count ): int {
		return Change_Engine::submit(
			(int) $character->id,
			[
				'change_type' => 'add_trait',
				'category'    => 'trait',
				'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Occult', 'count' => $count ] ],
			],
			get_current_user_id()
		);
	}

	public function test_resubmitting_the_same_trait_updates_the_one_pending_row_instead_of_adding_a_second(): void {
		$character = $this->make_character();

		$first_id  = $this->submit_occult( $character, 1 );
		$second_id = $this->submit_occult( $character, 2 );

		$this->assertSame( $first_id, $second_id, 'the second submit() call must return the SAME change id' );

		$pending = Change::for_character( (int) $character->id, [ 'status' => 'pending' ] );
		$this->assertCount( 1, $pending, 'only one pending row may exist for this trait' );
		$this->assertSame( 2, $pending[0]->change_data['trait']['count'], 'the row must reflect the most recent submission' );
	}

	public function test_a_different_trait_on_the_same_block_gets_its_own_pending_row(): void {
		$character = $this->make_character();

		$this->submit_occult( $character, 1 );
		Change_Engine::submit(
			(int) $character->id,
			[
				'change_type' => 'add_trait',
				'category'    => 'trait',
				'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Melee', 'count' => 1 ] ],
			],
			get_current_user_id()
		);

		$pending = Change::for_character( (int) $character->id, [ 'status' => 'pending' ] );
		$this->assertCount( 2, $pending, 'two genuinely different traits must never collapse into one row' );
	}

	public function test_resubmitting_after_the_first_was_already_approved_creates_a_new_row(): void {
		$character = $this->make_character();

		$first_id = $this->submit_occult( $character, 1 );
		Change_Engine::approve( $first_id, get_current_user_id(), null );

		$second_id = $this->submit_occult( $character, 2 );

		$this->assertNotSame( $first_id, $second_id, 'an already-approved change is a done deal, not a pending duplicate' );
		$this->assertSame( 'approved', Change::find( $first_id )->status );
		$this->assertSame( 'pending', Change::find( $second_id )->status );
	}

	/**
	 * xp_earn/xp_adjust are deliberately excluded from this guard - an ST awarding XP twice
	 * (e.g. two separate downtime events) is a real, intended scenario, not an accidental
	 * resubmission, and pending_duplicate_key() returns null for these change_types.
	 */
	public function test_xp_adjustments_are_never_treated_as_duplicates_of_each_other(): void {
		$character = $this->make_character();

		$first = Change_Engine::submit(
			(int) $character->id,
			[ 'change_type' => 'xp_adjust', 'category' => 'xp', 'change_data' => [ 'delta' => 3 ] ],
			get_current_user_id()
		);
		$second = Change_Engine::submit(
			(int) $character->id,
			[ 'change_type' => 'xp_adjust', 'category' => 'xp', 'change_data' => [ 'delta' => 3 ] ],
			get_current_user_id()
		);

		$this->assertNotSame( $first, $second );
		$this->assertCount( 2, Change::for_character( (int) $character->id, [ 'status' => 'pending' ] ) );
	}
}
