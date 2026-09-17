<?php

namespace BeyondElysium\Tests\Workflow;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Faction;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 §7 trace 5: a player proposes a coterie; an HST approves it from the Approval
 * Queue; the proposer is its leader and adds two members by name; a Storyteller makes a
 * haven Restricted to "Faction contains" the coterie; members see it and everyone else
 * gets a 404; a member removed by the leader loses it on the next request.
 */
class CoterieWorkflowTest extends WP_UnitTestCase {

	private string $slug = 'coterie-workflow';

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function make_player( int $game_id, string $name ): array {
		$player_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $player_id, 'player' );
		$character_id = (int) Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $player_id, 'created_by' => $player_id,
		] );
		return [ $player_id, $character_id ];
	}

	public function test_a_coterie_gates_a_haven_to_its_own_members(): void {
		do_action( 'rest_api_init' );

		$game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Coterie Workflow' ] );
		$hst_id  = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $hst_id, 'hst' );

		[ $leader_id, $leader_character_id ] = $this->make_player( $game_id, 'The Founder' );
		[ , $second_character_id ]           = $this->make_player( $game_id, 'Second Member' );
		[ , $third_character_id ]            = $this->make_player( $game_id, 'Third Member' );
		[ $outsider_id ]                     = $this->make_player( $game_id, 'An Outsider' );

		// The player proposes a coterie for their own character.
		wp_set_current_user( $leader_id );
		$proposed = $this->dispatch( 'POST', "/be/v1/{$this->slug}/characters/{$leader_character_id}/changes", [
			'change_type' => 'propose_faction',
			'category'    => 'faction',
			'change_data' => [ 'faction_type' => 'coterie', 'name' => 'Coterie of Thorns', 'goals' => 'Survive the week.' ],
		] );
		$this->assertSame( 201, $proposed->get_status() );
		$this->assertSame( 'pending', $proposed->get_data()->status );
		$change_id = (int) $proposed->get_data()->id;

		// An HST approves it from the Approval Queue.
		wp_set_current_user( $hst_id );
		$approved = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/changes/{$change_id}", [ 'status' => 'approved' ] );
		$this->assertSame( 200, $approved->get_status() );
		$this->assertSame( 'approved', (string) Change::find( $change_id )->status );

		$factions = Faction::for_game( $game_id );
		$this->assertCount( 1, $factions );
		$faction_id = (int) $factions[0]->id;
		$this->assertSame( 'Coterie of Thorns', $factions[0]->name );

		// The proposer is its leader and adds two members by name.
		wp_set_current_user( $leader_id );
		$members_route = "/be/v1/{$this->slug}/factions/{$faction_id}/members";
		$add_second     = $this->dispatch( 'POST', $members_route, [ 'character_id' => $second_character_id ] );
		$add_third      = $this->dispatch( 'POST', $members_route, [ 'character_id' => $third_character_id ] );
		$this->assertSame( 201, $add_second->get_status() );
		$this->assertSame( 201, $add_third->get_status() );

		$roster = $this->dispatch( 'GET', $members_route )->get_data();
		$this->assertCount( 3, $roster );

		// A Storyteller makes a haven Restricted to "Faction contains" the coterie.
		wp_set_current_user( $hst_id );
		$haven = $this->dispatch( 'POST', "/be/v1/{$this->slug}/world-objects", [
			'object_type'     => 'location',
			'name'            => 'Thorn Haven',
			'audience'        => 'restricted',
			'audience_rules'  => [
				'logic'      => 'AND',
				'conditions' => [ [ 'field' => 'group', 'operator' => 'contains', 'find' => 'Coterie of Thorns' ] ],
			],
		] );
		$this->assertSame( 201, $haven->get_status() );
		$haven_id = (int) $haven->get_data()->id;

		// Members see it; the outsider gets a 404.
		wp_set_current_user( $leader_id );
		$this->assertSame( 200, $this->dispatch( 'GET', "/be/v1/{$this->slug}/world-objects/{$haven_id}" )->get_status() );

		$second_wp_id = (int) Character::find( $second_character_id )->wp_user_id;
		wp_set_current_user( $second_wp_id );
		$this->assertSame( 200, $this->dispatch( 'GET', "/be/v1/{$this->slug}/world-objects/{$haven_id}" )->get_status() );

		$third_wp_id = (int) Character::find( $third_character_id )->wp_user_id;
		wp_set_current_user( $third_wp_id );
		$this->assertSame( 200, $this->dispatch( 'GET', "/be/v1/{$this->slug}/world-objects/{$haven_id}" )->get_status() );

		wp_set_current_user( $outsider_id );
		$this->assertSame( 404, $this->dispatch( 'GET', "/be/v1/{$this->slug}/world-objects/{$haven_id}" )->get_status() );

		// A member removed by the leader loses it on the next request.
		wp_set_current_user( $leader_id );
		$removed = $this->dispatch( 'DELETE', "{$members_route}/{$third_character_id}" );
		$this->assertSame( 204, $removed->get_status() );

		wp_set_current_user( $third_wp_id );
		$this->assertSame( 404, $this->dispatch( 'GET', "/be/v1/{$this->slug}/world-objects/{$haven_id}" )->get_status() );

		// The remaining two still see it - removal was scoped to the one member.
		wp_set_current_user( $second_wp_id );
		$this->assertSame( 200, $this->dispatch( 'GET', "/be/v1/{$this->slug}/world-objects/{$haven_id}" )->get_status() );
	}
}
