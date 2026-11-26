<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Bot_Auth;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Notice;
use BeyondElysium\Models\Player_Note;
use ReflectionMethod;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A chronicle's private notes about its players: the HST and AST read and write them, nobody else does, and they go
 * with the person's account or the chronicle and with nothing else.
 */
class PlayerNotesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-player-notes';
	private int $game_id;
	private int $hst;
	private int $ast;
	private int $narrator;
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id  = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Player Notes' ] );
		$this->hst      = $this->member( 'hst', 'editor', 'Hilda Storyteller' );
		$this->ast      = $this->member( 'ast', 'editor', 'Ada Assistant' );
		$this->narrator = $this->member( 'narrator', 'editor', 'Nora Narrator' );
		$this->player   = $this->member( 'player', 'subscriber', 'Pia Player' );
	}

	private function member( string $role, string $wp_role, string $name ): int {
		$user = self::factory()->user->create( [ 'role' => $wp_role, 'display_name' => $name ] );
		Game_Member::set_role( $this->game_id, $user, $role );
		return $user;
	}

	private function call( int $as, string $method, string $route, array $params = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}/players{$route}" );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function note_url(): string {
		return "/{$this->player}/notes";
	}

	// -- who may ------------------------------------------------------------------------------

	public function test_the_hst_and_the_ast_read_and_write_a_note(): void {
		foreach ( [ $this->hst, $this->ast ] as $person ) {
			$this->assertSame( 200, $this->call( $person, 'PUT', $this->note_url(), [ 'notes' => "By {$person}." ] )->get_status() );
			$this->assertSame( "By {$person}.", $this->call( $person, 'GET', $this->note_url() )->get_data()['notes'] );
		}
	}

	public function test_nobody_else_touches_a_note_not_even_the_player_it_is_about(): void {
		Player_Note::save( $this->game_id, $this->player, 'Secret view of Pia.', $this->hst );

		foreach ( [ $this->narrator, $this->player ] as $person ) {
			foreach ( [ 'GET', 'PUT', 'DELETE' ] as $method ) {
				$this->assertSame( 403, $this->call( $person, $method, $this->note_url(), [ 'notes' => 'x' ] )->get_status(), "{$method} as {$person}" );
			}
			$this->assertSame( 403, $this->call( $person, 'GET', '/former-notes' )->get_status() );
		}
		$outsider = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->assertSame( 403, $this->call( $outsider, 'GET', $this->note_url() )->get_status() );
	}

	// -- saving -------------------------------------------------------------------------------

	public function test_a_note_says_who_changed_it_and_when(): void {
		$this->call( $this->ast, 'PUT', $this->note_url(), [ 'notes' => 'Prefers late games.' ] );

		$data = $this->call( $this->hst, 'GET', $this->note_url() )->get_data();

		$this->assertSame( 'Prefers late games.', $data['notes'] );
		$this->assertSame( 'Ada Assistant', $data['updated_by'] );
		$this->assertNotNull( $data['updated_at'] );
	}

	public function test_a_second_save_replaces_the_first_and_an_empty_one_removes_it(): void {
		$this->call( $this->hst, 'PUT', $this->note_url(), [ 'notes' => 'First.' ] );
		$this->call( $this->hst, 'PUT', $this->note_url(), [ 'notes' => 'Second.' ] );
		$this->assertSame( 'Second.', $this->call( $this->hst, 'GET', $this->note_url() )->get_data()['notes'] );

		$this->call( $this->hst, 'PUT', $this->note_url(), [ 'notes' => '   ' ] );

		$this->assertNull( Player_Note::find( $this->game_id, $this->player ) );
		$this->assertSame( '', $this->call( $this->hst, 'GET', $this->note_url() )->get_data()['notes'] );
	}

	public function test_a_note_is_plain_text_and_holds_ten_thousand_characters(): void {
		$this->call( $this->hst, 'PUT', $this->note_url(), [ 'notes' => '<b>bold</b> <script>x()</script>' ] );
		$stored = $this->call( $this->hst, 'GET', $this->note_url() )->get_data()['notes'];
		$this->assertStringNotContainsString( '<', $stored );

		$this->assertSame( 200, $this->call( $this->hst, 'PUT', $this->note_url(), [ 'notes' => str_repeat( 'a', 10000 ) ] )->get_status() );
		$long = $this->call( $this->hst, 'PUT', $this->note_url(), [ 'notes' => str_repeat( 'a', 10001 ) ] );
		$this->assertSame( 400, $long->get_status() );
		$this->assertSame( 'too_long', $long->get_data()['code'] );
	}

	public function test_a_note_about_an_account_that_does_not_exist_is_refused(): void {
		$this->assertSame( 404, $this->call( $this->hst, 'PUT', '/999999/notes', [ 'notes' => 'Ghost.' ] )->get_status() );
	}

	public function test_delete_removes_the_note(): void {
		Player_Note::save( $this->game_id, $this->player, 'Gone soon.', $this->hst );

		$this->call( $this->hst, 'DELETE', $this->note_url() );

		$this->assertNull( Player_Note::find( $this->game_id, $this->player ) );
	}

	public function test_each_chronicle_keeps_its_own_note_about_the_same_person(): void {
		$other = (int) Game::create( [ 'slug' => 'thread-player-notes-other', 'name' => 'Other' ] );
		Game_Member::set_role( $other, $this->player, 'player' );
		Player_Note::save( $other, $this->player, 'Other chronicle only.', $this->hst );
		Player_Note::save( $this->game_id, $this->player, 'This chronicle only.', $this->hst );

		$this->assertSame( 'This chronicle only.', $this->call( $this->hst, 'GET', $this->note_url() )->get_data()['notes'] );
	}

	// -- the players list ---------------------------------------------------------------------

	public function test_the_players_list_marks_who_has_a_note_and_never_carries_one(): void {
		$other = $this->member( 'player', 'subscriber', 'Otto Other' );
		Player_Note::save( $this->game_id, $this->player, 'Private words here.', $this->hst );

		wp_set_current_user( $this->hst );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/players" ) );
		$players  = array_column( $response->get_data()['players'], null, 'wp_user_id' );

		$this->assertTrue( $players[ $this->player ]['has_notes'] );
		$this->assertFalse( $players[ $other ]['has_notes'] );
		$this->assertStringNotContainsString( 'Private words here', (string) wp_json_encode( $response->get_data() ) );
	}

	// -- leaving and coming back --------------------------------------------------------------

	public function test_a_note_outlives_a_player_who_leaves_and_comes_back_with_them(): void {
		Player_Note::save( $this->game_id, $this->player, 'Remember Pia.', $this->hst );

		Game_Member::remove( $this->game_id, $this->player );
		$former = $this->call( $this->hst, 'GET', '/former-notes' )->get_data();
		$this->assertSame( [ $this->player ], array_column( $former, 'wp_user_id' ) );
		$this->assertSame( 'Pia Player', $former[0]['display_name'] );
		$this->assertSame( 'Remember Pia.', $this->call( $this->hst, 'GET', $this->note_url() )->get_data()['notes'] );

		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		$this->assertSame( [], $this->call( $this->hst, 'GET', '/former-notes' )->get_data() );
		$this->assertSame( 'Remember Pia.', $this->call( $this->hst, 'GET', $this->note_url() )->get_data()['notes'] );
	}

	public function test_a_note_about_someone_still_in_the_chronicle_is_not_listed_as_former(): void {
		Player_Note::save( $this->game_id, $this->player, 'Still here.', $this->hst );

		$this->assertSame( [], $this->call( $this->hst, 'GET', '/former-notes' )->get_data() );
	}

	// -- deletion -----------------------------------------------------------------------------

	public function test_deleting_the_account_deletes_its_notes_in_every_chronicle(): void {
		$other = (int) Game::create( [ 'slug' => 'thread-player-notes-gone', 'name' => 'Gone' ] );
		Player_Note::save( $this->game_id, $this->player, 'One.', $this->hst );
		Player_Note::save( $other, $this->player, 'Two.', $this->hst );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->player );

		$this->assertNull( Player_Note::find( $this->game_id, $this->player ) );
		$this->assertNull( Player_Note::find( $other, $this->player ) );
	}

	public function test_deleting_the_chronicle_deletes_its_notes_and_its_notices(): void {
		Player_Note::save( $this->game_id, $this->player, 'Doomed.', $this->hst );
		Notice::add( $this->game_id, $this->player, 'plot_post', [ 'plot_title' => 'Doomed' ] );
		$game = Game::find_by_slug( $this->slug );

		$this->assertSame( 1, Game::content_counts( $game )['player_notes'] );
		$this->assertTrue( Game::delete_with_content( $this->slug ) );

		$this->assertNull( Player_Note::find( $this->game_id, $this->player ) );
		$this->assertSame( [], Notice::for_person( $this->game_id, $this->player, 10 ) );
	}

	// -- nowhere else -------------------------------------------------------------------------

	public function test_a_characters_own_route_never_carries_the_note(): void {
		$character = (int) Character::create( [
			'name' => 'Isolde', 'stack_slug' => 'vampire', 'owner_slug' => $this->slug,
			'wp_user_id' => $this->player, 'status' => 'active', 'created_by' => 1,
		] );
		Player_Note::save( $this->game_id, $this->player, 'Keep this off the sheet.', $this->hst );

		wp_set_current_user( $this->hst );
		$sheet = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$character}" ) );

		$this->assertStringNotContainsString( 'Keep this off the sheet', (string) wp_json_encode( $sheet->get_data() ) );
	}

	public function test_revenant_has_no_route_to_the_notes(): void {
		$match = new ReflectionMethod( Bot_Auth::class, 'match_route' );

		foreach ( [ 'GET', 'PUT', 'DELETE', 'POST' ] as $method ) {
			$this->assertNull( $match->invoke( null, $method, "/be/v1/{$this->slug}/players/5/notes" ), $method );
			$this->assertNull( $match->invoke( null, $method, "/be/v1/{$this->slug}/players/former-notes" ), $method );
		}
	}
}
