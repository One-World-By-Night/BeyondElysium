<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Covers the dedicated approval-rules management engine: create, edit, and
 * delete a rule on a trait_list item and on a tiered_power power/level,
 * chronicle-scoped permission gating (administrator bypasses membership,
 * an hst member is allowed, a narrator member and a plain subscriber are
 * both denied), and that writing a rule forks the block for the chronicle
 * rather than mutating the shared global catalog.
 */
class ApprovalRulesControllerTest extends WP_UnitTestCase {

	private $game_id;
	private $admin_id;
	private $hst_id;
	private $narrator_id;
	private $subscriber_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => 'approval-rules-test',
			'name'       => 'Approval Rules Test',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->hst_id        = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->narrator_id   = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		Game_Member::set_role( $this->game_id, $this->hst_id, 'hst' );
		Game_Member::set_role( $this->game_id, $this->narrator_id, 'narrator' );

		Schema_Block::create( [
			'slug'         => 'ar-test-merits',
			'name'         => 'AR Test Merits',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [
				[ 'name' => 'True Faith', 'cost' => '7' ],
				[ 'name' => 'Common Sense', 'cost' => '1' ],
			] ],
			'is_system'    => 0,
		] );

		Schema_Block::create( [
			'slug'         => 'ar-test-disciplines',
			'name'         => 'AR Test Disciplines',
			'section_type' => 'tiered_power',
			'definition'   => [ 'powers' => [
				[ 'name' => 'Thaumaturgy', 'levels' => [
					[ 'level' => 1, 'tier' => 'basic', 'power_name' => 'A Minor Purification of the Body' ],
					[ 'level' => 5, 'tier' => 'advanced', 'power_name' => 'Blood of Potency' ],
				] ],
			] ],
			'is_system'    => 0,
		] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function create_rule( array $body ) {
		$request = new WP_REST_Request( 'POST', '/be/v1/approval-rules-test/approval-rules' );
		$request->set_url_params( [ 'game_slug' => 'approval-rules-test' ] );
		$request->set_body_params( $body );
		return $this->dispatch( $request );
	}

	// --- permission gating ---

	public function test_an_administrator_needs_no_membership_row(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->create_rule( [
			'block_slug' => 'ar-test-merits', 'target_type' => 'item', 'target_name' => 'True Faith',
			'approval' => 'st', 'reason' => 'Hunter Coordinator Notify',
		] );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_an_hst_member_is_allowed(): void {
		wp_set_current_user( $this->hst_id );
		$response = $this->create_rule( [
			'block_slug' => 'ar-test-merits', 'target_type' => 'item', 'target_name' => 'True Faith',
			'approval' => 'st', 'reason' => 'Hunter Coordinator Notify',
		] );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_a_narrator_member_is_denied(): void {
		wp_set_current_user( $this->narrator_id );
		$response = $this->create_rule( [
			'block_slug' => 'ar-test-merits', 'target_type' => 'item', 'target_name' => 'True Faith', 'approval' => 'st',
		] );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_subscriber_is_denied(): void {
		wp_set_current_user( $this->subscriber_id );
		$response = $this->create_rule( [
			'block_slug' => 'ar-test-merits', 'target_type' => 'item', 'target_name' => 'True Faith', 'approval' => 'st',
		] );
		$this->assertSame( 403, $response->get_status() );
	}

	// --- item target (trait_list) ---

	public function test_creating_an_item_rule_forks_the_block_and_leaves_the_global_untouched(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->create_rule( [
			'block_slug' => 'ar-test-merits', 'target_type' => 'item', 'target_name' => 'True Faith',
			'approval' => 'st', 'reason' => 'Hunter Coordinator Notify',
		] );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'st', $data['approval'] );
		$this->assertSame( 'Hunter Coordinator Notify', $data['reason'] );

		$global = Schema_Block::find_by_slug( 'ar-test-merits' );
		$global_item = current( array_filter( $global->definition->items, fn( $i ) => $i->name === 'True Faith' ) );
		$this->assertEmpty( $global_item->approval ?? null, 'the shared global block must never be mutated by a game-scoped write' );

		$fork = Schema_Block::find_for_game( 'ar-test-merits', 'approval-rules-test' );
		$this->assertSame( 'approval-rules-test', $fork->game_slug );
	}

	public function test_listing_returns_only_entries_that_carry_a_rule(): void {
		wp_set_current_user( $this->admin_id );
		$this->create_rule( [
			'block_slug' => 'ar-test-merits', 'target_type' => 'item', 'target_name' => 'True Faith', 'approval' => 'st',
		] );

		$request = new WP_REST_Request( 'GET', '/be/v1/approval-rules-test/approval-rules' );
		$request->set_url_params( [ 'game_slug' => 'approval-rules-test' ] );
		$rules = $this->dispatch( $request )->get_data();

		$names = array_column( $rules, 'target_name' );
		$this->assertContains( 'True Faith', $names );
		$this->assertNotContains( 'Common Sense', $names, 'an item with no approval or reason is not a rule' );
	}

	public function test_updating_an_item_rule_by_its_id(): void {
		wp_set_current_user( $this->admin_id );
		$created = $this->create_rule( [
			'block_slug' => 'ar-test-merits', 'target_type' => 'item', 'target_name' => 'True Faith', 'approval' => 'st',
		] )->get_data();

		$request = new WP_REST_Request( 'PUT', '/be/v1/approval-rules-test/approval-rules/' . $created['id'] );
		$request->set_url_params( [ 'game_slug' => 'approval-rules-test', 'id' => $created['id'] ] );
		$request->set_body_params( [ 'reason' => 'Updated reason text' ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Updated reason text', $response->get_data()['reason'] );
		$this->assertSame( 'st', $response->get_data()['approval'], 'a field omitted from the update request is left as it was' );
	}

	public function test_deleting_an_item_rule_clears_it_but_keeps_the_item(): void {
		wp_set_current_user( $this->admin_id );
		$created = $this->create_rule( [
			'block_slug' => 'ar-test-merits', 'target_type' => 'item', 'target_name' => 'True Faith', 'approval' => 'st',
		] )->get_data();

		$request = new WP_REST_Request( 'DELETE', '/be/v1/approval-rules-test/approval-rules/' . $created['id'] );
		$request->set_url_params( [ 'game_slug' => 'approval-rules-test', 'id' => $created['id'] ] );
		$this->assertSame( 204, $this->dispatch( $request )->get_status() );

		$fork = Schema_Block::find_for_game( 'ar-test-merits', 'approval-rules-test' );
		$item = current( array_filter( $fork->definition->items, fn( $i ) => $i->name === 'True Faith' ) );
		$this->assertNotFalse( $item, 'the catalog item itself must survive a rule deletion' );
		$this->assertEmpty( $item->approval ?? null );
		$this->assertEmpty( $item->reason ?? null );
	}

	// --- power / level targets (tiered_power) ---

	public function test_a_whole_power_approval_override(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->create_rule( [
			'block_slug' => 'ar-test-disciplines', 'target_type' => 'power', 'target_name' => 'Thaumaturgy', 'approval' => 'coordinator',
		] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'coordinator', $response->get_data()['approval'] );

		$fork  = Schema_Block::find_for_game( 'ar-test-disciplines', 'approval-rules-test' );
		$power = current( array_filter( $fork->definition->powers, fn( $p ) => $p->name === 'Thaumaturgy' ) );
		$this->assertSame( 'coordinator', $power->approval_override );
	}

	public function test_a_single_level_reason(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->create_rule( [
			'block_slug' => 'ar-test-disciplines', 'target_type' => 'level', 'target_name' => 'Thaumaturgy',
			'level' => 5, 'reason' => 'Tremere Coordinator Approval',
		] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 5, $response->get_data()['level'] );
		$this->assertSame( 'Tremere Coordinator Approval', $response->get_data()['reason'] );

		$fork  = Schema_Block::find_for_game( 'ar-test-disciplines', 'approval-rules-test' );
		$power = current( array_filter( $fork->definition->powers, fn( $p ) => $p->name === 'Thaumaturgy' ) );
		$level1 = current( array_filter( $power->levels, fn( $l ) => $l->level === 1 ) );
		$level5 = current( array_filter( $power->levels, fn( $l ) => $l->level === 5 ) );
		$this->assertEmpty( $level1->reason ?? null, 'a rule on one level must not leak onto another level of the same power' );
		$this->assertSame( 'Tremere Coordinator Approval', $level5->reason );
	}

	// --- validation ---

	public function test_an_unknown_target_type_is_rejected(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->create_rule( [
			'block_slug' => 'ar-test-merits', 'target_type' => 'bogus', 'target_name' => 'True Faith',
		] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_level_target_without_a_level_number_is_rejected(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->create_rule( [
			'block_slug' => 'ar-test-disciplines', 'target_type' => 'level', 'target_name' => 'Thaumaturgy', 'reason' => 'x',
		] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_an_item_target_against_a_tiered_power_block_is_rejected(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->create_rule( [
			'block_slug' => 'ar-test-disciplines', 'target_type' => 'item', 'target_name' => 'Thaumaturgy', 'approval' => 'st',
		] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_an_invalid_approval_value_is_rejected(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->create_rule( [
			'block_slug' => 'ar-test-merits', 'target_type' => 'item', 'target_name' => 'True Faith',
			'approval' => 'not-a-real-level',
		] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_reason_is_sanitized_before_storage(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->create_rule( [
			'block_slug' => 'ar-test-merits', 'target_type' => 'item', 'target_name' => 'True Faith',
			'reason' => '<script>alert(1)</script>Hunter Coordinator Notify',
		] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertStringNotContainsString( '<script>', $response->get_data()['reason'] );
		$this->assertStringContainsString( 'Hunter Coordinator Notify', $response->get_data()['reason'] );
	}

	public function test_a_target_name_that_does_not_exist_is_a_404(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->create_rule( [
			'block_slug' => 'ar-test-merits', 'target_type' => 'item', 'target_name' => 'Nonexistent Merit', 'approval' => 'st',
		] );
		$this->assertSame( 404, $response->get_status() );
	}

	// --- options ---

	public function test_options_exposes_the_real_approval_levels_and_reason_presets(): void {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'GET', '/be/v1/approval-rules-test/approval-rules/options' );
		$request->set_url_params( [ 'game_slug' => 'approval-rules-test' ] );
		$data = $this->dispatch( $request )->get_data();

		$this->assertSame( [ 'auto', 'st', 'coordinator' ], $data['approval_levels'] );
		$this->assertContains( 'Coordinator Approval', $data['reason_presets'] );
		$this->assertNotContains( 'Unregulated', $data['reason_presets'], 'unregulated names no rule worth creating' );
	}
}
