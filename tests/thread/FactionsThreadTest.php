<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Faction;
use BeyondElysium\Models\Faction_Member;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 §3.10 (F1): sects, coteries, packs, chantries and courts. `be_manage_factions`
 * writes; a plain viewer reads name/type/description for anything `Audience` clears, and
 * goals plus the member roster only once they are a member (or a manager) of it too.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.10
 */
class FactionsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-factions';
	private int $game_id;
	private int $storyteller_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Factions' ] );

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );
	}

	private function send( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}{$route}" );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function make_player(): array {
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player_id, 'player' );
		$character_id = (int) Character::create( [
			'name' => 'A Character', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $player_id, 'created_by' => $this->storyteller_id,
		] );
		return [ $player_id, $character_id ];
	}

	private function make_faction( array $overrides = [] ): int {
		return (int) Faction::create( array_merge( [
			'game_id' => $this->game_id, 'name' => 'The Camarilla', 'faction_type' => 'sect',
			'goals' => 'Preserve the Masquerade.', 'created_by' => $this->storyteller_id,
		], $overrides ) );
	}

	// -------------------------------------------------------------------------
	// Visibility: everyone who can see it reads name/type/description; goals
	// and the roster are member-or-manager only.
	// -------------------------------------------------------------------------

	public function test_a_plain_viewer_does_not_see_goals(): void {
		[ $player_id ] = $this->make_player();
		$this->make_faction( [ 'audience' => 'everyone' ] );

		wp_set_current_user( $player_id );
		$response = $this->send( 'GET', '/factions' );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'The Camarilla', $data[0]['name'] );
		$this->assertArrayNotHasKey( 'goals', $data[0] );
	}

	public function test_a_member_sees_goals(): void {
		[ $player_id, $character_id ] = $this->make_player();
		$faction_id = $this->make_faction( [ 'audience' => 'everyone' ] );
		Faction_Member::add( $faction_id, $character_id, $this->storyteller_id, true );

		wp_set_current_user( $player_id );
		$response = $this->send( 'GET', "/factions/{$faction_id}" );
		$data     = $response->get_data();

		$this->assertSame( 'Preserve the Masquerade.', $data['goals'] );
		$this->assertTrue( $data['is_member'] );
	}

	public function test_a_storytellers_only_faction_is_hidden_from_a_plain_viewer(): void {
		[ $player_id ] = $this->make_player();
		$this->make_faction();

		wp_set_current_user( $player_id );
		$response = $this->send( 'GET', '/factions' );

		$this->assertSame( [], $response->get_data() );
	}

	// -------------------------------------------------------------------------
	// The roster is member-or-manager only, even when the faction itself is visible.
	// -------------------------------------------------------------------------

	public function test_a_non_member_viewer_cannot_read_the_roster(): void {
		[ $player_id ] = $this->make_player();
		[ , $member_id ] = $this->make_player();
		$faction_id = $this->make_faction( [ 'audience' => 'everyone' ] );
		Faction_Member::add( $faction_id, $member_id, $this->storyteller_id, true );

		wp_set_current_user( $player_id );
		$response = $this->send( 'GET', "/factions/{$faction_id}/members" );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_member_reads_the_roster(): void {
		[ $player_id, $character_id ] = $this->make_player();
		$faction_id = $this->make_faction( [ 'audience' => 'everyone' ] );
		Faction_Member::add( $faction_id, $character_id, $this->storyteller_id, true );

		wp_set_current_user( $player_id );
		$response = $this->send( 'GET', "/factions/{$faction_id}/members" );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertTrue( $data[0]['is_leader'] );
	}

	// -------------------------------------------------------------------------
	// Leader self-service: add/remove, but never remove the last leader, and a
	// leader can never remove another leader.
	// -------------------------------------------------------------------------

	public function test_a_leader_can_add_a_member(): void {
		[ $leader_id, $leader_character_id ] = $this->make_player();
		[ , $candidate_id ] = $this->make_player();
		$faction_id = $this->make_faction( [ 'audience' => 'everyone' ] );
		Faction_Member::add( $faction_id, $leader_character_id, $this->storyteller_id, true );

		wp_set_current_user( $leader_id );
		$response = $this->send( 'POST', "/factions/{$faction_id}/members", [ 'character_id' => $candidate_id ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertCount( 2, Faction_Member::for_faction( $faction_id ) );
	}

	public function test_a_leader_cannot_remove_another_leader(): void {
		[ $leader_id, $leader_character_id ] = $this->make_player();
		[ , $other_leader_id ] = $this->make_player();
		$faction_id = $this->make_faction( [ 'audience' => 'everyone' ] );
		Faction_Member::add( $faction_id, $leader_character_id, $this->storyteller_id, true );
		Faction_Member::add( $faction_id, $other_leader_id, $this->storyteller_id, true );

		wp_set_current_user( $leader_id );
		$response = $this->send( 'DELETE', "/factions/{$faction_id}/members/{$other_leader_id}" );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_manager_can_remove_a_leader_even_when_it_is_the_last_one(): void {
		[ , $leader_character_id ] = $this->make_player();
		$faction_id = $this->make_faction( [ 'audience' => 'everyone' ] );
		Faction_Member::add( $faction_id, $leader_character_id, $this->storyteller_id, true );

		wp_set_current_user( $this->storyteller_id );
		$response = $this->send( 'DELETE', "/factions/{$faction_id}/members/{$leader_character_id}" );

		// Even a manager can't leave a faction leaderless via this route (the
		// model-layer backstop in Faction_Member::remove()); they'd promote a
		// replacement leader first, or delete the faction outright.
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_non_leader_member_cannot_add_or_remove(): void {
		[ $member_id, $member_character_id ] = $this->make_player();
		[ , $leader_character_id ] = $this->make_player();
		[ , $candidate_id ] = $this->make_player();
		$faction_id = $this->make_faction( [ 'audience' => 'everyone' ] );
		Faction_Member::add( $faction_id, $leader_character_id, $this->storyteller_id, true );
		Faction_Member::add( $faction_id, $member_character_id, $this->storyteller_id, false );

		wp_set_current_user( $member_id );
		$response = $this->send( 'POST', "/factions/{$faction_id}/members", [ 'character_id' => $candidate_id ] );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// A plain player cannot create, update, or delete a faction directly.
	// -------------------------------------------------------------------------

	public function test_a_player_cannot_create_a_faction(): void {
		[ $player_id ] = $this->make_player();

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', '/factions', [ 'name' => 'Nope', 'faction_type' => 'sect' ] );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Deleting a faction unlinks its positions rather than leaving a dangling row.
	// -------------------------------------------------------------------------

	public function test_deleting_a_faction_unlinks_its_positions(): void {
		wp_set_current_user( $this->storyteller_id );
		$faction_id  = $this->make_faction();
		$position_id = $this->send( 'POST', '/positions', [ 'title' => 'Prince', 'faction_id' => $faction_id ] )
			->get_data()['id'];

		$this->send( 'DELETE', "/factions/{$faction_id}" );

		$position = $this->send( 'GET', '/positions' )->get_data();
		$found    = current( array_filter( $position, static fn( $p ) => $p['id'] === $position_id ) );
		$this->assertNull( $found['faction_id'] );
	}
}
