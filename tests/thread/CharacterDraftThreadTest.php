<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Bot_Auth;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Character_Draft;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Join_Request;
use ReflectionMethod;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The unfinished build a person keeps on their account: theirs alone, one per chronicle, removed when they create the
 * character, delete their account or the chronicle goes.
 */
class CharacterDraftThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-character-draft';
	private int $game_id;
	private int $hst;
	private int $player;
	private int $other_player;
	private int $outsider;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Drafted by Night' ] );
		$this->hst     = $this->person( 'hst', 'editor' );
		$this->player  = $this->person( 'player', 'subscriber' );
		$this->other_player = $this->person( 'player', 'subscriber' );
		$this->outsider     = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	private function person( string $role, string $wp_role ): int {
		$user = self::factory()->user->create( [ 'role' => $wp_role ] );
		Game_Member::set_role( $this->game_id, $user, $role );
		return $user;
	}

	private function send( int $as, string $method, array $body = [], ?string $slug = null ): \WP_REST_Response {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, '/be/v1/' . ( $slug ?? $this->slug ) . '/my/character-draft' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $method !== 'GET' ) {
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function draft( string $name = 'Isolde' ): array {
		return [
			'name'       => $name,
			'stack_slug' => 'vampire',
			'step'       => 'abilities',
			'sheet_data' => [ 'vampire-identity' => [ 'Clan' => 'Tremere' ], 'vampire-abilities' => [ [ 'name' => 'Occult' ] ] ],
			'biography'  => '<p>Born in Vienna.</p>',
		];
	}

	public function test_there_is_no_draft_until_one_is_saved(): void {
		$response = $this->send( $this->player, 'GET' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['draft'] );
		$this->assertNull( $response->get_data()['updated_at'] );
	}

	public function test_an_empty_sheet_reads_back_as_an_object(): void {
		$this->send( $this->player, 'PUT', [ 'draft' => [ 'name' => 'Blank', 'stack_slug' => 'vampire', 'sheet_data' => [] ] ] );

		$read = $this->send( $this->player, 'GET' )->get_data();

		$this->assertStringContainsString( '"sheet_data":{}', (string) wp_json_encode( $read ) );
	}

	public function test_a_person_saves_and_reads_their_own_draft(): void {
		$saved = $this->send( $this->player, 'PUT', [ 'draft' => $this->draft() ] );
		$this->assertSame( 200, $saved->get_status(), wp_json_encode( $saved->get_data() ) );

		$read = $this->send( $this->player, 'GET' )->get_data();
		$this->assertSame( 'Isolde', $read['draft']['name'] );
		$this->assertSame( 'vampire', $read['draft']['stack_slug'] );
		$this->assertSame( 'abilities', $read['draft']['step'] );
		$this->assertSame( 'Tremere', $read['draft']['sheet_data']['vampire-identity']['Clan'] );
		$this->assertNotEmpty( $read['updated_at'] );
	}

	public function test_nobody_reads_or_overwrites_anyone_elses_draft(): void {
		$this->send( $this->player, 'PUT', [ 'draft' => $this->draft( 'Mine' ) ] );

		$this->assertNull( $this->send( $this->other_player, 'GET' )->get_data()['draft'], 'Another player sees none.' );
		$this->assertNull( $this->send( $this->hst, 'GET' )->get_data()['draft'], 'Staff see none.' );

		$this->send( $this->other_player, 'PUT', [ 'draft' => $this->draft( 'Theirs' ) ] );
		$this->assertSame( 'Mine', $this->send( $this->player, 'GET' )->get_data()['draft']['name'] );
		$this->assertSame( 'Theirs', $this->send( $this->other_player, 'GET' )->get_data()['draft']['name'] );

		$this->send( $this->other_player, 'DELETE' );
		$this->assertSame( 'Mine', $this->send( $this->player, 'GET' )->get_data()['draft']['name'], 'Deleting one draft leaves the other.' );
	}

	public function test_a_second_save_replaces_the_first_so_there_is_one_per_chronicle(): void {
		$this->send( $this->player, 'PUT', [ 'draft' => $this->draft( 'First' ) ] );
		$this->send( $this->player, 'PUT', [ 'draft' => $this->draft( 'Second' ) ] );

		$this->assertSame( 'Second', $this->send( $this->player, 'GET' )->get_data()['draft']['name'] );
		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'be_character_drafts WHERE game_id = %d AND wp_user_id = %d', $this->game_id, $this->player ) ) );
	}

	public function test_each_chronicle_keeps_its_own_draft_for_the_same_person(): void {
		$other = (int) Game::create( [ 'slug' => 'thread-character-draft-two', 'name' => 'Two' ] );
		Game_Member::set_role( $other, $this->player, 'player' );

		$this->send( $this->player, 'PUT', [ 'draft' => $this->draft( 'Here' ) ] );
		$this->send( $this->player, 'PUT', [ 'draft' => $this->draft( 'There' ) ], 'thread-character-draft-two' );

		$this->assertSame( 'Here', $this->send( $this->player, 'GET' )->get_data()['draft']['name'] );
		$this->assertSame( 'There', $this->send( $this->player, 'GET', [], 'thread-character-draft-two' )->get_data()['draft']['name'] );
	}

	public function test_a_creature_type_the_person_cannot_pick_is_refused(): void {
		$response = $this->send( $this->player, 'PUT', [ 'draft' => [ 'name' => 'Odd', 'stack_slug' => 'various' ] ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNull( $this->send( $this->player, 'GET' )->get_data()['draft'] );
	}

	public function test_a_draft_over_the_size_limit_is_refused(): void {
		$huge = $this->draft();
		$huge['biography'] = str_repeat( 'x', Character_Draft::MAX_BYTES + 10 );

		$response = $this->send( $this->player, 'PUT', [ 'draft' => $huge ] );

		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'draft_too_large', $response->as_error()->get_error_code() );
	}

	public function test_a_draft_that_is_not_an_object_is_refused_and_unknown_keys_are_dropped(): void {
		$this->assertSame( 400, $this->send( $this->player, 'PUT', [ 'draft' => 'text' ] )->get_status() );

		$this->send( $this->player, 'PUT', [ 'draft' => $this->draft() + [ 'is_admin' => true, 'status' => 'active' ] ] );
		$read = $this->send( $this->player, 'GET' )->get_data()['draft'];
		$this->assertArrayNotHasKey( 'is_admin', $read );
		$this->assertArrayNotHasKey( 'status', $read );
	}

	public function test_a_non_member_keeps_a_draft_where_join_requests_are_on(): void {
		$saved = $this->send( $this->outsider, 'PUT', [ 'draft' => $this->draft( 'Newcomer' ) ] );

		$this->assertSame( 200, $saved->get_status(), wp_json_encode( $saved->get_data() ) );
		$this->assertSame( 'Newcomer', $this->send( $this->outsider, 'GET' )->get_data()['draft']['name'] );
	}

	public function test_a_non_member_is_refused_where_join_requests_are_off(): void {
		Game::update( $this->slug, [ 'settings' => [ 'join_requests' => false ] ] );

		foreach ( [ 'GET', 'PUT' ] as $method ) {
			$response = $this->send( $this->outsider, $method, [ 'draft' => $this->draft() ] );
			$this->assertSame( 403, $response->get_status(), $method );
			$this->assertSame( 'join_requests_off', $response->as_error()->get_error_code(), $method );
		}
	}

	public function test_a_non_member_with_a_character_already_waiting_is_refused(): void {
		$id = (int) Join_Request::open( $this->game_id, $this->outsider, 'Hello' );
		Join_Request::tie_character( $id, 999999 );

		$response = $this->send( $this->outsider, 'PUT', [ 'draft' => $this->draft() ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'join_already_requested', $response->as_error()->get_error_code() );
	}

	public function test_creating_the_character_removes_the_draft(): void {
		$this->send( $this->player, 'PUT', [ 'draft' => $this->draft() ] );

		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'name' => 'Isolde', 'stack_slug' => 'vampire' ] ) );
		$created = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$this->assertNull( $this->send( $this->player, 'GET' )->get_data()['draft'] );
	}

	public function test_a_storytellers_npc_does_not_remove_their_own_draft(): void {
		$this->send( $this->hst, 'PUT', [ 'draft' => $this->draft( 'Mine' ) ] );

		wp_set_current_user( $this->hst );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'name' => 'A Barfly', 'stack_slug' => 'vampire', 'is_npc' => true ] ) );
		rest_get_server()->dispatch( $request );

		$this->assertSame( 'Mine', $this->send( $this->hst, 'GET' )->get_data()['draft']['name'] );
	}

	public function test_discarding_removes_it(): void {
		$this->send( $this->player, 'PUT', [ 'draft' => $this->draft() ] );

		$this->assertSame( 200, $this->send( $this->player, 'DELETE' )->get_status() );
		$this->assertNull( $this->send( $this->player, 'GET' )->get_data()['draft'] );
	}

	public function test_deleting_the_account_removes_its_drafts_in_every_chronicle(): void {
		$other = (int) Game::create( [ 'slug' => 'thread-character-draft-gone', 'name' => 'Gone' ] );
		Character_Draft::save( $this->game_id, $this->player, $this->draft() );
		Character_Draft::save( $other, $this->player, $this->draft() );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->player );

		$this->assertNull( Character_Draft::find( $this->game_id, $this->player ) );
		$this->assertNull( Character_Draft::find( $other, $this->player ) );
	}

	public function test_deleting_the_chronicle_removes_its_drafts_and_counts_them(): void {
		Character_Draft::save( $this->game_id, $this->player, $this->draft() );
		$game = Game::find_by_slug( $this->slug );

		$this->assertSame( 1, Game::content_counts( $game )['character_drafts'] );
		$this->assertTrue( Game::delete_with_content( $this->slug ) );
		$this->assertNull( Character_Draft::find( $this->game_id, $this->player ) );
	}

	public function test_revenant_has_no_route_to_the_draft(): void {
		$match = new ReflectionMethod( Bot_Auth::class, 'match_route' );

		foreach ( [ 'GET', 'PUT', 'DELETE', 'POST' ] as $method ) {
			$this->assertNull( $match->invoke( null, $method, "/be/v1/{$this->slug}/my/character-draft" ), $method );
		}
	}

	public function test_a_character_the_draft_became_never_carries_it(): void {
		Character_Draft::save( $this->game_id, $this->player, $this->draft( 'Secret Plan' ) );
		$id = (int) Character::create( [
			'name' => 'Isolde', 'stack_slug' => 'vampire', 'owner_slug' => $this->slug,
			'wp_user_id' => $this->player, 'status' => 'active', 'created_by' => 1,
		] );

		wp_set_current_user( $this->hst );
		$sheet = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$id}" ) );

		$this->assertStringNotContainsString( 'Secret Plan', (string) wp_json_encode( $sheet->get_data() ) );
	}
}
