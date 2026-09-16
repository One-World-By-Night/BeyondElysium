<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Boolean-shaped tinyint(1) columns must reach REST clients as real integers.
 * `$wpdb` returns every column as a string, and the string "0" is truthy in
 * JavaScript - D51 was exactly this for `is_npc`. Left uncast on
 * `storyteller_only`, it made the Schema Blocks editor mark every block it saved
 * as Storyteller-only, hiding Abilities from every player on a live chronicle.
 */
class BooleanFlagTypesThreadTest extends WP_UnitTestCase {

	private int $admin_id;
	private string $game_slug = 'thread-test-flag-types';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Flag Types Game', 'notifications_enabled' => 0,
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );

		Schema_Block::create( [
			'slug' => 'thread-flag-plain-block', 'name' => 'Plain Block', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [] ], 'is_system' => 0, 'storyteller_only' => 0,
		] );
	}

	private function get( string $path, array $query = [] ) {
		$request = new WP_REST_Request( 'GET', $path );
		$request->set_query_params( $query );
		return rest_get_server()->dispatch( $request );
	}

	public function test_schema_block_flags_are_booleans_over_rest(): void {
		$block = (array) $this->get( '/be/v1/schema-blocks/thread-flag-plain-block' )->get_data();

		$this->assertSame( false, $block["storyteller_only"], "storyteller_only must be boolean false - a JSON boolean cannot be a truthy \"0\"." );
		$this->assertSame( false, $block["is_system"], "is_system must be boolean false, not the truthy string \"0\"." );
	}

	public function test_schema_block_flags_are_booleans_in_list_responses(): void {
		$rows = $this->get( '/be/v1/schema-blocks', [ 'per_page' => 100, 'search' => 'Plain Block' ] )->get_data();
		$row  = null;
		foreach ( $rows as $candidate ) {
			$candidate = (array) $candidate;
			if ( $candidate['slug'] === 'thread-flag-plain-block' ) {
				$row = $candidate;
			}
		}

		$this->assertNotNull( $row );
		$this->assertSame( false, $row["storyteller_only"] );
		$this->assertSame( false, $row["is_system"] );
	}

	public function test_a_real_storyteller_only_block_still_reads_as_one(): void {
		Schema_Block::update( 'thread-flag-plain-block', [ 'storyteller_only' => 1 ] );
		$block = Schema_Block::find_by_slug( 'thread-flag-plain-block' );

		$this->assertSame( true, $block->storyteller_only );
	}

	public function test_creature_stack_is_system_is_a_boolean(): void {
		$stacks = $this->get( '/be/v1/creature-stacks', [ 'per_page' => 1 ] )->get_data();
		$this->assertNotEmpty( $stacks, 'The test install seeds creature stacks.' );

		$this->assertIsBool( ( (array) $stacks[0] )["is_system"] );
		$this->assertIsBool( Creature_Stack::find_by_slug( ( (array) $stacks[0] )["slug"] )->is_system );
	}

	public function test_game_notifications_enabled_is_a_boolean(): void {
		$game = (array) $this->get( '/be/v1/games/' . $this->game_slug )->get_data();

		$this->assertSame( false, $game["notifications_enabled"], "notifications_enabled must be boolean false so a disabled chronicle does not render as enabled." );
		$this->assertSame( false, Game::find_by_slug( $this->game_slug )->notifications_enabled );
	}
}
