<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A chronicle linked to accessSchema has its HST or AST add and remove players: the membership row and the player role
 * there. A chronicle that is not linked answers 404. A staff member is never demoted; a player cannot do any of it.
 */
class ChroniclePlayersThreadTest extends WP_UnitTestCase {

	private string $slug = 'players-test';
	private int $game_id;
	private int $hst_id;
	private int $ast_id;
	private int $player_id;
	private int $newcomer_id;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once __DIR__ . '/fixtures/fake-owc-asc-roles.php';
	}

	public function setUp(): void {
		parent::setUp();
		$GLOBALS['be_test_asc_calls'] = [];
		unset( $GLOBALS['be_test_asc_grant_result'], $GLOBALS['be_test_asc_user_roles'] );
		update_option( 'be_asc_enabled', false );

		$admin         = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Players Test', 'created_by' => $admin ] );

		$this->hst_id      = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->ast_id      = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->player_id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->newcomer_id = self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Test Newcomer', 'user_email' => 'newcomer@example.test' ] );

		Game_Member::set_role( $this->game_id, $this->hst_id, 'hst' );
		Game_Member::set_role( $this->game_id, $this->ast_id, 'ast' );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );

		$this->link_chronicle();
	}

	public function tearDown(): void {
		update_option( 'be_asc_enabled', false );
		parent::tearDown();
	}

	private function dispatch( int $as, string $method, string $route, array $params = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function link_chronicle( ?string $path = 'Chronicle/Players Test', bool $site_reads_asc = true ): void {
		global $wpdb;
		update_option( 'be_asc_enabled', $site_reads_asc );
		$wpdb->update( $wpdb->prefix . 'be_games', [ 'asc_role_path' => $path ], [ 'id' => $this->game_id ] );
	}

	public function test_an_ast_adds_an_existing_account_as_a_player(): void {
		$response = $this->dispatch( $this->ast_id, 'POST', "/be/v1/{$this->slug}/players", [ 'wp_user_id' => $this->newcomer_id ] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'added', $response->get_data()['status'] );
		$this->assertSame( 'player', Game_Member::find( $this->game_id, $this->newcomer_id )->role );
		$this->assertTrue( $response->get_data()['asc']['granted'] );
	}

	public function test_a_player_can_neither_list_add_nor_remove_players(): void {
		$this->assertSame( 403, $this->dispatch( $this->player_id, 'GET', "/be/v1/{$this->slug}/players" )->get_status() );
		$this->assertSame( 403, $this->dispatch( $this->player_id, 'POST', "/be/v1/{$this->slug}/players", [ 'wp_user_id' => $this->newcomer_id ] )->get_status() );
		$this->assertSame( 403, $this->dispatch( $this->player_id, 'DELETE', "/be/v1/{$this->slug}/players/{$this->ast_id}" )->get_status() );
		$this->assertNull( Game_Member::find( $this->game_id, $this->newcomer_id ) );
	}

	public function test_a_staff_member_is_never_demoted_or_removed_here(): void {
		$added = $this->dispatch( $this->ast_id, 'POST', "/be/v1/{$this->slug}/players", [ 'wp_user_id' => $this->hst_id ] );
		$this->assertSame( 200, $added->get_status() );
		$this->assertSame( 'staff', $added->get_data()['status'] );

		$removed = $this->dispatch( $this->ast_id, 'DELETE', "/be/v1/{$this->slug}/players/{$this->hst_id}" );
		$this->assertSame( 409, $removed->get_status() );
		$this->assertSame( 'hst', Game_Member::find( $this->game_id, $this->hst_id )->role );
	}

	public function test_the_hst_removes_a_player_and_the_list_shows_who_is_left(): void {
		$removed = $this->dispatch( $this->hst_id, 'DELETE', "/be/v1/{$this->slug}/players/{$this->player_id}" );
		$this->assertSame( 200, $removed->get_status() );
		$this->assertSame( 'removed', $removed->get_data()['status'] );
		$this->assertNull( Game_Member::find( $this->game_id, $this->player_id ) );

		$this->dispatch( $this->hst_id, 'POST', "/be/v1/{$this->slug}/players", [ 'wp_user_id' => $this->newcomer_id ] );
		$list = $this->dispatch( $this->hst_id, 'GET', "/be/v1/{$this->slug}/players" )->get_data();
		$this->assertSame( [ 'Test Newcomer' ], array_column( $list['players'], 'display_name' ) );
		$this->assertSame( 'chronicle/players-test/player', $list['asc_role_path'] );
	}

	public function test_an_account_that_does_not_exist_is_refused(): void {
		$response = $this->dispatch( $this->ast_id, 'POST', "/be/v1/{$this->slug}/players", [ 'wp_user_id' => 999999 ] );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_on_an_accessschema_chronicle_the_player_role_is_granted_and_revoked(): void {

		$added = $this->dispatch( $this->ast_id, 'POST', "/be/v1/{$this->slug}/players", [ 'wp_user_id' => $this->newcomer_id ] );
		$asc   = $added->get_data()['asc'];
		$this->assertTrue( $asc['granted'] );
		$this->assertSame( 'chronicle/players-test/player', $asc['role_path'] );
		$this->assertContains( [ 'grant', 'newcomer@example.test', 'chronicle/players-test/player' ], $GLOBALS['be_test_asc_calls'] );
		$this->assertContains( [ 'refresh', $this->newcomer_id, null ], $GLOBALS['be_test_asc_calls'], 'that one account is refreshed' );

		$removed = $this->dispatch( $this->ast_id, 'DELETE', "/be/v1/{$this->slug}/players/{$this->newcomer_id}" );
		$this->assertTrue( $removed->get_data()['asc']['revoked'] );
		$this->assertContains( [ 'revoke', 'newcomer@example.test', 'chronicle/players-test/player' ], $GLOBALS['be_test_asc_calls'] );
	}

	public function test_a_grant_accessschema_refuses_is_reported_and_the_player_is_still_added(): void {
		$GLOBALS['be_test_asc_grant_result'] = new \WP_Error( 'asc_api_error', 'ASC API returned HTTP 403' );

		$response = $this->dispatch( $this->ast_id, 'POST', "/be/v1/{$this->slug}/players", [ 'wp_user_id' => $this->newcomer_id ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertFalse( $response->get_data()['asc']['granted'] );
		$this->assertStringContainsString( '403', $response->get_data()['asc']['message'] );
		$this->assertSame( 'player', Game_Member::find( $this->game_id, $this->newcomer_id )->role );
	}

	/**
	 * Every way a chronicle is not linked: the site does not read accessSchema, or the chronicle names no role path.
	 *
	 * @return array<string,array{0:?string,1:bool}>
	 */
	public static function unlinked_chronicles(): array {
		return [
			'the site does not read accessSchema' => [ 'Chronicle/Players Test', false ],
			'the chronicle names no role path'    => [ '', true ],
			'neither'                             => [ null, false ],
		];
	}

	/**
	 * @dataProvider unlinked_chronicles
	 */
	public function test_a_chronicle_not_linked_to_accessschema_answers_404_on_every_players_route( ?string $path, bool $site_reads_asc ): void {
		$this->link_chronicle( $path, $site_reads_asc );

		foreach ( [ $this->hst_id, $this->ast_id ] as $as ) {
			$list = $this->dispatch( $as, 'GET', "/be/v1/{$this->slug}/players" );
			$this->assertSame( 404, $list->get_status() );
			$this->assertSame( 'players_unavailable', $list->as_error()->get_error_code() );
			$this->assertSame( 404, $this->dispatch( $as, 'POST', "/be/v1/{$this->slug}/players", [ 'wp_user_id' => $this->newcomer_id ] )->get_status() );
			$this->assertSame( 404, $this->dispatch( $as, 'DELETE', "/be/v1/{$this->slug}/players/{$this->player_id}" )->get_status() );
		}

		$this->assertNull( Game_Member::find( $this->game_id, $this->newcomer_id ), 'nobody was added' );
		$this->assertSame( 'player', Game_Member::find( $this->game_id, $this->player_id )->role, 'nobody was removed' );
		$this->assertSame( [], $GLOBALS['be_test_asc_calls'], 'accessSchema was never asked' );
	}

	public function test_a_player_is_refused_before_the_link_is_looked_at(): void {
		$this->link_chronicle( null, false );

		$this->assertSame( 403, $this->dispatch( $this->player_id, 'GET', "/be/v1/{$this->slug}/players" )->get_status() );
	}

	public function test_my_games_says_which_chronicles_are_linked(): void {
		$linked = $this->dispatch( $this->ast_id, 'GET', '/be/v1/my/games' )->get_data();
		$this->assertContains( [ 'slug' => $this->slug, 'name' => 'Players Test', 'role' => 'ast', 'asc_linked' => true ], $linked );

		$this->link_chronicle( null, true );
		$unlinked = $this->dispatch( $this->ast_id, 'GET', '/be/v1/my/games' )->get_data();
		$this->assertContains( [ 'slug' => $this->slug, 'name' => 'Players Test', 'role' => 'ast', 'asc_linked' => false ], $unlinked );
	}

	public function test_my_games_lists_a_chronicle_reached_through_accessschema(): void {
		$GLOBALS['be_test_asc_user_roles'] = [ 'newcomer@example.test' => [ 'Chronicle/Players Test/AST', 'Chronicle/Players Test/Player' ] ];

		$games = $this->dispatch( $this->newcomer_id, 'GET', '/be/v1/my/games' )->get_data();

		$this->assertContains( [ 'slug' => $this->slug, 'name' => 'Players Test', 'role' => 'ast', 'asc_linked' => true ], $games, 'the highest role held there' );
	}
}
