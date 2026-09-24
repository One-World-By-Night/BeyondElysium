<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Submission;
use WP_UnitTestCase;

/**
 * `Submission`'s own model-level contract.
 */
class SubmissionModelThreadTest extends WP_UnitTestCase {

	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => 'thread-test-submission', 'name' => 'Thread Test Submission',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	private function base_row( array $overrides = [] ): array {
		return array_merge( [
			'game_id'              => $this->game_id,
			'submitted_by'         => 1,
			'arrival'              => 'joining',
			'character_name'       => 'Test Character',
			'stack_slug'           => 'vampire',
			'source_file'          => 'sheet.gex',
			'format'               => 'XML',
			'file_hash'            => hash( 'sha256', 'x' ),
			'parsed'               => wp_json_encode( [ 'characters' => [] ] ),
			'verification_source'  => null,
		], $overrides );
	}

	public function test_create_inserts_a_waiting_row_and_find_returns_it(): void {
		$id  = Submission::create( $this->base_row() );
		$row = Submission::find( $id );

		$this->assertNotNull( $row );
		$this->assertSame( 'waiting', $row->state );
		$this->assertSame( 'joining', $row->arrival );
	}

	public function test_find_never_returns_the_file_columns(): void {
		$id  = Submission::create( $this->base_row() );
		$row = Submission::find( $id );

		$this->assertObjectNotHasProperty( 'parsed', $row );
		$this->assertObjectNotHasProperty( 'verification_source', $row );
	}

	public function test_find_with_file_returns_the_stored_parsed_data(): void {
		$id  = Submission::create( $this->base_row() );
		$row = Submission::find_with_file( $id );

		$this->assertObjectHasProperty( 'parsed', $row );
		$this->assertStringContainsString( 'characters', $row->parsed );
	}

	public function test_create_refuses_a_second_waiting_row_for_the_same_sender_and_chronicle(): void {
		Submission::create( $this->base_row() );

		$this->expectException( \RuntimeException::class );
		Submission::create( $this->base_row() );
	}

	public function test_create_allows_a_second_row_once_the_first_is_no_longer_waiting(): void {
		$first = Submission::create( $this->base_row() );
		Submission::transition( $first, 'withdrawn' );

		$second = Submission::create( $this->base_row() );

		$this->assertNotSame( $first, $second );
	}

	public function test_create_allows_the_same_sender_across_different_chronicles(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => 'thread-test-submission-2', 'name' => 'Thread Test Submission 2',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );
		$other_game_id = (int) $wpdb->insert_id;

		Submission::create( $this->base_row() );
		$id = Submission::create( $this->base_row( [ 'game_id' => $other_game_id ] ) );

		$this->assertNotNull( Submission::find( $id ) );
	}

	public function test_has_waiting_reflects_a_real_open_row(): void {
		$this->assertFalse( Submission::has_waiting( $this->game_id, 1 ) );

		Submission::create( $this->base_row() );

		$this->assertTrue( Submission::has_waiting( $this->game_id, 1 ) );
		$this->assertFalse( Submission::has_waiting( $this->game_id, 2 ) );
	}

	public function test_transition_clears_both_file_columns_and_stamps_answered_at(): void {
		$id = Submission::create( $this->base_row( [ 'verification_source' => '<grapevine/>' ] ) );

		Submission::transition( $id, 'refused', [ 'answer_note' => 'Not a fit for this chronicle.' ] );
		$row = Submission::find_with_file( $id );

		$this->assertSame( 'refused', $row->state );
		$this->assertNull( $row->parsed );
		$this->assertNull( $row->verification_source );
		$this->assertNotNull( $row->answered_at );
		$this->assertSame( 'Not a fit for this chronicle.', $row->answer_note );
		$this->assertFalse( Submission::has_waiting( $this->game_id, 1 ) );
	}

	public function test_transition_to_a_non_terminal_state_keeps_the_file(): void {
		// 'waiting' itself is the only non-terminal state this model knows about.
		$id = Submission::create( $this->base_row() );

		Submission::transition( $id, 'waiting' );
		$row = Submission::find_with_file( $id );

		$this->assertNotNull( $row->parsed );
	}

	public function test_waiting_for_game_lists_only_waiting_rows_for_that_game(): void {
		$id = Submission::create( $this->base_row() );
		$other = Submission::create( $this->base_row( [ 'submitted_by' => 2 ] ) );
		Submission::transition( $other, 'withdrawn' );

		$rows = Submission::waiting_for_game( $this->game_id );

		$this->assertCount( 1, $rows );
		$this->assertSame( $id, (int) $rows[0]->id );
	}

	public function test_count_waiting_matches_the_real_row_count(): void {
		Submission::create( $this->base_row() );
		Submission::create( $this->base_row( [ 'submitted_by' => 2 ] ) );

		$this->assertSame( 2, Submission::count_waiting( $this->game_id ) );
	}

	public function test_for_user_returns_the_chronicle_name_and_slug_without_the_file(): void {
		Submission::create( $this->base_row() );

		$rows = Submission::for_user( 1 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Thread Test Submission', $rows[0]->game_name );
		$this->assertSame( 'thread-test-submission', $rows[0]->game_slug );
		$this->assertObjectNotHasProperty( 'parsed', $rows[0] );
	}

	public function test_expire_stale_moves_only_old_waiting_rows(): void {
		global $wpdb;
		$fresh = Submission::create( $this->base_row() );
		$stale = Submission::create( $this->base_row( [ 'submitted_by' => 3 ] ) );

		$wpdb->update(
			$wpdb->prefix . 'be_character_submissions',
			[ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( Submission::WAITING_TTL_DAYS + 1 ) * DAY_IN_SECONDS ) ],
			[ 'id' => $stale ]
		);

		$expired = Submission::expire_stale();

		$this->assertSame( 1, $expired );
		$this->assertSame( 'waiting', Submission::find( $fresh )->state );
		$stale_row = Submission::find_with_file( $stale );
		$this->assertSame( 'expired', $stale_row->state );
		$this->assertNull( $stale_row->parsed );
	}
}
