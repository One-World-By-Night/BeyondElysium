<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `POST /{game_slug}/resource-pools/bulk-reset` (bulk-operations-design.md Item 1) - the
 * ordinary end-of-session "everyone's Willpower/Blood refills" action, applied to a batch
 * of characters at once instead of one at a time. Writes `sheet_data` directly, never
 * routed through `Change_Engine`/`character_changes`, matching how a starting sheet is
 * written directly at creation.
 */
class ResourcePoolBulkResetTest extends WP_UnitTestCase {

	private string $game_slug       = 'thread-test-bulk-reset-game';
	private string $other_game_slug = 'thread-test-bulk-reset-other-game';

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

	private function character( string $slug, array $sheet_data = [] ): int {
		return Character::create( [
			'name' => 'Bulk Reset Test', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $slug,
			'sheet_data' => $sheet_data,
		] );
	}

	private function bulk_reset( array $character_ids, string $block_slug, string $pool_name ) {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/resource-pools/bulk-reset" );
		$request->set_param( 'character_ids', $character_ids );
		$request->set_param( 'block_slug', $block_slug );
		$request->set_param( 'pool_name', $pool_name );
		return rest_get_server()->dispatch( $request );
	}

	public function test_resets_temporary_to_permanent_for_the_object_shape(): void {
		$id = $this->character( $this->game_slug, [
			'vampire-resources' => [ 'Blood' => [ 'permanent' => 10, 'temporary' => 3 ] ],
		] );

		$response = $this->bulk_reset( [ $id ], 'vampire-resources', 'Blood' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['reset'] );

		$character = Character::find( $id );
		$this->assertSame(
			[ 'permanent' => 10, 'temporary' => 10 ],
			$character->sheet_data['vampire-resources']['Blood']
		);
	}

	public function test_a_character_who_does_not_hold_the_pool_is_a_noop_not_a_failure(): void {
		$id = $this->character( $this->game_slug, [] );

		$response = $this->bulk_reset( [ $id ], 'vampire-resources', 'Blood' );
		$this->assertSame( 200, $response->get_status() );
		// Still counted - a no-op success, matching bulk_award_xp's own "count what applied"
		// shape, not an error return.
		$this->assertSame( 1, $response->get_data()['reset'] );

		$character = Character::find( $id );
		$this->assertArrayNotHasKey( 'vampire-resources', $character->sheet_data );
	}

	public function test_a_bare_scalar_pool_is_a_noop_since_it_has_no_separate_temporary(): void {
		$id = $this->character( $this->game_slug, [
			'vampire-resources' => [ 'Blood' => 10 ],
		] );

		$this->bulk_reset( [ $id ], 'vampire-resources', 'Blood' );

		$character = Character::find( $id );
		$this->assertSame( 10, $character->sheet_data['vampire-resources']['Blood'] );
	}

	public function test_a_character_belonging_to_a_different_game_is_skipped_not_reset(): void {
		$foreign_id = $this->character( $this->other_game_slug, [
			'vampire-resources' => [ 'Blood' => [ 'permanent' => 10, 'temporary' => 2 ] ],
		] );

		$response = $this->bulk_reset( [ $foreign_id ], 'vampire-resources', 'Blood' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['reset'] );

		$character = Character::find( $foreign_id );
		$this->assertSame( 2, $character->sheet_data['vampire-resources']['Blood']['temporary'] );
	}

	public function test_a_mixed_batch_only_resets_the_in_game_characters(): void {
		$in_game  = $this->character( $this->game_slug, [ 'vampire-resources' => [ 'Blood' => [ 'permanent' => 10, 'temporary' => 1 ] ] ] );
		$foreign  = $this->character( $this->other_game_slug, [ 'vampire-resources' => [ 'Blood' => [ 'permanent' => 10, 'temporary' => 1 ] ] ] );

		$response = $this->bulk_reset( [ $in_game, $foreign ], 'vampire-resources', 'Blood' );
		$this->assertSame( 1, $response->get_data()['reset'] );

		$this->assertSame( 10, Character::find( $in_game )->sheet_data['vampire-resources']['Blood']['temporary'] );
		$this->assertSame( 1, Character::find( $foreign )->sheet_data['vampire-resources']['Blood']['temporary'] );
	}

	public function test_missing_params_are_rejected(): void {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/resource-pools/bulk-reset" );
		$request->set_param( 'character_ids', [ 1 ] );
		// block_slug and pool_name both omitted.
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_non_manager_is_denied(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->bulk_reset( [ 1 ], 'vampire-resources', 'Blood' );
		$this->assertSame( 403, $response->get_status() );
	}
}
