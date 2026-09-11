<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Change_Engine;
use WP_UnitTestCase;

/**
 * Covers the reason-carrying half of resolve_approval_level() - a schema block item or
 * tiered_power level can now carry a `reason` string (the real-world approval authority
 * a bylaw import would set), which must always force at least `st`-level review and must
 * be attached to the resulting Change record. Real Storyteller/Coordinator approval
 * routing itself is never automated by this plugin - the reason is surfaced, not enforced.
 */
class ChangeEngineBylawReasonTest extends WP_UnitTestCase {

	private string $trait_list_slug = 'thread-test-bylaw-trait-list';
	private string $tiered_power_slug = 'thread-test-bylaw-tiered-power';

	public function setUp(): void {
		parent::setUp();

		Schema_Block::create( [
			'slug'         => $this->trait_list_slug,
			'name'         => 'Thread Test Bylaw Trait List',
			'section_type' => 'trait_list',
			'definition'   => [
				'items' => [
					[ 'name' => 'Plain Item' ],
					[ 'name' => 'Reason Only Item', 'reason' => 'Requires Tremere Coordinator approval.' ],
					[ 'name' => 'Reason Plus Auto Item', 'approval' => 'auto', 'reason' => 'Requires Ravnos Coordinator approval.' ],
					[ 'name' => 'Reason Plus Coordinator Item', 'approval' => 'coordinator', 'reason' => 'Disallowed per Character Bylaws.' ],
				],
			],
			'is_system'    => 1,
		] );

		Schema_Block::create( [
			'slug'         => $this->tiered_power_slug,
			'name'         => 'Thread Test Bylaw Tiered Power',
			'section_type' => 'tiered_power',
			'definition'   => [
				'powers' => [
					[
						'name'   => 'Visceratika',
						'levels' => [
							[ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Level One' ],
							[ 'level' => 3, 'tier' => 'basic', 'power_name' => 'Level Three', 'reason' => 'Notify the Tremere Coordinator.' ],
							[ 'level' => 4, 'tier' => 'intermediate', 'power_name' => 'Level Four', 'reason' => 'Requires Tremere Coordinator approval.' ],
						],
					],
					[
						'name'              => 'Thanatosis',
						'approval_override' => 'coordinator',
						'levels'            => [
							[ 'level' => 5, 'tier' => 'advanced', 'power_name' => 'Level Five', 'reason' => 'Requires Giovanni Coordinator approval.' ],
						],
					],
				],
			],
			'is_system'    => 1,
		] );
	}

	private function make_character( string $game_slug = 'thread-test-bylaw-game' ): object {
		$id = Character::create( [
			'name'       => 'Bylaw Reason Test Character',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $game_slug,
		] );
		return Character::find( $id );
	}

	public function test_an_item_with_no_reason_resolves_to_the_block_default_with_no_reason(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Plain Item' ] ],
		] );

		$this->assertSame( 'st', $resolved['level'] );
		$this->assertNull( $resolved['reason'] );
	}

	public function test_a_reason_with_no_approval_override_still_forces_st_and_carries_the_reason(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Reason Only Item' ] ],
		] );

		$this->assertSame( 'st', $resolved['level'] );
		$this->assertSame( 'Requires Tremere Coordinator approval.', $resolved['reason'] );
	}

	public function test_a_reason_cannot_be_downgraded_by_an_auto_approval_override(): void {
		// Real scenario: an admin sets approval=auto for other reasons, but a bylaw reason
		// is also present - the reason's implicit st-floor must win, never auto.
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Reason Plus Auto Item' ] ],
		] );

		$this->assertSame( 'st', $resolved['level'], 'a reason must never be silently bypassed by a weaker approval setting' );
		$this->assertSame( 'Requires Ravnos Coordinator approval.', $resolved['reason'] );
	}

	public function test_a_coordinator_approval_alongside_a_reason_stays_coordinator(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Reason Plus Coordinator Item' ] ],
		] );

		$this->assertSame( 'coordinator', $resolved['level'] );
		$this->assertSame( 'Disallowed per Character Bylaws.', $resolved['reason'] );
	}

	public function test_tiered_power_level_reason_is_matched_by_exact_level_only(): void {
		$character = $this->make_character();

		// Level 1 carries no reason at all.
		$unflagged = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->tiered_power_slug, 'trait' => [ 'name' => 'Visceratika', 'level' => 1 ] ],
		] );
		$this->assertSame( 'st', $unflagged['level'] );
		$this->assertNull( $unflagged['reason'], 'level 1 has no reason of its own and must not inherit level 3/4\'s' );

		// Level 3 (notify-style) and level 4 (approval-style) each carry their own distinct reason.
		$level_three = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->tiered_power_slug, 'trait' => [ 'name' => 'Visceratika', 'level' => 3 ] ],
		] );
		$this->assertSame( 'Notify the Tremere Coordinator.', $level_three['reason'] );

		$level_four = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->tiered_power_slug, 'trait' => [ 'name' => 'Visceratika', 'level' => 4 ] ],
		] );
		$this->assertSame( 'Requires Tremere Coordinator approval.', $level_four['reason'] );
		$this->assertNotSame( $level_three['reason'], $level_four['reason'], 'one entry per level - each level\'s own reason, never shared' );
	}

	public function test_tiered_power_whole_power_override_combines_with_a_level_reason(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->tiered_power_slug, 'trait' => [ 'name' => 'Thanatosis', 'level' => 5 ] ],
		] );

		$this->assertSame( 'coordinator', $resolved['level'] );
		$this->assertSame( 'Requires Giovanni Coordinator approval.', $resolved['reason'] );
	}

	public function test_submit_persists_the_resolved_reason_onto_the_change_record(): void {
		$character = $this->make_character();
		$change_id = Change_Engine::submit( (int) $character->id, [
			'change_type' => 'add_trait',
			'category'    => $this->trait_list_slug,
			'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Reason Only Item' ] ],
		], $character->wp_user_id ?: 1 );

		$this->assertNotSame( 0, $change_id );
		$change = Change::find( $change_id );
		$this->assertSame( 'pending', $change->status, 'a reason-bearing change must never land auto-approved' );
		$this->assertSame( 'Requires Tremere Coordinator approval.', $change->reason );
	}

	public function test_game_level_auto_approve_never_overrides_a_reason_bearing_change(): void {
		$game_slug = 'thread-test-bylaw-auto-approve-game';
		Game::create( [
			'slug'     => $game_slug,
			'name'     => 'Bylaw Auto-Approve Test Game',
			'settings' => [ 'auto_approve' => true ],
		] );
		$character = $this->make_character( $game_slug );

		$resolved = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Reason Only Item' ] ],
		] );

		$this->assertSame( 'st', $resolved['level'], 'game-level auto_approve must never wave through a real bylaw citation' );
		$this->assertNotNull( $resolved['reason'] );

		// Confirmed for real: the SAME game's plain (reason-less) item still gets the
		// existing auto-approve convenience, proving this isn't a blanket regression.
		$plain = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Plain Item' ] ],
		] );
		$this->assertSame( 'auto', $plain['level'] );
	}
}
