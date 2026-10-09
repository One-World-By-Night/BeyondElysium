<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The character list route behind the Storyteller Toolkit's Characters tab and My Chronicle: a manager reads this
 * chronicle's characters and nobody else's, and a player reads only their own.
 */
class CharacterListScopeThreadTest extends WP_UnitTestCase {

	private string $slug_a = 'thread-list-a';
	private string $slug_b = 'thread-list-b';
	private int $storyteller_id;
	private int $narrator_id;
	private int $player_one_id;
	private int $player_two_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$game_a = (int) Game::create( [ 'slug' => $this->slug_a, 'name' => 'List A' ] );
		$game_b = (int) Game::create( [ 'slug' => $this->slug_b, 'name' => 'List B' ] );

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_a, $this->storyteller_id, 'hst' );

		$this->narrator_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_a, $this->narrator_id, 'narrator' );

		$this->player_one_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->player_two_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_a, $this->player_one_id, 'player' );
		Game_Member::set_role( $game_a, $this->player_two_id, 'player' );

		wp_set_current_user( $this->storyteller_id );
		$this->make( 'Alpha One', $this->slug_a, $this->player_one_id );
		$this->make( 'Alpha Two', $this->slug_a, $this->player_two_id );
		$this->make( 'Alpha Npc', $this->slug_a, null, true );
		$this->make( 'Beta One', $this->slug_b, $this->player_one_id );
	}

	private function make( string $name, string $slug, ?int $user_id, bool $npc = false, string $status = 'active', string $stack = 'vampire' ): int {
		return (int) Character::create( [
			'name' => $name, 'stack_slug' => $stack, 'owner_type' => 'chronicle', 'owner_slug' => $slug,
			'wp_user_id' => $user_id, 'is_npc' => $npc ? 1 : 0, 'status' => $status, 'created_by' => $this->storyteller_id,
		] );
	}

	/**
	 * @return array{0:int,1:string[]} The status and the names the list route returned.
	 */
	private function list_as( int $user_id, string $slug, array $params = [] ): array {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$slug}/characters" );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_get_server()->dispatch( $request );
		$names    = [];
		if ( $response->get_status() === 200 ) {
			foreach ( $response->get_data() as $row ) {
				$names[] = is_array( $row ) ? $row['name'] : $row->name;
			}
			sort( $names );
		}
		return [ $response->get_status(), $names ];
	}

	public function test_a_storyteller_lists_this_chronicles_player_characters_and_no_others(): void {
		[ $status, $names ] = $this->list_as( $this->storyteller_id, $this->slug_a );

		$this->assertSame( 200, $status );
		$this->assertSame( [ 'Alpha One', 'Alpha Two' ], $names );
	}

	public function test_a_storyteller_asking_for_npcs_gets_this_chronicles_npcs_only(): void {
		[ $status, $names ] = $this->list_as( $this->storyteller_id, $this->slug_a, [ 'is_npc' => 1 ] );

		$this->assertSame( 200, $status );
		$this->assertSame( [ 'Alpha Npc' ], $names );
	}

	public function test_a_player_lists_only_their_own_characters(): void {
		[ $status, $names ] = $this->list_as( $this->player_one_id, $this->slug_a );

		$this->assertSame( 200, $status );
		$this->assertSame( [ 'Alpha One' ], $names );
	}

	public function test_a_player_cannot_ask_for_another_players_characters(): void {
		[ , $names ] = $this->list_as( $this->player_one_id, $this->slug_a, [ 'wp_user_id' => $this->player_two_id ] );

		$this->assertSame( [ 'Alpha One' ], $names );
	}

	public function test_a_player_cannot_ask_for_npcs(): void {
		[ , $names ] = $this->list_as( $this->player_one_id, $this->slug_a, [ 'is_npc' => 1 ] );

		$this->assertNotContains( 'Alpha Npc', $names );
		$this->assertSame( [ 'Alpha One' ], $names );
	}

	public function test_a_narrator_lists_every_player_character_of_the_chronicle_but_no_npcs(): void {
		[ $status, $names ] = $this->list_as( $this->narrator_id, $this->slug_a, [ 'is_npc' => 1 ] );

		$this->assertSame( 200, $status );
		$this->assertSame( [ 'Alpha One', 'Alpha Two' ], $names );
	}

	public function test_search_matches_part_of_a_name_and_takes_wildcards_literally(): void {
		[ , $names ] = $this->list_as( $this->storyteller_id, $this->slug_a, [ 'search' => 'two' ] );
		$this->assertSame( [ 'Alpha Two' ], $names );

		[ , $wildcard ] = $this->list_as( $this->storyteller_id, $this->slug_a, [ 'search' => '%' ] );
		$this->assertSame( [], $wildcard );
	}

	public function test_the_status_filter_narrows_the_list(): void {
		wp_set_current_user( $this->storyteller_id );
		$this->make( 'Alpha Retired', $this->slug_a, $this->player_one_id, false, 'retired' );

		[ , $retired ] = $this->list_as( $this->storyteller_id, $this->slug_a, [ 'status' => 'retired' ] );
		$this->assertSame( [ 'Alpha Retired' ], $retired );

		[ , $active ] = $this->list_as( $this->storyteller_id, $this->slug_a, [ 'status' => 'active' ] );
		$this->assertSame( [ 'Alpha One', 'Alpha Two' ], $active );
	}

	public function test_the_creature_type_filter_narrows_the_list(): void {
		wp_set_current_user( $this->storyteller_id );
		$this->make( 'Alpha Mortal', $this->slug_a, $this->player_two_id, false, 'active', 'mortal' );

		[ , $mortals ] = $this->list_as( $this->storyteller_id, $this->slug_a, [ 'stack_slug' => 'mortal' ] );
		$this->assertSame( [ 'Alpha Mortal' ], $mortals );
	}

	public function test_a_player_keeps_the_filters_inside_their_own_characters(): void {
		wp_set_current_user( $this->storyteller_id );
		$this->make( 'Alpha Mortal', $this->slug_a, $this->player_two_id, false, 'active', 'mortal' );

		[ , $names ] = $this->list_as( $this->player_one_id, $this->slug_a, [ 'stack_slug' => 'mortal' ] );
		$this->assertSame( [], $names );
	}

	public function test_a_storyteller_of_one_chronicle_is_refused_the_list_of_another(): void {
		[ $status ] = $this->list_as( $this->storyteller_id, $this->slug_b );

		$this->assertSame( 403, $status );
	}
}
