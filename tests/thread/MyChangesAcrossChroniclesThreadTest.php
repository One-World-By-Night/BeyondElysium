<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `GET /my/changes`: a player's own changes across every chronicle on this site where they have a character, pending
 * plus anything reviewed in the last 30 days, each carrying its chronicle, a plain description, and a display status
 * distinguishing an auto-approved change from a Storyteller's own approval.
 */
class MyChangesAcrossChroniclesThreadTest extends WP_UnitTestCase {

	private int $player;
	private int $other_player;
	private int $hst;
	private int $char_a;
	private int $char_b;
	private int $char_other;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => 'thread-mychanges-a', 'name' => 'My Changes A' ] );
		Game::create( [ 'slug' => 'thread-mychanges-b', 'name' => 'My Changes B' ] );

		$this->player       = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->other_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->hst          = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->char_a = Character::create( [
			'name' => 'Changes A', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => 'thread-mychanges-a', 'wp_user_id' => $this->player,
		] );
		$this->char_b = Character::create( [
			'name' => 'Changes B', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => 'thread-mychanges-b', 'wp_user_id' => $this->player,
		] );
		$this->char_other = Character::create( [
			'name' => 'Someone Else', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => 'thread-mychanges-a', 'wp_user_id' => $this->other_player,
		] );
	}

	private function dispatch(): \WP_REST_Response {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'GET', '/be/v1/my/changes' );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Inserts a change row directly, with full control over status, auto_approved, and reviewed_at (for backdating).
	 */
	private function insert_change( array $data ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_character_changes', array_merge( [
			'change_type'  => 'xp_earn',
			'change_data'  => '{"amount":1}',
			'submitted_by' => $this->player,
			'submitted_at' => current_time( 'mysql' ),
			'auto_approved' => 0,
		], $data ) );
		return (int) $wpdb->insert_id;
	}

	public function test_a_player_with_characters_in_two_chronicles_sees_both(): void {
		$this->insert_change( [ 'character_id' => $this->char_a, 'status' => 'pending' ] );
		$this->insert_change( [ 'character_id' => $this->char_b, 'status' => 'pending' ] );

		$response = $this->dispatch();
		$this->assertSame( 200, $response->get_status() );
		$slugs = array_column( $response->get_data(), 'game_slug' );
		$this->assertContains( 'thread-mychanges-a', $slugs );
		$this->assertContains( 'thread-mychanges-b', $slugs );
	}

	public function test_a_change_reviewed_31_days_ago_is_left_out(): void {
		$old = gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS );
		$this->insert_change( [
			'character_id' => $this->char_a, 'status' => 'approved',
			'reviewed_by' => $this->hst, 'reviewed_at' => $old,
		] );

		$response = $this->dispatch();
		$this->assertCount( 0, $response->get_data() );
	}

	public function test_a_change_reviewed_within_30_days_is_included(): void {
		$recent = gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS );
		$this->insert_change( [
			'character_id' => $this->char_a, 'status' => 'approved',
			'reviewed_by' => $this->hst, 'reviewed_at' => $recent,
		] );

		$response = $this->dispatch();
		$this->assertCount( 1, $response->get_data() );
	}

	public function test_another_players_changes_never_appear(): void {
		$this->insert_change( [ 'character_id' => $this->char_other, 'status' => 'pending' ] );

		$response = $this->dispatch();
		$this->assertCount( 0, $response->get_data() );
	}

	public function test_a_chronicle_where_the_player_has_no_character_adds_nothing(): void {
		Game::create( [ 'slug' => 'thread-mychanges-empty', 'name' => 'Empty Chronicle' ] );

		$response = $this->dispatch();
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, $response->get_data() );
	}

	public function test_an_auto_approved_change_reads_auto_approved_and_a_storytellers_reads_approved(): void {
		$auto = $this->insert_change( [
			'character_id' => $this->char_a, 'status' => 'approved', 'auto_approved' => 1,
			'reviewed_by' => $this->player, 'reviewed_at' => current_time( 'mysql' ),
		] );
		$manual = $this->insert_change( [
			'character_id' => $this->char_a, 'status' => 'approved', 'auto_approved' => 0,
			'reviewed_by' => $this->hst, 'reviewed_at' => current_time( 'mysql' ),
		] );

		$response = $this->dispatch();
		$by_id = [];
		foreach ( $response->get_data() as $row ) {
			$by_id[ $row->id ] = $row;
		}
		$this->assertSame( 'auto_approved', $by_id[ $auto ]->display_status );
		$this->assertSame( 'approved', $by_id[ $manual ]->display_status );
	}

	public function test_a_pending_change_reads_pending_and_a_rejected_one_reads_refused(): void {
		$pending = $this->insert_change( [ 'character_id' => $this->char_a, 'status' => 'pending' ] );
		$refused = $this->insert_change( [
			'character_id' => $this->char_a, 'status' => 'rejected',
			'reviewed_by' => $this->hst, 'reviewed_at' => current_time( 'mysql' ),
		] );

		$response = $this->dispatch();
		$by_id = [];
		foreach ( $response->get_data() as $row ) {
			$by_id[ $row->id ] = $row;
		}
		$this->assertSame( 'pending', $by_id[ $pending ]->display_status );
		$this->assertSame( 'refused', $by_id[ $refused ]->display_status );
	}

	public function test_a_refusals_note_shows_with_st_text_stripped(): void {
		$id = $this->insert_change( [
			'character_id' => $this->char_a, 'status' => 'rejected',
			'reviewed_by' => $this->hst, 'reviewed_at' => current_time( 'mysql' ),
			'review_notes' => 'Visible. [ST]Staff only.[/ST] Also visible.',
		] );

		$response = $this->dispatch();
		$row = current( array_filter( $response->get_data(), fn( $r ) => (int) $r->id === $id ) );
		$this->assertStringNotContainsString( 'Staff only', $row->review_notes );
		$this->assertStringContainsString( 'Visible.', $row->review_notes );
	}

	public function test_each_row_carries_a_plain_description(): void {
		$this->insert_change( [ 'character_id' => $this->char_a, 'status' => 'pending', 'change_data' => '{"amount":5}' ] );

		$response = $this->dispatch();
		$this->assertNotEmpty( $response->get_data()[0]->description );
	}
}
