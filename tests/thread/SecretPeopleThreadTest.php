<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The people a player may name as the one who told them something: the Who's Who a player can see plus the
 * characters on the far end of a connection from one of their own. A character is never its own teller.
 */
class SecretPeopleThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-secret-people';
	private int $game_id;
	private int $storyteller_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id        = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Secret People' ] );
		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );
	}

	private function send( string $method, string $route, array $body = [], ?string $slug = null ) {
		$request = new WP_REST_Request( $method, '/be/v1/' . ( $slug ?? $this->slug ) . $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @return array{0:int,1:int} The player's user id and their character id.
	 */
	private function make_player( string $name ): array {
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player_id, 'player' );
		wp_set_current_user( $this->storyteller_id );
		return [ $player_id, $this->make_character( $name, [ 'wp_user_id' => $player_id ] ) ];
	}

	private function make_character( string $name, array $extra = [], ?string $slug = null ): int {
		wp_set_current_user( $this->storyteller_id );
		return (int) Character::create( array_merge( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $slug ?? $this->slug, 'created_by' => $this->storyteller_id,
		], $extra ) );
	}

	private function make_public( int $character_id ): void {
		Character::update_header( $character_id, [ 'profile_audience' => 'everyone' ] );
	}

	private function connect( int $source_id, int $target_id, string $target_type = 'character' ): void {
		wp_set_current_user( $this->storyteller_id );
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => $source_id,
			'target_type' => $target_type, 'target_id' => $target_id, 'label' => 'Knows',
			'created_by' => $this->storyteller_id,
		] );
	}

	/**
	 * @return array<int,array<string,mixed>> The people the route returns, keyed by name.
	 */
	private function people_for( int $user_id ): array {
		wp_set_current_user( $user_id );
		$response = $this->send( 'GET', '/my/secrets/people' );
		$this->assertSame( 200, $response->get_status() );
		$by_name = [];
		foreach ( $response->get_data() as $person ) {
			$by_name[ $person['name'] ] = $person;
		}
		return $by_name;
	}

	public function test_a_character_in_whos_who_is_offered_with_its_kind(): void {
		[ $player_id ] = $this->make_player( 'Asker' );
		$public_id     = $this->make_character( 'Public Pat' );
		$this->make_public( $public_id );
		$npc_id = $this->make_character( 'Public Npc', [ 'is_npc' => 1 ] );
		$this->make_public( $npc_id );

		$people = $this->people_for( $player_id );

		$this->assertSame( $public_id, $people['Public Pat']['id'] );
		$this->assertSame( 'pc', $people['Public Pat']['kind'] );
		$this->assertSame( $npc_id, $people['Public Npc']['id'] );
		$this->assertSame( 'npc', $people['Public Npc']['kind'] );
	}

	public function test_a_character_hidden_from_whos_who_and_unconnected_is_not_offered(): void {
		[ $player_id ] = $this->make_player( 'Asker' );
		$this->make_character( 'Hidden Hank' );
		$this->make_character( 'Hidden Npc', [ 'is_npc' => 1 ] );

		$this->assertSame( [], $this->people_for( $player_id ) );
	}

	public function test_a_hidden_character_one_of_the_players_characters_is_connected_to_is_offered(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		$hidden_id              = $this->make_character( 'Hidden Mentor', [ 'is_npc' => 1 ] );
		$this->connect( $own_id, $hidden_id );

		$people = $this->people_for( $player_id );

		$this->assertSame( $hidden_id, $people['Hidden Mentor']['id'] );
		$this->assertSame( 'npc', $people['Hidden Mentor']['kind'] );
	}

	public function test_a_connection_pointing_at_the_players_character_counts_too(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		$sire_id                = $this->make_character( 'Hidden Sire' );
		$this->connect( $sire_id, $own_id );

		$this->assertArrayHasKey( 'Hidden Sire', $this->people_for( $player_id ) );
	}

	public function test_a_connection_to_something_that_is_not_a_character_adds_nobody(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		wp_set_current_user( $this->storyteller_id );
		$plot_id = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'A Plot', 'created_by' => $this->storyteller_id, 'audience' => 'everyone' ] );
		$this->connect( $own_id, $plot_id, 'plot' );

		$this->assertSame( [], $this->people_for( $player_id ) );
	}

	public function test_a_connection_to_a_plot_never_reads_the_plot_id_as_a_character(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		$hidden_id              = $this->make_character( 'Hidden Hank' );
		$this->connect( $own_id, $hidden_id, 'plot' );

		$this->assertSame( [], $this->people_for( $player_id ) );
	}

	public function test_other_peoples_connections_are_not_offered(): void {
		[ $player_id ]      = $this->make_player( 'Asker' );
		$first_id           = $this->make_character( 'First Stranger' );
		$second_id          = $this->make_character( 'Second Stranger' );
		$this->connect( $first_id, $second_id );

		$this->assertSame( [], $this->people_for( $player_id ) );
	}

	public function test_the_players_own_characters_in_whos_who_are_offered(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		$this->make_public( $own_id );
		$second_own_id = $this->make_character( 'Asker Two', [ 'wp_user_id' => $player_id ] );
		$this->make_public( $second_own_id );

		$people = $this->people_for( $player_id );

		$this->assertSame( $own_id, $people['Asker']['id'] );
		$this->assertSame( $second_own_id, $people['Asker Two']['id'] );
	}

	public function test_a_hidden_character_of_the_players_own_that_nothing_connects_is_not_offered(): void {
		[ $player_id ] = $this->make_player( 'Asker' );

		$this->assertSame( [], $this->people_for( $player_id ) );
	}

	public function test_another_of_the_players_own_characters_is_offered_through_a_connection(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		$second_own_id          = $this->make_character( 'Asker Two', [ 'wp_user_id' => $player_id ] );
		$this->connect( $own_id, $second_own_id );

		$people = $this->people_for( $player_id );

		$this->assertArrayHasKey( 'Asker Two', $people );
		$this->assertSame( $second_own_id, $people['Asker Two']['id'] );
	}

	public function test_a_retired_character_is_not_offered(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		$retired_id             = $this->make_character( 'Retired Rita', [ 'status' => 'retired' ] );
		$this->make_public( $retired_id );
		$this->connect( $own_id, $retired_id );

		$this->assertArrayNotHasKey( 'Retired Rita', $this->people_for( $player_id ) );
	}

	public function test_a_character_in_another_chronicle_is_not_offered(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		wp_set_current_user( $this->storyteller_id );
		Game::create( [ 'slug' => 'thread-secret-people-other', 'name' => 'Other' ] );
		$foreign_id = $this->make_character( 'Foreign Fay', [], 'thread-secret-people-other' );
		$this->make_public( $foreign_id );
		$this->connect( $own_id, $foreign_id );

		$this->assertArrayNotHasKey( 'Foreign Fay', $this->people_for( $player_id ) );
	}

	public function test_the_display_name_is_the_public_name_when_there_is_one(): void {
		[ $player_id ] = $this->make_player( 'Asker' );
		$id            = $this->make_character( 'Real Name' );
		Character::update_header( $id, [ 'profile_audience' => 'everyone', 'public_name' => 'The Alias' ] );

		$people = $this->people_for( $player_id );

		$this->assertArrayHasKey( 'The Alias', $people );
		$this->assertArrayNotHasKey( 'Real Name', $people );
	}

	public function test_the_list_is_sorted_by_name(): void {
		[ $player_id ] = $this->make_player( 'Asker' );
		foreach ( [ 'Zed', 'amy', 'Bob' ] as $name ) {
			$this->make_public( $this->make_character( $name ) );
		}

		$this->assertSame( [ 'amy', 'Bob', 'Zed' ], array_keys( $this->people_for( $player_id ) ) );
	}

	public function test_a_storyteller_is_offered_every_active_character(): void {
		$this->make_character( 'Hidden Hank' );
		$this->make_character( 'Hidden Npc', [ 'is_npc' => 1 ] );
		$this->make_character( 'Staff Own', [ 'wp_user_id' => $this->storyteller_id ] );

		$people = $this->people_for( $this->storyteller_id );

		$this->assertArrayHasKey( 'Hidden Hank', $people );
		$this->assertArrayHasKey( 'Hidden Npc', $people );
		$this->assertArrayHasKey( 'Staff Own', $people );
	}

	public function test_a_logged_out_visitor_is_refused(): void {
		wp_set_current_user( 0 );
		$response = $this->send( 'GET', '/my/secrets/people' );
		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	// -------------------------------------------------------------------------
	// Logging with a named teller
	// -------------------------------------------------------------------------

	private function log_as( int $user_id, int $character_id, array $extra ) {
		wp_set_current_user( $user_id );
		return $this->send( 'POST', '/my/secrets/log', array_merge( [
			'character_id' => $character_id, 'title' => 'A rumor', 'details' => 'Heard it somewhere.', 'how' => 'told',
		], $extra ) );
	}

	public function test_a_listed_person_can_be_named_as_the_teller(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		$public_id              = $this->make_character( 'Public Pat' );
		$this->make_public( $public_id );

		$response = $this->log_as( $player_id, $own_id, [ 'teller_character_id' => $public_id ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $public_id, (int) $response->get_data()->change_data['teller_character_id'] );
	}

	public function test_a_connected_but_hidden_person_can_be_named_as_the_teller(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		$hidden_id              = $this->make_character( 'Hidden Mentor' );
		$this->connect( $own_id, $hidden_id );

		$response = $this->log_as( $player_id, $own_id, [ 'teller_character_id' => $hidden_id ] );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_a_hidden_unconnected_character_cannot_be_named_by_id(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		$hidden_id              = $this->make_character( 'Hidden Hank' );

		$response = $this->log_as( $player_id, $own_id, [ 'teller_character_id' => $hidden_id ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_a_character_from_another_chronicle_cannot_be_named_by_id(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		wp_set_current_user( $this->storyteller_id );
		Game::create( [ 'slug' => 'thread-secret-people-other', 'name' => 'Other' ] );
		$foreign_id = $this->make_character( 'Foreign Fay', [], 'thread-secret-people-other' );
		$this->make_public( $foreign_id );

		$response = $this->log_as( $player_id, $own_id, [ 'teller_character_id' => $foreign_id ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_another_of_the_players_own_characters_can_be_the_teller(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		$second_own_id          = $this->make_character( 'Asker Two', [ 'wp_user_id' => $player_id ] );
		$this->make_public( $second_own_id );

		$response = $this->log_as( $player_id, $own_id, [ 'teller_character_id' => $second_own_id ] );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_a_character_cannot_be_named_as_its_own_teller(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );
		$this->make_public( $own_id );

		$response = $this->log_as( $player_id, $own_id, [ 'teller_character_id' => $own_id ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_a_typed_name_is_still_accepted(): void {
		[ $player_id, $own_id ] = $this->make_player( 'Asker' );

		$response = $this->log_as( $player_id, $own_id, [ 'teller_name' => 'A stranger at the bar' ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'A stranger at the bar', $response->get_data()->change_data['teller_name'] );
	}
}
