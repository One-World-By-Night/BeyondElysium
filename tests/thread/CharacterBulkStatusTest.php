<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `POST /{game_slug}/characters/bulk-status` and `GET /{game_slug}/characters/statuses`
 * (bulk-operations-design.md Item 2) - "retire, transfer" (excluding actual
 * chronicle-to-chronicle transfer, which already means something else in this codebase)
 * "or mark inactive across a selection," validated against the same `Character::STATUSES`
 * a single `PUT /{game_slug}/characters/{id}` already enforces (Decision 102).
 */
class CharacterBulkStatusTest extends WP_UnitTestCase {

	private string $game_slug       = 'thread-test-bulk-status-game';
	private string $other_game_slug = 'thread-test-bulk-status-other-game';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		foreach ( [ $this->game_slug, $this->other_game_slug ] as $slug ) {
			$wpdb->insert( $wpdb->prefix . 'be_games', [
				'slug' => $slug, 'name' => "Thread Test {$slug}",
				'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			] );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function character( string $slug, string $status = 'active' ): int {
		return Character::create( [
			'name' => 'Bulk Status Test', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $slug, 'status' => $status,
		] );
	}

	private function bulk_status( array $character_ids, string $status ) {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/bulk-status" );
		$request->set_param( 'character_ids', $character_ids );
		$request->set_param( 'status', $status );
		return rest_get_server()->dispatch( $request );
	}

	public function test_updates_status_for_every_character_in_the_list(): void {
		$a = $this->character( $this->game_slug );
		$b = $this->character( $this->game_slug );

		$response = $this->bulk_status( [ $a, $b ], 'inactive' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['updated'] );

		$this->assertSame( 'inactive', Character::find( $a )->status );
		$this->assertSame( 'inactive', Character::find( $b )->status );
	}

	public function test_an_invalid_status_is_rejected(): void {
		$a = $this->character( $this->game_slug );

		$response = $this->bulk_status( [ $a ], 'not-a-real-status' );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'active', Character::find( $a )->status );
	}

	public function test_a_character_belonging_to_a_different_game_is_reported_not_found(): void {
		$foreign = $this->character( $this->other_game_slug );

		$response = $this->bulk_status( [ $foreign ], 'retired' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['updated'] );

		$results = $response->get_data()['results'];
		$this->assertFalse( $results[0]['success'] );
		$this->assertSame( 'not_found', $results[0]['error'] );

		// Never touched - a bad ID in the batch must not silently corrupt another chronicle's character.
		$this->assertSame( 'active', Character::find( $foreign )->status );
	}

	public function test_partial_failure_reports_a_per_id_result_not_all_or_nothing(): void {
		$real    = $this->character( $this->game_slug );
		$foreign = $this->character( $this->other_game_slug );

		$response = $this->bulk_status( [ $real, $foreign ], 'dead' );
		$this->assertSame( 1, $response->get_data()['updated'] );

		// The real character in this game was updated despite the foreign ID's failure.
		$this->assertSame( 'dead', Character::find( $real )->status );
		$this->assertSame( 'active', Character::find( $foreign )->status );

		$results = $response->get_data()['results'];
		$this->assertCount( 2, $results );
	}

	public function test_missing_character_ids_is_rejected(): void {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/bulk-status" );
		$request->set_param( 'status', 'retired' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_non_manager_is_denied(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->bulk_status( [ 1 ], 'retired' );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_get_statuses_returns_the_real_vocabulary(): void {
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/statuses" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[ 'active', 'inactive', 'retired', 'dead', 'pending' ],
			$response->get_data()['statuses']
		);
	}
}
