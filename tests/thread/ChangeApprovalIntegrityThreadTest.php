<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Change_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-015 and F-031.
 *
 * F-015: approving a change checked "still pending" in the controller, then applied it with
 * nothing tying the check to the write. Two approvals landing together both applied: the XP
 * came off twice and the trait was added twice. Approval now claims the change itself, once.
 *
 * F-031: a player's resubmission rewrites a pending change in place, and approval acted on the
 * id alone - a Storyteller who read "Occult 2 -> 3" could approve "Occult 2 -> 5". The queue now
 * hands out a review token for exactly what it showed, and a stale token is refused.
 */
class ChangeApprovalIntegrityThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-approval-integrity';
	private int $storyteller;
	private int $player;
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) Game::find_by_slug( $this->slug )->id;

		$this->storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $this->storyteller, 'hst' );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->player, 'player' );

		$this->character = Character::create( [
			'name' => 'Integrity Test', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
		] );
		Character::update_xp( $this->character, 20, 20 );
	}

	private function pending_trait_change( int $cost ): int {
		return Change::create( [
			'character_id' => $this->character,
			'change_type'  => 'add_trait',
			'category'     => 'trait',
			'change_data'  => [ 'block_slug' => 'thread-block', 'trait' => [ 'name' => 'Occult', 'count' => 3 ] ],
			'xp_cost'      => $cost,
			'status'       => 'pending',
			'submitted_by' => $this->player,
		] );
	}

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_change_is_applied_once_however_many_times_approval_reaches_it(): void {
		$change_id = $this->pending_trait_change( 5 );

		$first  = Change_Engine::approve( $change_id, $this->storyteller, null );
		$second = Change_Engine::approve( $change_id, $this->storyteller, null );

		$character = Character::find( $this->character );
		$this->assertTrue( $first );
		$this->assertFalse( $second );
		$this->assertSame( 15, (int) $character->xp_unspent );
		$this->assertCount( 1, $character->sheet_data['thread-block'] );
	}

	public function test_an_auto_approved_submission_still_applies(): void {
		wp_set_current_user( $this->storyteller );

		$response = $this->dispatch( 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/changes", [
			'change_type' => 'xp_adjust',
			'category'    => 'experience',
			'change_data' => [ 'amount' => 4 ],
		] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'approved', $response->get_data()->status );
		$this->assertSame( 24, (int) Character::find( $this->character )->xp_earned );
	}

	public function test_the_queue_hands_out_a_review_token_and_approval_honors_it(): void {
		$change_id = $this->pending_trait_change( 5 );
		wp_set_current_user( $this->storyteller );

		$queue = $this->dispatch( 'GET', "/be/v1/{$this->slug}/changes" )->get_data();
		$token = $queue[0]->review_token;

		$response = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/changes/{$change_id}", [ 'status' => 'approved', 'review_token' => $token ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'approved', Change::find( $change_id )->status );
	}

	public function test_approving_a_change_that_was_resubmitted_after_the_queue_loaded_is_refused(): void {
		$change_id = $this->pending_trait_change( 5 );
		wp_set_current_user( $this->storyteller );
		$token = $this->dispatch( 'GET', "/be/v1/{$this->slug}/changes" )->get_data()[0]->review_token;

		// The player resubmits a bigger purchase into the same pending row.
		Change::update_pending_data( $change_id, [
			'change_type' => 'add_trait',
			'category'    => 'trait',
			'change_data' => [ 'block_slug' => 'thread-block', 'trait' => [ 'name' => 'Occult', 'count' => 5 ] ],
			'xp_cost'     => 15,
		] );

		$response = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/changes/{$change_id}", [ 'status' => 'approved', 'review_token' => $token ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'change_changed', $response->as_error()->get_error_code() );
		$this->assertSame( 'pending', Change::find( $change_id )->status );
		$this->assertSame( 20, (int) Character::find( $this->character )->xp_unspent );
	}

	public function test_batch_approve_skips_a_change_whose_token_is_stale(): void {
		$change_id = $this->pending_trait_change( 5 );
		wp_set_current_user( $this->storyteller );

		$response = $this->dispatch( 'POST', "/be/v1/{$this->slug}/changes/batch-approve", [
			'change_ids'    => [ $change_id ],
			'review_tokens' => [ (string) $change_id => 'not-the-token-the-queue-gave' ],
		] );

		$this->assertSame( [ $change_id ], $response->get_data()['skipped'] );
		$this->assertSame( 'pending', Change::find( $change_id )->status );
	}

	public function test_a_resubmission_that_loses_the_race_to_an_approval_reports_failure(): void {
		$change_id = $this->pending_trait_change( 5 );
		Change_Engine::approve( $change_id, $this->storyteller, null );

		$updated = Change::update_pending_data( $change_id, [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'thread-block', 'trait' => [ 'name' => 'Occult', 'count' => 5 ] ],
			'xp_cost'     => 15,
		] );

		$this->assertFalse( $updated );
	}
}
