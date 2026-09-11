<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Step 3a/3e audit finding: `update_item()` (single approve/reject) had no check that a
 * change was still `pending` before calling `Change_Engine::approve()`/`reject()`.
 * `Change_Engine::approve()` deliberately allows a second call on an already-`approved`
 * record - `Change_Engine::submit()`'s own auto-approve path relies on exactly that to
 * apply an auto-approved change's side effects right after creating it - so that method
 * can't be the thing guarding against a *client* re-approving through this route. Before
 * this fix, calling PUT .../changes/{id} with status=approved twice on the same change
 * would re-apply the sheet mutation and re-deduct XP a second time.
 */
class ChangesControllerApprovalTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-approval-game';
	private int $character_id;
	private int $st_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Approval Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->character_id = Character::create( [
			'name' => 'Approval Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $player,
		] );
		Character::update_xp( $this->character_id, 10, 10 );

		$this->st_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function submit_change(): int {
		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes" );
		$request->set_param( 'change_type', 'add_trait' );
		$request->set_param( 'category', 'met-merits' );
		$request->set_param( 'change_data', [ 'block_slug' => 'met-merits', 'trait' => [ 'name' => 'Iron Will' ] ] );
		return (int) $this->dispatch( $request )->get_data()->id;
	}

	public function test_approving_the_same_change_twice_is_rejected_the_second_time(): void {
		$change_id = $this->submit_change();
		wp_set_current_user( $this->st_id );

		$first = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$first->set_param( 'status', 'approved' );
		$this->assertSame( 200, $this->dispatch( $first )->get_status() );

		$second = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$second->set_param( 'status', 'approved' );
		$response = $this->dispatch( $second );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'change_not_pending', $response->as_error()->get_error_code() );
	}

	public function test_a_second_approval_does_not_re_deduct_xp(): void {
		$change_id = $this->submit_change();
		wp_set_current_user( $this->st_id );

		$approve = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$approve->set_param( 'status', 'approved' );
		$this->dispatch( $approve );

		$after_first = Character::find( $this->character_id )->xp_unspent;
		$this->assertSame( 7, (int) $after_first, 'Iron Will costs 3; 10 - 3 = 7 after the one real approval.' );

		// Rejected by change_not_pending, so this must be a true no-op on the sheet/XP.
		$second = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$second->set_param( 'status', 'approved' );
		$this->dispatch( $second );

		$after_second = Character::find( $this->character_id )->xp_unspent;
		$this->assertSame( 7, (int) $after_second, 'A rejected re-approval attempt must never touch xp_unspent again.' );
	}

	public function test_rejecting_an_already_rejected_change_is_also_refused(): void {
		$change_id = $this->submit_change();
		wp_set_current_user( $this->st_id );

		$first = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$first->set_param( 'status', 'rejected' );
		$first->set_param( 'notes', 'Not this time.' );
		$this->assertSame( 200, $this->dispatch( $first )->get_status() );

		$second = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$second->set_param( 'status', 'rejected' );
		$second->set_param( 'notes', 'Still no.' );
		$response = $this->dispatch( $second );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'change_not_pending', $response->as_error()->get_error_code() );
	}
}
