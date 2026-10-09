<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Bylaws;
use BeyondElysium\Services\Change_Engine;
use WP_UnitTestCase;

/**
 * `Change_Engine::resolve_rule_level()`'s OWBN Character Bylaws step: on a chronicle with the switch on, every
 * bylaw attached to a purchased entry adds its own reason, on top of whatever the block's own rules already added.
 */
class ChangeEngineBylawsThreadTest extends WP_UnitTestCase {

	public function tearDown(): void {
		Bylaws::reset_cache();
		delete_option( Bylaws::OVERRIDE_OPTION );
		parent::tearDown();
	}

	private function upload_ruleset( array $rules, array $attachments ): void {
		Bylaws::upload( [ 'rules' => $rules, 'attachments' => $attachments ] );
	}

	private function game( bool $bylaws_on, string $slug ): object {
		Game::create( [ 'slug' => $slug, 'name' => 'Bylaws Test Game', 'settings' => [ 'owbn_bylaws' => $bylaws_on ] ] );
		return Game::find_by_slug( $slug );
	}

	private function character( string $game_slug, bool $is_npc = false ): object {
		$id = Character::create( [
			'name' => 'Bylaws Test Character', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $game_slug, 'is_npc' => $is_npc ? 1 : 0,
		] );
		return Character::find( $id );
	}

