<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-116. The Approval Queue showed "Submitted by" as a bare WordPress user id
 * ("1") instead of who actually submitted the change - every other reviewer-facing field
 * (character_name, approval_level) is resolved to something readable, but submitted_by never
 * was.
 */
class ApprovalQueueSubmitterNameThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-queue-submitter-name';

	public function test_the_queue_names_who_submitted_each_change(): void {
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->slug, 'name' => 'Queue Submitter Name' ] );
		$player = self::factory()->user->create( [ 'display_name' => 'Alice Player', 'role' => 'subscriber' ] );
		$character = (int) Character::create( [ 'name' => 'Submitter Subject', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );

		Change::create( [
			'character_id' => $character, 'change_type' => 'modify_trait', 'status' => 'pending',
			'change_data'  => [ 'block_slug' => 'met-abilities', 'trait' => [ 'name' => 'Occult', 'count' => 1 ] ],
			'submitted_by' => $player,
		] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/changes" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$items = $response->get_data();
		$this->assertCount( 1, $items );
		$this->assertSame( 'Alice Player', $items[0]->submitted_by_name );
		$this->assertSame( $player, (int) $items[0]->submitted_by );
	}

	public function test_a_deleted_submitter_falls_back_to_the_id(): void {
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->slug, 'name' => 'Queue Submitter Name' ] );
		$character = (int) Character::create( [ 'name' => 'Orphan Subject', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );

		Change::create( [
			'character_id' => $character, 'change_type' => 'modify_trait', 'status' => 'pending',
			'change_data'  => [ 'block_slug' => 'met-abilities', 'trait' => [ 'name' => 'Occult', 'count' => 1 ] ],
			'submitted_by' => 999999,
		] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/changes" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertNull( $response->get_data()[0]->submitted_by_name );
	}
}
