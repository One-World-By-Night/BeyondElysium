<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Connection;
use WP_UnitTestCase;

/**
 * The universal join (Decision 016) is the piece most likely to be misused - a
 * validated bidirectional lookup and duplicate rejection here is what keeps every
 * future controller from hand-rolling its own UNION or its own dedup check.
 *
 * Lives in tests/thread/, not tests/unit/ as workflow-0.5.md Step 1n names it: the
 * model calls $wpdb directly (find_duplicate(), for_entity()), which TESTING.md
 * defines as the thread layer's boundary, not unit's.
 *
 * @see BE_PROCESS/workflow-0.5.md Step 1.3
 */
class ConnectionTest extends WP_UnitTestCase {

	private int $game_id;

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => 'connection-test-game', 'name' => 'Connection Test Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	public function test_bidirectional_lookup_returns_connection_from_either_end(): void {
		$id = Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => 1,
			'target_type' => 'plot', 'target_id' => 2, 'created_by' => 1,
		] );

		$this->assertNotFalse( $id );
		$this->assertCount( 1, Connection::for_entity( 'character', 1 ) );
		$this->assertCount( 1, Connection::for_entity( 'plot', 2 ) );
		$this->assertSame( (int) $id, (int) Connection::for_entity( 'character', 1 )[0]->id );
		$this->assertSame( (int) $id, (int) Connection::for_entity( 'plot', 2 )[0]->id );
	}

	public function test_duplicate_connection_is_a_no_op(): void {
		$first = Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => 5,
			'target_type' => 'character', 'target_id' => 6, 'label' => 'sister', 'created_by' => 1,
		] );
		$second = Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => 5,
			'target_type' => 'character', 'target_id' => 6, 'label' => 'sister', 'created_by' => 1,
		] );

		$this->assertSame( $first, $second, 'the same tuple twice must return the existing row, not create a second one' );
		$this->assertCount( 1, Connection::for_source( 'character', 5 ) );
	}

	public function test_same_endpoints_different_label_are_not_duplicates(): void {
		$first  = Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => 7,
			'target_type' => 'character', 'target_id' => 8, 'label' => 'sister', 'created_by' => 1,
		] );
		$second = Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => 7,
			'target_type' => 'character', 'target_id' => 8, 'label' => 'rival', 'created_by' => 1,
		] );

		$this->assertNotSame( $first, $second );
		$this->assertCount( 2, Connection::for_source( 'character', 7 ) );
	}

	public function test_tag_target_allows_null_target_id(): void {
		$id = Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => 3,
			'target_type' => 'tag', 'label' => 'Black Hand', 'created_by' => 1,
		] );

		$this->assertNotFalse( $id );
		$connection = Connection::find( (int) $id );
		$this->assertNull( $connection->target_id );
	}

	public function test_invalid_entity_type_is_rejected(): void {
		$result = Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => 1,
			'target_type' => 'not_a_real_type', 'target_id' => 2, 'created_by' => 1,
		] );

		$this->assertFalse( $result );
	}

	public function test_non_tag_target_requires_a_target_id(): void {
		$result = Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => 1,
			'target_type' => 'plot', 'created_by' => 1,
		] );

		$this->assertFalse( $result );
	}

	public function test_delete_for_entity_removes_connections_in_both_directions(): void {
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => 9,
			'target_type' => 'plot', 'target_id' => 10, 'created_by' => 1,
		] );
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => 10,
			'target_type' => 'character', 'target_id' => 11, 'created_by' => 1,
		] );

		Connection::delete_for_entity( 'plot', 10 );

		$this->assertCount( 0, Connection::for_entity( 'plot', 10 ) );
	}
}
