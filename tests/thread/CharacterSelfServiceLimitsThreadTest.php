<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-033: a player's own-character update route accepted `status`, `narrator`,
 * and `rp_notes`. A pending new character made itself active - undoing the chronicle's
 * "Require new character approval" - a dead one came back, and a player could overwrite the
 * Storyteller's private roleplaying notes they are never shown. Header text was also stored
 * unsanitized on update although create sanitized it.
 */
class CharacterSelfServiceLimitsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-self-service';
	private int $player;
	private int $storyteller;
	private int $dead_character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug,
			'settings' => wp_json_encode( [ 'require_new_character_approval' => true ] ),
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) Game::find_by_slug( $this->slug )->id;

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->player, 'player' );
		$this->storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $this->storyteller, 'hst' );

		$this->dead_character = Character::create( [
			'name' => 'Fallen', 'stack_slug' => 'vampire', 'status' => 'dead',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
			'rp_notes' => 'Storyteller secret', 'narrator' => 'Assigned Narrator',
		] );
	}

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_pending_new_character_cannot_activate_itself(): void {
		wp_set_current_user( $this->player );
		$created = $this->dispatch( 'POST', "/be/v1/{$this->slug}/characters", [ 'name' => 'Newcomer', 'stack_slug' => 'vampire' ] );
		$this->assertSame( 201, $created->get_status() );
		$id = (int) $created->get_data()->id;
		$this->assertSame( 'pending', Character::find( $id )->status );

		$this->dispatch( 'PUT', "/be/v1/{$this->slug}/characters/{$id}", [ 'status' => 'active' ] );

		$this->assertSame( 'pending', Character::find( $id )->status );
	}

	public function test_a_player_cannot_bring_a_dead_character_back(): void {
		wp_set_current_user( $this->player );

		$this->dispatch( 'PUT', "/be/v1/{$this->slug}/characters/{$this->dead_character}", [ 'status' => 'active' ] );

		$this->assertSame( 'dead', Character::find( $this->dead_character )->status );
	}

	public function test_a_player_cannot_overwrite_storyteller_notes_or_the_narrator(): void {
		wp_set_current_user( $this->player );

		$this->dispatch( 'PUT', "/be/v1/{$this->slug}/characters/{$this->dead_character}", [
			'rp_notes' => '',
			'narrator' => 'Myself',
		] );

		$character = Character::find( $this->dead_character );
		$this->assertSame( 'Storyteller secret', $character->rp_notes );
		$this->assertSame( 'Assigned Narrator', $character->narrator );
	}

	public function test_a_player_still_renames_their_character_with_the_name_sanitized(): void {
		wp_set_current_user( $this->player );

		$this->dispatch( 'PUT', "/be/v1/{$this->slug}/characters/{$this->dead_character}", [ 'name' => '<b>Risen</b> Again' ] );

		$this->assertSame( 'Risen Again', Character::find( $this->dead_character )->name );
	}

	public function test_a_storyteller_still_sets_status_and_notes(): void {
		wp_set_current_user( $this->storyteller );

		$this->dispatch( 'PUT', "/be/v1/{$this->slug}/characters/{$this->dead_character}", [
			'status'   => 'active',
			'rp_notes' => 'Updated secret',
		] );

		$character = Character::find( $this->dead_character );
		$this->assertSame( 'active', $character->status );
		$this->assertSame( 'Updated secret', $character->rp_notes );
	}
}
