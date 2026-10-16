<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Discord_Link;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A Discord account's request to act as a chronicle member, and its approval.
 */
class DiscordLinksThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-discord-links';
	private int $game_id;
	private int $player;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Linking by Night' ] );
		$this->player  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
	}

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function character( int $wp_user_id, bool $is_npc = false, string $owner_slug = '' ): int {
		return (int) Character::create( [
			'name'       => 'Fixture',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $owner_slug ?: $this->slug,
			'wp_user_id' => $wp_user_id,
			'is_npc'     => $is_npc ? 1 : 0,
		] );
	}

	public function test_request_confirm_approve(): void {
		$result = Discord_Link::start_or_renew( $this->game_id, '1', 'Player One' );
		$this->assertSame( Discord_Link::REQUESTED, $result['status'] );
		$this->assertNotEmpty( $result['token'] );

		$character_id = $this->character( $this->player );
		$link = Discord_Link::find_by_live_token( $result['token'] );
		wp_set_current_user( $this->player );
		$response = $this->dispatch( 'POST', "/be/v1/{$this->slug}/discord-links/confirm", [
			'token' => $result['token'], 'character_ids' => [ $character_id ],
		] );
		$this->assertSame( Discord_Link::CONFIRMED, $response->get_data()['status'] );

		wp_set_current_user( $this->hst );
		$approve = $this->dispatch( 'POST', "/be/v1/{$this->slug}/discord-links/{$link->id}/approve" );
		$this->assertSame( 200, $approve->get_status() );
		$this->assertSame( Discord_Link::APPROVED, Discord_Link::find( (int) $link->id )->status );
	}

	public function test_staff_are_approved_at_once(): void {
		$result = Discord_Link::start_or_renew( $this->game_id, '2', 'HST Discord' );

		wp_set_current_user( $this->hst );
		$response = $this->dispatch( 'POST', "/be/v1/{$this->slug}/discord-links/confirm", [
			'token' => $result['token'], 'character_ids' => [],
		] );
		$this->assertSame( Discord_Link::APPROVED, $response->get_data()['status'] );
	}

	public function test_one_active_link_per_discord_user(): void {
		$result = Discord_Link::start_or_renew( $this->game_id, '3', 'Renaming' );
		$again  = Discord_Link::start_or_renew( $this->game_id, '3', 'Renamed' );
		$this->assertSame( $result['link_id'], $again['link_id'], 'the same requested row is renewed' );
	}

	public function test_one_active_link_per_wp_user_a_new_approval_replaces_the_old(): void {
		$first  = Discord_Link::start_or_renew( $this->game_id, '4', 'First Device' );
		Discord_Link::confirm( Discord_Link::find( $first['link_id'] ), $this->player, [], true );

		$second = Discord_Link::start_or_renew( $this->game_id, '5', 'Second Device' );
		Discord_Link::confirm( Discord_Link::find( $second['link_id'] ), $this->player, [], true );

		$this->assertSame( Discord_Link::REVOKED, Discord_Link::find( (int) $first['link_id'] )->status );
		$this->assertSame( Discord_Link::APPROVED, Discord_Link::find( (int) $second['link_id'] )->status );
	}

	public function test_characters_must_be_the_users_own_in_the_game_and_not_npcs(): void {
		$result = Discord_Link::start_or_renew( $this->game_id, '6', 'Picker' );
		$other_user      = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$not_mine        = $this->character( $other_user );
		$npc             = $this->character( $this->player, true );
		$other_game_slug = 'thread-discord-links-other';
		Game::create( [ 'slug' => $other_game_slug, 'name' => 'Other' ] );
		$wrong_game      = $this->character( $this->player, false, $other_game_slug );

		wp_set_current_user( $this->player );
		foreach ( [ $not_mine, $npc, $wrong_game ] as $bad_id ) {
			$response = $this->dispatch( 'POST', "/be/v1/{$this->slug}/discord-links/confirm", [
				'token' => $result['token'], 'character_ids' => [ $bad_id ],
			] );
			$this->assertSame( 400, $response->get_status(), "character {$bad_id} should have been refused" );
		}
	}

	public function test_an_expired_or_reused_token_is_refused(): void {
		$result = Discord_Link::start_or_renew( $this->game_id, '7', 'Expiring' );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_discord_links', [ 'token_expires_at' => wp_date( 'Y-m-d H:i:s', time() - 60 ) ], [ 'id' => $result['link_id'] ] );
		$this->assertNull( Discord_Link::find_by_live_token( $result['token'] ) );

		$reused = Discord_Link::start_or_renew( $this->game_id, '8', 'Reusing' );
		Discord_Link::confirm( Discord_Link::find( $reused['link_id'] ), $this->player, [], true );
		$this->assertNull( Discord_Link::find_by_live_token( $reused['token'] ), 'a token stops working once confirmed' );
	}

	public function test_revoking_from_either_side(): void {
		$byStaff = Discord_Link::start_or_renew( $this->game_id, '9', 'Staff Revoke' );
		Discord_Link::confirm( Discord_Link::find( $byStaff['link_id'] ), $this->player, [], true );
		Discord_Link::revoke_by_staff( (int) $byStaff['link_id'], $this->hst );
		$this->assertSame( Discord_Link::REVOKED, Discord_Link::find( (int) $byStaff['link_id'] )->status );

		$second_user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $second_user, 'player' );
		$bySelf = Discord_Link::start_or_renew( $this->game_id, '10', 'Self Revoke' );
		Discord_Link::confirm( Discord_Link::find( $bySelf['link_id'] ), $second_user, [], true );
		Discord_Link::revoke_own( $this->game_id, $second_user );
		$this->assertSame( Discord_Link::REVOKED, Discord_Link::find( (int) $bySelf['link_id'] )->status );
	}

	public function test_the_lookup_lists_only_characters_still_owned(): void {
		$character_id = $this->character( $this->player );
		$result = Discord_Link::start_or_renew( $this->game_id, '11', 'Owned Check' );
		Discord_Link::confirm( Discord_Link::find( $result['link_id'] ), $this->player, [ $character_id ], true );

		$link = Discord_Link::active_for_discord_user( $this->game_id, '11' );
		$this->assertCount( 1, Discord_Link::live_characters( $link ) );

		Character::update_header( $character_id, [ 'wp_user_id' => self::factory()->user->create() ] );
		$this->assertCount( 0, Discord_Link::live_characters( $link ), 'a character given to someone else drops out' );
	}

	public function test_a_storyteller_sets_and_clears_an_approved_links_room(): void {
		$result = Discord_Link::start_or_renew( $this->game_id, '20', 'Room Setter' );
		Discord_Link::confirm( Discord_Link::find( $result['link_id'] ), $this->player, [], true );

		wp_set_current_user( $this->hst );
		$set = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/discord-links/{$result['link_id']}/room", [
			'channel_id' => '555666777',
		] );
		$this->assertSame( 200, $set->get_status() );
		$this->assertSame( '555666777', $set->get_data()['room_channel_id'] );
		$this->assertSame( '555666777', Discord_Link::find( (int) $result['link_id'] )->room_channel_id );

		$list = $this->dispatch( 'GET', "/be/v1/{$this->slug}/discord-links" );
		$found = current( array_filter( $list->get_data(), static fn( $l ) => $l['id'] === (int) $result['link_id'] ) );
		$this->assertSame( '555666777', $found['room_channel_id'] );

		$clear = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/discord-links/{$result['link_id']}/room", [
			'channel_id' => null,
		] );
		$this->assertSame( 200, $clear->get_status() );
		$this->assertNull( Discord_Link::find( (int) $result['link_id'] )->room_channel_id );
	}

	public function test_a_room_cannot_be_set_on_a_link_that_is_not_approved(): void {
		$result = Discord_Link::start_or_renew( $this->game_id, '21', 'Not Approved Yet' );

		wp_set_current_user( $this->hst );
		$response = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/discord-links/{$result['link_id']}/room", [
			'channel_id' => '111',
		] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_player_cannot_set_a_room(): void {
		$result = Discord_Link::start_or_renew( $this->game_id, '22', 'Player Attempt' );
		Discord_Link::confirm( Discord_Link::find( $result['link_id'] ), $this->player, [], true );

		wp_set_current_user( $this->player );
		$response = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/discord-links/{$result['link_id']}/room", [
			'channel_id' => '111',
		] );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_the_token_never_appears_in_a_url_path(): void {
		$result = Discord_Link::start_or_renew( $this->game_id, '12', 'Token Safety' );
		$game   = Game::find( $this->game_id );
		$confirm_url = \BeyondElysium\Services\Chronicle_Players::discord_confirm_link( $game, $result['token'] );
		$path = wp_parse_url( $confirm_url, PHP_URL_PATH );
		$this->assertStringNotContainsString( $result['token'], (string) $path );
		$query = wp_parse_url( $confirm_url, PHP_URL_QUERY );
		$this->assertStringContainsString( $result['token'], (string) $query );
	}
}
