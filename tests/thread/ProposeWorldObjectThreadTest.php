<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A player proposes an item for their own character.
 */
class ProposeWorldObjectThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-propose-item';
	private int $game_id;
	private int $player;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Propose Item',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );

		$this->character_id = (int) Character::create( [
			'game_slug'  => $this->game_slug,
			'owner_slug' => $this->game_slug,
			'name'       => 'The Artificer',
			'stack_slug' => 'vampire',
			'wp_user_id' => $this->player,
			'created_by' => $this->player,
		] );
	}

	private function propose( array $data = [] ) {
		wp_set_current_user( $this->player );

		$request = new WP_REST_Request(
			'POST',
			"/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes"
		);
		$request->set_body_params( array_merge( [
			'change_type' => 'propose_world_object',
			'category'    => 'world_object',
			'change_data' => [
				'object_type' => 'item',
				'name'        => 'Ashwood Stake',
				'description' => '<p>Carved from a <em>very</em> old tree.</p>',
				'properties'  => [ 'item_type' => 'Weapon', 'level' => 2 ],
			],
		], $data ) );

		return rest_get_server()->dispatch( $request );
	}

	private function reviewer( string $role ): int {
		$user = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $user, $role );
		return $user;
	}

	private function approve( int $user_id, int $change_id ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$request->set_body_params( [ 'status' => 'approved' ] );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_player_can_propose_an_item_for_their_own_character(): void {
		$response = $this->propose();

		$this->assertSame( 201, $response->get_status() );
		$change = (array) $response->get_data();
		$this->assertSame( 'propose_world_object', $change['change_type'] );
		$this->assertSame( 'pending', $change['status'] );
		$this->assertSame( 0.0, (float) $change['xp_cost'], 'An item is not an XP purchase.' );
	}

	public function test_an_invented_object_type_is_refused_on_the_way_in(): void {
		$response = $this->propose( [ 'change_data' => [
			'object_type' => 'starship',
			'name'        => 'The Nostromo',
		] ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_proposal_with_no_name_is_refused(): void {
		$response = $this->propose( [ 'change_data' => [
			'object_type' => 'item',
			'name'        => '   ',
		] ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_approving_creates_the_catalog_row_and_connects_it_to_the_character(): void {
		$change_id = (int) ( (array) $this->propose()->get_data() )['id'];

		$hst = $this->reviewer( 'hst' );
		$this->assertSame( 200, $this->approve( $hst, $change_id )->get_status() );

		$objects = World_Object::for_game( $this->game_id, [ 'per_page' => 50 ] );
		$names   = array_map( static fn( $o ) => $o->name, $objects );
		$this->assertContains( 'Ashwood Stake', $names );

		$made = null;
		foreach ( $objects as $object ) {
			if ( $object->name === 'Ashwood Stake' ) {
				$made = $object;
			}
		}
		$this->assertNotNull( $made );
		$this->assertStringContainsString( '<em>very</em>', (string) $made->description );

		$connections = Connection::for_source( 'character', $this->character_id );
		$targets     = array_map( static fn( $c ) => (int) $c->target_id, $connections );
		$this->assertContains(
			(int) $made->id,
			$targets,
			'The player asked for their character to have it - the connection is half of what was approved.'
		);
	}

	/**
	 * A reviewer without catalog rights cannot approve an item proposal.
	 */
	public function test_a_reviewer_without_catalog_rights_cannot_approve_it(): void {
		$change_id = (int) ( (array) $this->propose()->get_data() )['id'];

		$reviewer = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $reviewer, 'hst' );

		$user = new \WP_User( $reviewer );
		$user->add_cap( 'be_manage_world_objects', false );

		$response = $this->approve( $reviewer, $change_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'catalog_permission_denied', $response->get_data()['code'] );

		$this->assertSame(
			'pending',
			(string) Change::find( $change_id )->status,
			'A refused approval must leave the change for someone who can decide it.'
		);

		$names = array_map(
			static fn( $o ) => $o->name,
			World_Object::for_game( $this->game_id, [ 'per_page' => 50 ] )
		);
		$this->assertNotContains( 'Ashwood Stake', $names, 'Nothing may reach the catalog.' );
	}

	public function test_rejecting_writes_nothing_to_the_catalog(): void {
		$change_id = (int) ( (array) $this->propose()->get_data() )['id'];

		$hst = $this->reviewer( 'hst' );
		wp_set_current_user( $hst );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$request->set_body_params( [ 'status' => 'rejected', 'notes' => 'We already have one.' ] );
		rest_get_server()->dispatch( $request );

		$names = array_map(
			static fn( $o ) => $o->name,
			World_Object::for_game( $this->game_id, [ 'per_page' => 50 ] )
		);
		$this->assertNotContains( 'Ashwood Stake', $names );
	}
}
