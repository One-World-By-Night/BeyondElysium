<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Player_Invite;
use BeyondElysium\Services\Player_Invites;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The Players tab's routes for inviting a player by email with their characters, cancelling an invite, and linking and
 * unlinking a player's characters, on a chronicle linked to accessSchema.
 */
class ChroniclePlayersInvitesThreadTest extends WP_UnitTestCase {

	private string $slug = 'invites-route-test';
	private int $game_id;
	private int $hst_id;
	private int $player_id;

	/** @var int[] */
	private array $characters = [];

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once __DIR__ . '/fixtures/fake-owc-asc-roles.php';
	}

	public function setUp(): void {
		parent::setUp();
		reset_phpmailer_instance();
		$GLOBALS['be_test_asc_calls'] = [];
		unset( $GLOBALS['be_test_asc_grant_result'], $GLOBALS['be_test_asc_user_roles'] );
		delete_site_option( Player_Invites::INDEX_OPTION );
		update_option( 'be_asc_enabled', true );

		$this->game_id   = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Invites Route Test', 'asc_role_path' => 'chronicle/invites-route-test' ] );
		$this->hst_id    = self::factory()->user->create( [ 'role' => 'editor', 'display_name' => 'Head Storyteller' ] );
		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Plain Player' ] );
		Game_Member::set_role( $this->game_id, $this->hst_id, 'hst' );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		foreach ( [ 'Ada Vane', 'Bram Cole', 'Cass Reed' ] as $name ) {
			$this->characters[] = (int) Character::create( [ 'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );
		}
	}

	public function tearDown(): void {
		update_option( 'be_asc_enabled', false );
		parent::tearDown();
	}

	private function dispatch( int $as, string $method, string $route, array $params = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}{$route}" );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_an_existing_account_is_made_a_player_and_linked_now(): void {
		$account = self::factory()->user->create( [ 'user_email' => 'existing@example.test' ] );

		$response = $this->dispatch( $this->hst_id, 'POST', '/players/invites', [ 'email' => 'Existing@Example.test', 'character_ids' => [ $this->characters[0], $this->characters[1] ] ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'linked', $response->get_data()['status'] );
		$this->assertSame( 'player', Game_Member::find( $this->game_id, $account )->role );
		$this->assertSame( $account, (int) Character::find( $this->characters[0] )->wp_user_id );
		$this->assertContains( [ 'grant', 'existing@example.test', 'chronicle/invites-route-test/player' ], array_map( static fn( $c ) => array_slice( $c, 0, 3 ), $GLOBALS['be_test_asc_calls'] ) );
	}

	public function test_a_new_email_waits_and_is_sent_one_invitation(): void {
		$response = $this->dispatch( $this->hst_id, 'POST', '/players/invites', [ 'email' => 'someone.new@example.test', 'character_ids' => [ $this->characters[2] ] ] );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'invited', $data['status'] );
		$this->assertTrue( $data['email_sent'] );
		$sent = tests_retrieve_phpmailer_instance()->get_sent( 0 );
		$this->assertSame( 'someone.new@example.test', $sent->to[0][0] );
		$this->assertStringContainsString( 'Invites Route Test', $sent->subject );
		$this->assertStringContainsString( 'Head Storyteller', $sent->body );
		$this->assertStringContainsString( 'auth=sso', $sent->body );
		$this->assertStringContainsString( 'game_slug=invites-route-test', $sent->body );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent( 1 ), 'one email only' );
	}

	public function test_no_email_goes_out_when_the_box_is_unticked(): void {
		$response = $this->dispatch( $this->hst_id, 'POST', '/players/invites', [ 'email' => 'quiet@example.test', 'character_ids' => [], 'send_email' => false ] );

		$this->assertFalse( $response->get_data()['email_sent'] );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent( 0 ) );
	}

	public function test_the_invite_list_shows_each_email_its_characters_and_who_sent_it(): void {
		$this->dispatch( $this->hst_id, 'POST', '/players/invites', [ 'email' => 'waiting@example.test', 'character_ids' => [ $this->characters[1], $this->characters[0] ], 'send_email' => false ] );

		$invites = $this->dispatch( $this->hst_id, 'GET', '/players/invites' )->get_data();

		$this->assertCount( 1, $invites );
		$this->assertSame( 'waiting@example.test', $invites[0]['email'] );
		$this->assertSame( 'Head Storyteller', $invites[0]['invited_by'] );
		$this->assertSame( [ 'Ada Vane', 'Bram Cole' ], array_column( $invites[0]['characters'], 'name' ) );
	}

	public function test_cancelling_an_invite_by_route_clears_its_characters(): void {
		$invite_id = (int) $this->dispatch( $this->hst_id, 'POST', '/players/invites', [ 'email' => 'drop@example.test', 'character_ids' => [ $this->characters[0] ], 'send_email' => false ] )->get_data()['invite_id'];

		$response = $this->dispatch( $this->hst_id, 'DELETE', "/players/invites/{$invite_id}" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( Character::find( $this->characters[0] )->pending_player_email );
		$this->assertSame( 404, $this->dispatch( $this->hst_id, 'DELETE', "/players/invites/{$invite_id}" )->get_status(), 'an invite is cancelled once' );
	}

	public function test_a_players_characters_are_linked_many_at_a_time_and_unlinked_one_at_a_time(): void {
		$linked = $this->dispatch( $this->hst_id, 'POST', "/players/{$this->player_id}/characters", [ 'character_ids' => $this->characters ] );
		$this->assertCount( 3, $linked->get_data()['linked'] );

		$players = $this->dispatch( $this->hst_id, 'GET', '/players' )->get_data()['players'];
		$plain   = array_values( array_filter( $players, fn( $p ) => $p['wp_user_id'] === $this->player_id ) )[0];
		$this->assertSame( [ 'Ada Vane', 'Bram Cole', 'Cass Reed' ], array_column( $plain['characters'], 'name' ) );

		$unlink = $this->dispatch( $this->hst_id, 'DELETE', "/players/{$this->player_id}/characters/{$this->characters[1]}" );
		$this->assertSame( 200, $unlink->get_status() );
		$this->assertNull( Character::find( $this->characters[1] )->wp_user_id );
	}

	public function test_characters_are_linked_only_to_a_member_of_the_chronicle(): void {
		$stranger = self::factory()->user->create();

		$response = $this->dispatch( $this->hst_id, 'POST', "/players/{$stranger}/characters", [ 'character_ids' => [ $this->characters[0] ] ] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertNull( Character::find( $this->characters[0] )->wp_user_id );
	}

	public function test_a_player_cannot_invite_or_link(): void {
		$invite = $this->dispatch( $this->player_id, 'POST', '/players/invites', [ 'email' => 'friend@example.test', 'character_ids' => [ $this->characters[0] ] ] );
		$link   = $this->dispatch( $this->player_id, 'POST', "/players/{$this->player_id}/characters", [ 'character_ids' => [ $this->characters[0] ] ] );

		$this->assertSame( 403, $invite->get_status() );
		$this->assertSame( 403, $link->get_status() );
		$this->assertSame( [], Player_Invite::open_for_game( $this->game_id ) );
		$this->assertNull( Character::find( $this->characters[0] )->wp_user_id );
	}

	public function test_an_address_that_is_not_an_email_is_refused(): void {
		$response = $this->dispatch( $this->hst_id, 'POST', '/players/invites', [ 'email' => 'not an email', 'character_ids' => [] ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_email', $response->get_data()['code'] );
	}

	public function test_a_chronicle_not_linked_to_accessschema_has_no_invites(): void {
		update_option( 'be_asc_enabled', false );

		$this->assertSame( 404, $this->dispatch( $this->hst_id, 'POST', '/players/invites', [ 'email' => 'x@example.test', 'character_ids' => [] ] )->get_status() );
		$this->assertSame( 404, $this->dispatch( $this->hst_id, 'GET', '/players/invites' )->get_status() );
	}

	public function test_a_pending_email_set_from_the_characters_list_waits_as_an_invite(): void {
		$response = $this->dispatch( $this->hst_id, 'PUT', "/characters/{$this->characters[1]}", [ 'pending_player_email' => 'From.List@Example.test' ] );
		$this->assertSame( 200, $response->get_status() );

		$open = Player_Invite::open_for_game( $this->game_id );
		$this->assertCount( 1, $open );
		$this->assertSame( 'from.list@example.test', $open[0]->email );

		$account = self::factory()->user->create( [ 'user_email' => 'from.list@example.test' ] );
		$this->assertSame( $account, (int) Character::find( $this->characters[1] )->wp_user_id );
	}
}