	public function test_the_switch_off_adds_no_bylaw_reason(): void {
		$this->upload_ruleset(
			[ [ 'clause_id' => 1, 'path' => '10.e.v', 'subject' => 'True Faith', 'pc' => 'Coordinator Notify', 'npc' => 'Unregulated', 'coordinators' => [ 'Hunter' ] ] ],
			[ [ 'clause_id' => 1, 'family' => 'merits', 'name' => 'True Faith' ] ]
		);
		$game      = $this->game( false, 'thread-bylaws-off' );
		$character = $this->character( $game->slug );

		$resolved = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'True Faith' ] ],
		] );

		$this->assertNull( $resolved['reason'] );
	}

	public function test_the_switch_on_a_pc_purchase_waits_with_the_bylaw_reason(): void {
		$this->upload_ruleset(
			[ [ 'clause_id' => 1, 'path' => '10.e.v', 'subject' => 'True Faith', 'pc' => 'Coordinator Notify', 'npc' => 'Unregulated', 'coordinators' => [ 'Hunter' ] ] ],
			[ [ 'clause_id' => 1, 'family' => 'merits', 'name' => 'True Faith' ] ]
		);
		$game      = $this->game( true, 'thread-bylaws-pc' );
		$character = $this->character( $game->slug );

		$resolved = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'True Faith' ] ],
		] );

		$this->assertSame( 'st', $resolved['level'] );
		$this->assertStringContainsString( 'Coordinator Notify', $resolved['reason'] );
		$this->assertStringContainsString( '[OWBN Character Bylaws 10.e.v, clause 1]', $resolved['reason'] );
	}

	public function test_an_unregulated_npc_axis_adds_no_reason_even_with_the_switch_on(): void {
		$this->upload_ruleset(
			[ [ 'clause_id' => 1, 'path' => '10.e.v', 'subject' => 'True Faith', 'pc' => 'Coordinator Notify', 'npc' => 'Unregulated', 'coordinators' => [ 'Hunter' ] ] ],
			[ [ 'clause_id' => 1, 'family' => 'merits', 'name' => 'True Faith' ] ]
		);
		$game      = $this->game( true, 'thread-bylaws-npc' );
		$character = $this->character( $game->slug, true );

		$resolved = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'True Faith' ] ],
		] );

		$this->assertNull( $resolved['reason'] );
	}

	public function test_an_unrelated_entry_gets_no_bylaw_reason(): void {
		$this->upload_ruleset(
			[ [ 'clause_id' => 1, 'path' => '10.e.v', 'subject' => 'True Faith', 'pc' => 'Coordinator Notify', 'npc' => 'Unregulated', 'coordinators' => [ 'Hunter' ] ] ],
			[ [ 'clause_id' => 1, 'family' => 'merits', 'name' => 'True Faith' ] ]
		);
		$game      = $this->game( true, 'thread-bylaws-unrelated' );
		$character = $this->character( $game->slug );

		$resolved = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'Iron Will' ] ],
		] );

		$this->assertNull( $resolved['reason'] );
	}

	public function test_the_chronicles_own_reason_comes_before_the_bylaw_reason(): void {
		\BeyondElysium\Models\Schema_Block::create( [
			'slug' => 'thread-bylaws-block', 'name' => 'Thread Bylaws Block', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [ [ 'name' => 'True Faith', 'reason' => 'Chronicle house rule note.' ] ] ],
		] );
		$this->upload_ruleset(
			[ [ 'clause_id' => 1, 'path' => '10.e.v', 'subject' => 'True Faith', 'pc' => 'Coordinator Notify', 'npc' => 'Unregulated', 'coordinators' => [ 'Hunter' ] ] ],
			[ [ 'clause_id' => 1, 'family' => 'thread-bylaws-block', 'name' => 'True Faith' ] ]
		);
		$game      = $this->game( true, 'thread-bylaws-combo' );
		$character = $this->character( $game->slug );

		$resolved = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'thread-bylaws-block', 'trait' => [ 'name' => 'True Faith' ] ],
		] );

		$lines = explode( "\n", $resolved['reason'] );
		$this->assertSame( 'Chronicle house rule note.', $lines[0] );
		$this->assertStringContainsString( 'Coordinator Notify', $lines[1] );
	}

	public function test_chronicle_wide_auto_approve_never_overrides_a_bylaw_reason(): void {
		$this->upload_ruleset(
			[ [ 'clause_id' => 1, 'path' => '10.e.v', 'subject' => 'True Faith', 'pc' => 'Coordinator Notify', 'npc' => 'Unregulated', 'coordinators' => [ 'Hunter' ] ] ],
			[ [ 'clause_id' => 1, 'family' => 'merits', 'name' => 'True Faith' ] ]
		);
		Game::create( [
			'slug' => 'thread-bylaws-auto', 'name' => 'Bylaws Auto Test Game',
			'settings' => [ 'owbn_bylaws' => true, 'auto_approve' => true ],
		] );
		$game      = Game::find_by_slug( 'thread-bylaws-auto' );
		$character = $this->character( $game->slug );

		$resolved = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'True Faith' ] ],
		] );

		$this->assertSame( 'st', $resolved['level'] );
	}

	public function test_two_rules_attached_to_the_same_entry_both_contribute_a_line(): void {
		$this->upload_ruleset(
			[
				[ 'clause_id' => 1, 'path' => '10.f.ii', 'subject' => 'True Faith', 'pc' => 'Disallowed', 'npc' => 'Unregulated', 'coordinators' => [] ],
				[ 'clause_id' => 2, 'path' => '10.e.v', 'subject' => 'True Faith', 'pc' => 'Coordinator Notify', 'npc' => 'Unregulated', 'coordinators' => [ 'Hunter' ] ],
			],
			[
				[ 'clause_id' => 1, 'family' => 'merits', 'name' => 'True Faith' ],
				[ 'clause_id' => 2, 'family' => 'merits', 'name' => 'True Faith' ],
			]
		);
		$game      = $this->game( true, 'thread-bylaws-two-rules' );
		$character = $this->character( $game->slug );

		$resolved = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'True Faith' ] ],
		] );

		$lines = explode( "\n", $resolved['reason'] );
		$this->assertCount( 2, $lines );
		$this->assertStringContainsString( '10.e.v', $lines[0] );
		$this->assertStringContainsString( '10.f.ii', $lines[1] );
	}

	public function test_a_level_restricted_attachment_only_fires_on_its_own_levels(): void {
		\BeyondElysium\Models\Schema_Block::create( [
			'slug' => 'thread-bylaws-power-block', 'name' => 'Thread Bylaws Power Block', 'section_type' => 'tiered_power',
			'definition' => [
				'powers' => [ [ 'name' => 'Visceratika', 'levels' => [
					[ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Level One' ],
					[ 'level' => 4, 'tier' => 'intermediate', 'power_name' => 'Level Four' ],
				] ] ],
			],
		] );
		$this->upload_ruleset(
			[ [ 'clause_id' => 1, 'path' => '10.e.ix', 'subject' => 'Visceratika 4+', 'pc' => 'Coordinator Approval', 'npc' => 'Unregulated', 'coordinators' => [ 'Tremere' ] ] ],
			[ [ 'clause_id' => 1, 'family' => 'thread-bylaws-power-block', 'name' => 'Visceratika', 'levels' => [ 4 ] ] ]
		);
		$game      = $this->game( true, 'thread-bylaws-levels' );
		$character = $this->character( $game->slug );

		$level_one = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'thread-bylaws-power-block', 'trait' => [ 'name' => 'Visceratika', 'level' => 1 ] ],
		] );
		$this->assertNull( $level_one['reason'] );

		$level_four = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'thread-bylaws-power-block', 'trait' => [ 'name' => 'Visceratika', 'level' => 4 ] ],
		] );
		$this->assertStringContainsString( 'Coordinator Approval', $level_four['reason'] );
	}

	public function test_a_count_range_attachment_only_fires_within_its_own_range(): void {
		$this->upload_ruleset(
			[
				[ 'clause_id' => 1, 'path' => '10.e.v', 'subject' => 'True Faith 1-5', 'pc' => 'Coordinator Notify', 'npc' => 'Unregulated', 'coordinators' => [ 'Hunter' ] ],
				[ 'clause_id' => 2, 'path' => '10.e.vi', 'subject' => 'True Faith 6+', 'pc' => 'Disallowed', 'npc' => 'Disallowed', 'coordinators' => [ 'Hunter' ] ],
			],
			[
				[ 'clause_id' => 1, 'family' => 'merits', 'name' => 'True Faith', 'count_range' => [ 'from' => 1, 'to' => 5 ] ],
				[ 'clause_id' => 2, 'family' => 'merits', 'name' => 'True Faith', 'count_range' => [ 'from' => 6, 'to' => PHP_INT_MAX ] ],
			]
		);
		$game      = $this->game( true, 'thread-bylaws-count-range' );
		$character = $this->character( $game->slug );

		$three = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'True Faith', 'count' => 3 ] ],
		] );
		$this->assertStringContainsString( '10.e.v', $three['reason'] );
		$this->assertStringNotContainsString( '10.e.vi', $three['reason'] );

		$six = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'True Faith', 'count' => 6 ] ],
		] );
		$this->assertStringContainsString( '10.e.vi', $six['reason'] );
		$this->assertStringNotContainsString( '10.e.v,', $six['reason'] );
	}

	public function test_a_6_plus_attachment_reaches_a_named_elder_pick_but_not_a_numbered_level(): void {
		\BeyondElysium\Models\Schema_Block::create( [
			'slug' => 'thread-bylaws-elder-block', 'name' => 'Thread Bylaws Elder Block', 'section_type' => 'tiered_power',
			'definition' => [
				'powers' => [ [ 'name' => 'Visceratika', 'levels' => [
					[ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Level One' ],
					[ 'tier' => 'elder', 'power_name' => 'Flesh of the Corpse' ],
				] ] ],
			],
		] );
		$this->upload_ruleset(
			[ [ 'clause_id' => 1, 'path' => '10.e.ix', 'subject' => 'Visceratika 6+', 'pc' => 'Coordinator Approval', 'npc' => 'Unregulated', 'coordinators' => [ 'Tremere' ] ] ],
			[ [ 'clause_id' => 1, 'family' => 'thread-bylaws-elder-block', 'name' => 'Visceratika', 'picks' => [ 'all' ] ] ]
		);
		$game      = $this->game( true, 'thread-bylaws-elder-pick' );
		$character = $this->character( $game->slug );

		$level_one = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'thread-bylaws-elder-block', 'trait' => [ 'name' => 'Visceratika', 'level' => 1 ] ],
		] );
		$this->assertNull( $level_one['reason'], 'a numbered-level purchase is not an Elder-and-above pick' );

		$elder = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'thread-bylaws-elder-block', 'trait' => [ 'name' => 'Visceratika', 'power_name' => 'Flesh of the Corpse' ] ],
		] );
		$this->assertStringContainsString( 'Coordinator Approval', $elder['reason'] );
	}
}
