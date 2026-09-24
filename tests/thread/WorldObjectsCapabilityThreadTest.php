<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The Storyteller Toolkit's World Objects tab gates on `be_manage_world_objects` resolved *per chronicle*.
 */
class WorldObjectsCapabilityThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-wo-capability';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread WO Capability',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ), 'settings' => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	/** @return array<string,bool> */
	private function capabilities_for( int $user_id ): array {
		wp_set_current_user( $user_id );
		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/my/capabilities" )
		);
		return (array) ( (array) $response->get_data() )['capabilities'];
	}

	public function test_the_route_returns_the_world_objects_capability_at_all(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );

		$this->assertArrayHasKey(
			'be_manage_world_objects',
			$this->capabilities_for( $player ),
			'The World Objects tab keys on this; omitted, the tab is invisible to everyone.'
		);
	}

	public function test_an_hst_and_an_ast_manage_the_catalog(): void {
		foreach ( [ 'hst', 'ast' ] as $role ) {
			$user = self::factory()->user->create( [ 'role' => 'editor' ] );
			Game_Member::set_role( $this->game_id, $user, $role );

			$this->assertTrue(
				$this->capabilities_for( $user )['be_manage_world_objects'],
				"A {$role} should manage the world-object catalog."
			);
		}
	}

	public function test_a_narrator_a_harpy_and_a_player_do_not(): void {
		foreach ( [ 'narrator' => 'editor', 'boons' => 'subscriber', 'player' => 'subscriber' ] as $role => $wp_role ) {
			$user = self::factory()->user->create( [ 'role' => $wp_role ] );
			Game_Member::set_role( $this->game_id, $user, $role );

			$this->assertFalse(
				$this->capabilities_for( $user )['be_manage_world_objects'],
				"A {$role} should not manage the world-object catalog."
			);
		}
	}

	public function test_an_unknown_chronicle_reports_every_capability_false(): void {
		$user = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/be/v1/no-such-chronicle/my/capabilities' )
		);
		$capabilities = (array) ( (array) $response->get_data() )['capabilities'];

		$this->assertArrayHasKey( 'be_manage_world_objects', $capabilities );
		$this->assertFalse( $capabilities['be_manage_world_objects'] );
	}
}
