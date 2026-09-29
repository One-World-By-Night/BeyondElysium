<?php

namespace BeyondElysium\Tests\Workflow;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Player_Invites;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A Storyteller invites a player by email with two characters before the player has an account; the player signs up
 * and signs in, and sees those two characters and no other.
 */
class InvitePlayerWorkflowTest extends WP_UnitTestCase {

	private string $slug = 'invite-player-workflow';

	private function dispatch( int $as, string $method, string $route, array $params = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}{$route}" );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function tearDown(): void {
		update_option( 'be_asc_enabled', false );
		parent::tearDown();
	}

	public function test_an_invited_player_signs_in_and_finds_their_characters_waiting(): void {
		do_action( 'rest_api_init' );
		reset_phpmailer_instance();
		delete_site_option( Player_Invites::INDEX_OPTION );
		update_option( 'be_asc_enabled', true );

		$game_id     = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Invite Player Workflow', 'asc_role_path' => 'chronicle/invite-player-workflow' ] );
		$storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $storyteller, 'hst' );
		$ids = [];
		foreach ( [ 'Imported One', 'Imported Two', 'Somebody Else' ] as $name ) {
			$ids[] = (int) Character::create( [ 'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );
		}

		// The Storyteller invites an address nobody has signed in with yet, with two of the three characters.
		$invited = $this->dispatch( $storyteller, 'POST', '/players/invites', [ 'email' => 'Returning.Player@Example.test', 'character_ids' => [ $ids[0], $ids[1] ] ] );
		$this->assertSame( 'invited', $invited->get_data()['status'] );
		$this->assertTrue( $invited->get_data()['email_sent'] );
		$this->assertCount( 1, $this->dispatch( $storyteller, 'GET', '/players/invites' )->get_data() );

		// The player creates their account with that address, then signs in.
		$player = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'returning.player@example.test' ] );
		do_action( 'wp_login', 'returning', get_userdata( $player ) );

		// They are a player now, and their characters are theirs.
		$this->assertSame( 'player', Game_Member::find( $game_id, $player )->role );
		$mine = $this->dispatch( $player, 'GET', '/characters' );
		$this->assertSame( 200, $mine->get_status() );
		$this->assertSame( [ 'Imported One', 'Imported Two' ], array_values( array_map( static fn( $c ) => $c->name, (array) $mine->get_data() ) ) );

		// Nothing waits any more.
		$this->assertSame( [], $this->dispatch( $storyteller, 'GET', '/players/invites' )->get_data() );
		$this->assertSame( [], Player_Invites::sites_for( 'returning.player@example.test' ) );
	}
}
