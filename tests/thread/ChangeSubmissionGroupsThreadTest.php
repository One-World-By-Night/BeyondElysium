<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Change_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The chronicle's removal/lowering switch: what it catches, the shared submission id a whole editor set is submitted
 * under, how it forces every change in a caught set to wait, and how approving a set together orders refunds before
 * charges in one transaction.
 */
class ChangeSubmissionGroupsThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-removal-switch';
	private int $character_id;
	private int $st_id;
	private int $player_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->game_slug, 'name' => 'Removal Switch Chronicle' ] );
		Game::update( $this->game_slug, [ 'settings' => [ 'auto_approve' => true ] ] );

		$this->player_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->character_id = Character::create( [
			'name' => 'Swap Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player_id,
			'sheet_data' => [ 'vampire-abilities' => [ [ 'name' => 'Brawl', 'count' => 3 ] ] ],
		] );
		Character::update_xp( $this->character_id, 50, 50 );

		$this->st_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function turn_switch_on(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'auto_approve' => true, 'approval_on_removal' => true ] ] );
	}

	private function submit_one( string $change_type, array $change_data ) {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes" );
		$request->set_param( 'change_type', $change_type );
		$request->set_param( 'category', 'vampire-abilities' );
		$request->set_param( 'change_data', $change_data );
		return $this->dispatch( $request );
	}

	private function submit_set( array $changes ) {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes/submit" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'changes' => array_map( static fn( $c ) => $c + [ 'category' => 'vampire-abilities' ], $changes ) ] ) );
		return $this->dispatch( $request );
	}

	private function character() {
		return Character::find( $this->character_id );
	}

	// --- catches_removal_rule() classification, direct ---

	public function test_remove_trait_is_always_caught(): void {
		$this->assertTrue( Change_Engine::catches_removal_rule( $this->character(), [
			'change_type' => 'remove_trait',
			'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Brawl' ] ],
		] ) );
	}

	public function test_a_lower_count_is_caught(): void {
		$this->assertTrue( Change_Engine::catches_removal_rule( $this->character(), [
			'change_type' => 'modify_trait',
			'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Brawl', 'count' => 1 ] ],
		] ) );
	}

	public function test_a_higher_count_is_not_caught(): void {
		$this->assertFalse( Change_Engine::catches_removal_rule( $this->character(), [
			'change_type' => 'modify_trait',
			'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Brawl', 'count' => 5 ] ],
		] ) );
	}

	public function test_a_rename_is_caught(): void {
		$this->assertTrue( Change_Engine::catches_removal_rule( $this->character(), [
			'change_type' => 'modify_trait',
			'change_data' => [
				'block_slug' => 'vampire-abilities',
				'trait'      => [ 'name' => 'Renamed Brawl', 'count' => 3 ],
				'previous'   => [ 'name' => 'Brawl' ],
			],
		] ) );
	}

	public function test_a_genuine_addition_is_not_caught(): void {
		$this->assertFalse( Change_Engine::catches_removal_rule( $this->character(), [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Athletics', 'count' => 3 ] ],
		] ) );
	}

	public function test_a_crafted_addition_that_actually_lowers_a_held_count_is_caught(): void {
		$this->assertTrue( Change_Engine::catches_removal_rule( $this->character(), [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Brawl', 'count' => 1 ] ],
		] ) );
	}

	public function test_a_lowered_permanent_pool_value_is_caught(): void {
		Character::update_sheet_data( $this->character_id, [ 'vampire-resources' => [ 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ] ] ] );
		$this->assertTrue( Change_Engine::catches_removal_rule( $this->character(), [
			'change_type' => 'modify_resource',
			'change_data' => [ 'block_slug' => 'vampire-resources', 'values' => [ 'Willpower' => [ 'permanent' => 3 ] ] ],
		] ) );
	}

	public function test_a_raised_permanent_pool_value_is_not_caught(): void {
		Character::update_sheet_data( $this->character_id, [ 'vampire-resources' => [ 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ] ] ] );
		$this->assertFalse( Change_Engine::catches_removal_rule( $this->character(), [
			'change_type' => 'modify_resource',
			'change_data' => [ 'block_slug' => 'vampire-resources', 'values' => [ 'Willpower' => [ 'permanent' => 7 ] ] ],
		] ) );
	}

	// --- the switch, end to end ---

	public function test_with_the_switch_off_a_swap_both_auto_approve(): void {
		$response = $this->submit_set( [
			[ 'change_type' => 'remove_trait', 'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Brawl' ] ] ],
			[ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Athletics', 'count' => 3 ] ] ],
		] );

		$this->assertSame( 201, $response->get_status() );
		foreach ( $response->get_data()['changes'] as $change ) {
			$this->assertSame( 'approved', $change->status, 'the switch is off - today\'s behaviour is unchanged' );
		}
	}

	public function test_with_the_switch_on_a_lone_removal_waits_with_the_reason(): void {
		$this->turn_switch_on();
		$response = $this->submit_one( 'remove_trait', [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Brawl' ] ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()->status );
		$this->assertSame( 'Part of a change that removes, lowers or renames something.', $response->get_data()->reason );
	}

	public function test_with_the_switch_on_the_swaps_addition_waits_too_under_one_submission_id(): void {
		$this->turn_switch_on();
		$response = $this->submit_set( [
			[ 'change_type' => 'remove_trait', 'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Brawl' ] ] ],
			[ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Athletics', 'count' => 3 ] ] ],
		] );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertNotEmpty( $data['submission_id'] );
		$this->assertCount( 2, $data['changes'] );
		foreach ( $data['changes'] as $change ) {
			$this->assertSame( 'pending', $change->status );
			$this->assertSame( 'Part of a change that removes, lowers or renames something.', $change->reason );
			$this->assertSame( $data['submission_id'], $change->submission_id );
		}
	}

	public function test_with_the_switch_on_a_set_of_additions_only_still_auto_approves(): void {
		$this->turn_switch_on();
		$response = $this->submit_set( [
			[ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Athletics', 'count' => 2 ] ] ],
			[ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Stealth', 'count' => 1 ] ] ],
		] );

		$this->assertSame( 201, $response->get_status() );
		foreach ( $response->get_data()['changes'] as $change ) {
			$this->assertSame( 'approved', $change->status );
		}
	}

	public function test_with_the_switch_off_a_lone_removal_through_the_single_change_route_still_follows_its_own_rules(): void {
		// No switch, no other rule on this block: ordinary behaviour.
		$response = $this->submit_one( 'remove_trait', [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Brawl' ] ] );
		$this->assertSame( 'approved', $response->get_data()->status );
	}

	// --- approving a set together: refunds before charges, all or nothing ---

	public function test_approve_all_applies_the_refund_before_the_charge_in_one_transaction(): void {
		$this->turn_switch_on();
		$submitted = $this->submit_set( [
			[ 'change_type' => 'remove_trait', 'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Brawl' ] ] ],
			[ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Athletics', 'count' => 3 ] ] ],
		] )->get_data();
		$ids = array_map( static fn( $c ) => (int) $c->id, $submitted['changes'] );

		$xp_before = (int) $this->character()->xp_unspent;

		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/changes/batch-approve" );
		$request->set_param( 'change_ids', $ids );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $ids, $response->get_data()['approved'] );

		$sheet = $this->character()->sheet_data;
		$names = array_column( $sheet['vampire-abilities'] ?? [], 'name' );
		$this->assertNotContains( 'Brawl', $names );
		$this->assertContains( 'Athletics', $names );

		// Removing Brawl never refunds - only Athletics x3 actually deducts.
		$this->assertSame( $xp_before - 3, (int) $this->character()->xp_unspent );
	}

	public function test_refuse_all_leaves_the_sheet_and_xp_alone(): void {
		$this->turn_switch_on();
		$submitted = $this->submit_set( [
			[ 'change_type' => 'remove_trait', 'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Brawl' ] ] ],
			[ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Athletics', 'count' => 3 ] ] ],
		] )->get_data();

		$sheet_before = $this->character()->sheet_data;
		$xp_before    = (int) $this->character()->xp_unspent;

		wp_set_current_user( $this->st_id );
		foreach ( $submitted['changes'] as $change ) {
			$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change->id}" );
			$request->set_param( 'status', 'rejected' );
			$this->assertSame( 200, $this->dispatch( $request )->get_status() );
		}

		$this->assertSame( $sheet_before, $this->character()->sheet_data );
		$this->assertSame( $xp_before, (int) $this->character()->xp_unspent );
	}

	public function test_a_failure_inside_approve_all_writes_nothing(): void {
		$this->turn_switch_on();
		$submitted = $this->submit_set( [
			[ 'change_type' => 'remove_trait', 'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Brawl' ] ] ],
			[ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Athletics', 'count' => 3 ] ] ],
		] )->get_data();
		$ids = array_map( static fn( $c ) => $c->id, $submitted['changes'] );

		// One of the two is already reviewed by the time the group tries to approve it - the whole group must fail.
		$pre_approved = $submitted['changes'][0];
		wp_set_current_user( $this->st_id );
		$ok = Change_Engine::reject( (int) $pre_approved->id, $this->st_id, null );
		$this->assertTrue( $ok );

		$sheet_before = $this->character()->sheet_data;
		$result       = Change_Engine::approve_group( $ids, $this->st_id );

		$this->assertFalse( $result );
		$this->assertSame( $sheet_before, $this->character()->sheet_data, 'nothing from the still-pending sibling was applied either' );
		$this->assertSame( 'rejected', Change::find( (int) $pre_approved->id )->status );
		$still_pending_id = (int) $submitted['changes'][1]->id;
		$this->assertSame( 'pending', Change::find( $still_pending_id )->status );
	}

	public function test_a_failure_inside_submit_set_writes_no_change(): void {
		$xp_before = (int) $this->character()->xp_unspent;
		$response  = $this->submit_set( [
			[ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Athletics', 'count' => 2 ] ] ],
			[ 'change_type' => 'add_trait', 'change_data' => [] ],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $xp_before, (int) $this->character()->xp_unspent );
		$this->assertCount( 0, Change::for_character( $this->character_id, [] ) );
	}
}
