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
 * 1.0.0-review F-056. Approving a change wrote it to the sheet and the XP, then recorded the
 * approval without checking that write. When it failed - found live, with this branch's code
 * running against a database that did not have `review_notes` yet - the sheet and XP changed,
 * the change stayed pending, and approving it again from the queue applied it a second time.
 * The writes now land together or not at all.
 */
class ChangeApprovalWriteFailureThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-approval-write-failure';
	private int $storyteller;
	private int $player;
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$game_id           = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Approval Write Failure' ] );
		$this->storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->player      = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->storyteller, 'hst' );
		Game_Member::set_role( $game_id, $this->player, 'player' );

		$this->character = Character::create( [
			'name' => 'Write Failure Test', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
		] );
		Character::update_xp( $this->character, 20, 20 );
	}

	public function tearDown(): void {
		remove_filter( 'query', [ $this, 'break_the_approval_write' ] );
		parent::tearDown();
	}

	private function pending_change(): int {
		return Change::create( [
			'character_id' => $this->character,
			'change_type'  => 'add_trait',
			'category'     => 'thread-block',
			'change_data'  => [ 'block_slug' => 'thread-block', 'trait' => [ 'name' => 'Occult', 'count' => 3 ] ],
			'xp_cost'      => 5,
			'status'       => 'pending',
			'submitted_by' => $this->player,
		] );
	}

	/** Makes the one write that records an approval fail, the way a missing column does. */
	public function break_the_approval_write( string $query ): string {
		$records_approval = str_starts_with( $query, 'UPDATE' )
			&& str_contains( $query, 'be_character_changes' )
			&& str_contains( $query, "`status` = 'approved'" );
		return $records_approval ? 'UPDATE be_thread_no_such_table SET status = 1' : $query;
	}

	private function with_the_approval_write_broken( callable $act ) {
		global $wpdb;
		add_filter( 'query', [ $this, 'break_the_approval_write' ] );
		$quiet = $wpdb->suppress_errors( true );
		try {
			return $act();
		} finally {
			$wpdb->suppress_errors( $quiet );
			remove_filter( 'query', [ $this, 'break_the_approval_write' ] );
		}
	}

	public function test_a_change_whose_approval_cannot_be_recorded_is_not_applied_either(): void {
		$change_id = $this->pending_change();

		$approved = $this->with_the_approval_write_broken( fn() => Change_Engine::approve( $change_id, $this->storyteller, null ) );

		$character = Character::find( $this->character );
		$this->assertFalse( $approved );
		$this->assertSame( 20, (int) $character->xp_unspent );
		$this->assertArrayNotHasKey( 'thread-block', $character->sheet_data );
		$this->assertSame( 'pending', Change::find( $change_id )->status );
	}

	public function test_approving_it_again_once_the_write_works_applies_it_exactly_once(): void {
		$change_id = $this->pending_change();
		$this->with_the_approval_write_broken( fn() => Change_Engine::approve( $change_id, $this->storyteller, null ) );

		$this->assertTrue( Change_Engine::approve( $change_id, $this->storyteller, null ) );

		$character = Character::find( $this->character );
		$this->assertSame( 15, (int) $character->xp_unspent );
		$this->assertCount( 1, $character->sheet_data['thread-block'] );
	}

	public function test_the_queue_reports_a_failed_approval_as_a_failure_not_as_an_edit(): void {
		$change_id = $this->pending_change();
		wp_set_current_user( $this->storyteller );
		$token = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/changes" ) )->get_data()[0]->review_token;

		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/changes/{$change_id}" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'status' => 'approved', 'review_token' => $token ] ) );
		$response = $this->with_the_approval_write_broken( fn() => rest_get_server()->dispatch( $request ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'update_failed', $response->as_error()->get_error_code() );
		$this->assertSame( 20, (int) Character::find( $this->character )->xp_unspent );
	}
}
