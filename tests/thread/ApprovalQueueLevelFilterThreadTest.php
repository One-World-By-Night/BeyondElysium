<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-099 (Pass H intake `t1-risky-screens`). A change's approval level is worked out
 * when it's shown, never stored, so the Approval Queue filtered by level on the one page it had
 * already fetched: a level with nothing on page 1 read "Nothing pending." beside "Page 1 (25
 * total)" and an enabled Next, while its changes sat a page away.
 */
class ApprovalQueueLevelFilterThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-queue-level-filter';
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->slug, 'name' => 'Queue Level Filter' ] );
		Schema_Block::create( [
			'slug' => 'qlf-abilities', 'name' => 'Queue Level Filter Abilities', 'section_type' => 'trait_list', 'is_system' => 1,
			'definition' => [ 'items' => [ [
				'name' => 'Occult', 'approval' => 'st',
				'approval_by_value' => [ [ 'from' => 1, 'to' => 3, 'approval' => 'auto' ], [ 'from' => 4, 'to' => 5, 'approval' => 'st' ] ],
			] ] ],
		] );
		$this->character = (int) Character::create( [ 'name' => 'Queue Filter Subject', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );

		// Newest first: five Storyteller-level changes, then twenty that resolve auto above them.
		foreach ( [ 5 => 4, 20 => 2 ] as $how_many => $count ) {
			for ( $i = 0; $i < $how_many; $i++ ) {
				Change::create( [
					'character_id' => $this->character, 'change_type' => 'modify_trait', 'status' => 'pending',
					'change_data'  => [ 'block_slug' => 'qlf-abilities', 'trait' => [ 'name' => 'Occult', 'count' => $count ] ],
				] );
			}
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}be_character_changes SET submitted_at = DATE_ADD( submitted_at, INTERVAL id SECOND ) WHERE character_id = %d",
			$this->character
		) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function queue( array $params ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/changes" );
		$request->set_query_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_level_filter_pages_through_that_level_alone(): void {
		$first = $this->queue( [ 'approval_level' => 'st', 'per_page' => 20, 'page' => 1 ] );

		$this->assertSame( 200, $first->get_status() );
		$this->assertCount( 5, $first->get_data() );
		$this->assertSame( [ 'st' ], array_values( array_unique( array_map( static fn( $row ) => $row->approval_level, $first->get_data() ) ) ) );
		$this->assertSame( 5, (int) $first->get_headers()['X-WP-Total'] );
	}

	public function test_a_later_page_of_a_level_holds_the_rest_of_it(): void {
		$second = $this->queue( [ 'approval_level' => 'auto', 'per_page' => 15, 'page' => 2 ] );

		$this->assertCount( 5, $second->get_data() );
		$this->assertSame( 20, (int) $second->get_headers()['X-WP-Total'] );
	}

	public function test_no_level_filter_pages_as_before(): void {
		$all = $this->queue( [ 'per_page' => 20, 'page' => 1 ] );

		$this->assertCount( 20, $all->get_data() );
		$this->assertSame( 25, (int) $all->get_headers()['X-WP-Total'] );
	}
}
