<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/Bot_Signed_Request.php';

use BeyondElysium\Core\Bot_Signing;
use BeyondElysium\Models\Bot_Connection;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Discord_Link;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Tests\Support\Bot_Signed_Request;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A bot's signed call, acting as the Discord user's linked account.
 */
class BotActAsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-bot-act-as';
	private int $game_id;
	private int $connection_id;
	private string $secret;
	private int $player;
	private int $hst;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Act As by Night' ] );
		$connected      = Bot_Connection::connect( $this->game_id, '1', 'Guild', 1 );
		$this->connection_id = $connected['id'];
		$this->secret         = Bot_Signing::base64url_decode( $connected['secret'] );

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );

		$this->character_id = (int) Character::create( [
			'name' => 'Linked One', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'wp_user_id' => $this->player,
		] );

		$link = Discord_Link::start_or_renew( $this->game_id, '77', 'player-discord' );
		Discord_Link::confirm( Discord_Link::find( $link['link_id'] ), $this->player, [ $this->character_id ], true );

		$hst_link = Discord_Link::start_or_renew( $this->game_id, '88', 'hst-discord' );
		Discord_Link::confirm( Discord_Link::find( $hst_link['link_id'] ), $this->hst, [], true );
	}

	private function signed( string $method, string $route, string $discord_user_id, ?array $body = null, array $query = [] ) {
		$body_json = $body === null ? null : wp_json_encode( $body );
		$request   = Bot_Signed_Request::build( $method, $route, $this->connection_id, $this->secret, $discord_user_id, $query, $body_json );
		return rest_get_server()->dispatch( $request );
	}

	private function dispatch_as_site_user( int $user_id, string $method, string $route ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $method, $route );
		$response = rest_get_server()->dispatch( $request );
		wp_set_current_user( 0 );
		return $response;
	}

	public function test_my_characters_matches_what_the_website_shows_the_same_user(): void {
		$website = $this->dispatch_as_site_user( $this->player, 'GET', "/be/v1/{$this->slug}/my/characters" );
		$bot     = $this->signed( 'GET', "/be/v1/{$this->slug}/my/characters", '77' );

		$this->assertSame( 200, $website->get_status() );
		$this->assertSame( 200, $bot->get_status() );
		$this->assertEquals( $website->get_data(), $bot->get_data() );
	}

	public function test_a_single_character_matches_what_the_website_shows(): void {
		$website = $this->dispatch_as_site_user( $this->player, 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}" );
		$bot     = $this->signed( 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}", '77' );

		$this->assertSame( 200, $website->get_status() );
		$this->assertSame( 200, $bot->get_status() );
		$this->assertSame( $website->get_data()->id, $bot->get_data()->id );
		$this->assertSame( $website->get_data()->name, $bot->get_data()->name );
	}

	public function test_an_unlinked_discord_user_is_refused(): void {
		$response = $this->signed( 'GET', "/be/v1/{$this->slug}/my/characters", '999999' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'bot_user_not_linked', $response->as_error()->get_error_code() );
	}

	public function test_a_declined_link_is_refused(): void {
		$link = Discord_Link::start_or_renew( $this->game_id, '55', 'declined-discord' );
		$confirmed = Discord_Link::confirm( Discord_Link::find( $link['link_id'] ), $this->player, [], false );
		Discord_Link::decline( (int) $confirmed->id, $this->hst );

		$response = $this->signed( 'GET', "/be/v1/{$this->slug}/my/characters", '55' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'bot_user_not_linked', $response->as_error()->get_error_code() );
	}

	public function test_a_revoked_link_is_refused(): void {
		$link = Discord_Link::start_or_renew( $this->game_id, '66', 'revoked-discord' );
		Discord_Link::confirm( Discord_Link::find( $link['link_id'] ), $this->player, [], true );
		Discord_Link::revoke_by_staff( (int) Discord_Link::active_for_discord_user( $this->game_id, '66' )->id, $this->hst );

		$response = $this->signed( 'GET', "/be/v1/{$this->slug}/my/characters", '66' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'bot_user_not_linked', $response->as_error()->get_error_code() );
	}

	public function test_a_route_off_the_allowlist_is_refused_for_a_linked_user(): void {
		$response = $this->signed( 'DELETE', "/be/v1/{$this->slug}/characters/{$this->character_id}", '77' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'bot_route_not_allowed', $response->as_error()->get_error_code() );
	}

	public function test_a_player_cannot_approve_links_a_storyteller_can_acting_from_discord(): void {
		$link = Discord_Link::start_or_renew( $this->game_id, '33', 'waiting-discord' );
		Discord_Link::confirm( Discord_Link::find( $link['link_id'] ), self::factory()->user->create( [ 'role' => 'subscriber' ] ), [], false );
		$waiting_id = (int) Discord_Link::active_for_discord_user( $this->game_id, '33' )->id;

		$as_player = $this->signed( 'POST', "/be/v1/{$this->slug}/discord-links/{$waiting_id}/approve", '77', [] );
		$this->assertSame( 403, $as_player->get_status() );

		$as_hst = $this->signed( 'POST', "/be/v1/{$this->slug}/discord-links/{$waiting_id}/approve", '88', [] );
		$this->assertSame( 200, $as_hst->get_status() );
		$this->assertSame( Discord_Link::APPROVED, Discord_Link::find( $waiting_id )->status );
	}

	public function test_every_non_get_write_lands_in_the_audit_table(): void {
		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_bot_audit" );

		$this->signed( 'DELETE', "/be/v1/{$this->slug}/discord-links/mine", '77' );

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_bot_audit" );
		$this->assertSame( $before + 1, $after );

		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}be_bot_audit ORDER BY id DESC LIMIT 1" );
		$this->assertSame( 'DELETE', $row->method );
		$this->assertSame( '77', $row->discord_user_id );
		$this->assertSame( (int) $this->player, (int) $row->wp_user_id );
	}

	public function test_a_get_call_is_not_audited(): void {
		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_bot_audit" );

		$this->signed( 'GET', "/be/v1/{$this->slug}/my/characters", '77' );

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_bot_audit" );
		$this->assertSame( $before, $after );
	}

	public function test_a_linked_player_can_spend_their_own_pool_through_the_bot(): void {
		Character::update_sheet_data( $this->character_id, [ 'vampire-resources' => [ 'Blood' => [ 'permanent' => 10, 'temporary' => 10 ] ] ] );

		$response = $this->signed(
			'POST',
			"/be/v1/{$this->slug}/characters/{$this->character_id}/temporary/pool",
			'77',
			[ 'block_slug' => 'vampire-resources', 'pool_name' => 'Blood', 'delta' => -2, 'reason' => 'spent in play' ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 8, $response->get_data()['temporary'] );
	}

	public function test_a_linked_player_can_log_a_wound_through_the_bot(): void {
		$response = $this->signed(
			'POST',
			"/be/v1/{$this->slug}/characters/{$this->character_id}/wound",
			'77',
			[ 'type' => 'lethal', 'amount' => 2 ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['wounds']['lethal'] );
	}

	public function test_a_linked_player_can_record_and_clear_a_night_loss_through_the_bot(): void {
		$add = $this->signed(
			'POST',
			"/be/v1/{$this->slug}/characters/{$this->character_id}/night-losses",
			'77',
			[ 'kind' => 'trait', 'block_slug' => 'vampire-abilities', 'name' => 'Brawl' ]
		);
		$this->assertSame( 201, $add->get_status() );

		$cleared = $this->signed( 'DELETE', "/be/v1/{$this->slug}/characters/{$this->character_id}/night-losses", '77' );
		$this->assertSame( 200, $cleared->get_status() );
		$this->assertCount( 0, $cleared->get_data()['lost_traits'] );
	}

	public function test_a_linked_storyteller_can_open_and_close_a_session_through_the_bot(): void {
		$create = $this->signed( 'POST', "/be/v1/{$this->slug}/sessions", '88', [ 'game_date' => '2026-10-15' ] );
		$this->assertSame( 201, $create->get_status() );
		$session_id = $create->get_data()->id;

		$listed = $this->signed( 'GET', "/be/v1/{$this->slug}/sessions", '88', null, [ 'from' => '2026-10-15', 'to' => '2026-10-15' ] );
		$this->assertSame( 200, $listed->get_status() );
		$this->assertCount( 1, $listed->get_data() );

		$closed = $this->signed( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/close", '88', [] );
		$this->assertSame( 200, $closed->get_status() );
	}

	public function test_a_player_cannot_close_a_session_through_the_bot(): void {
		$session_id = (int) \BeyondElysium\Models\Game_Session::create( [ 'game_id' => $this->game_id, 'game_date' => '2026-10-16', 'created_by' => $this->hst ] );

		$response = $this->signed( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/close", '77', [] );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_request_without_the_bot_headers_is_untouched(): void {
		wp_set_current_user( $this->hst );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/my/characters" );
		$response = rest_get_server()->dispatch( $request );
		wp_set_current_user( 0 );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_a_linked_player_can_fetch_their_own_posting_profile(): void {
		$response = $this->signed( 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}/posting-profile", '77' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Linked One', $data['display_name'] );
		$this->assertNull( $data['image_url'] );
		$this->assertFalse( $data['is_npc'] );
		$this->assertFalse( $data['profile_public'] );
	}

	public function test_a_posting_profile_prefers_the_public_name_and_marks_itself_public_when_set(): void {
		Character::update_header( $this->character_id, [
			'public_name'      => 'The Masked Dilettante',
			'profile_audience' => 'everyone',
		] );

		$response = $this->signed( 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}/posting-profile", '77' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'The Masked Dilettante', $data['display_name'] );
		$this->assertTrue( $data['profile_public'] );
	}

	public function test_a_posting_profile_resolves_its_portrait_to_a_real_url(): void {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );
		Character::update_header( $this->character_id, [ 'image_id' => $attachment_id ] );

		$response = $this->signed( 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}/posting-profile", '77' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'canola', $response->get_data()['image_url'] );
	}

	public function test_a_storyteller_can_fetch_an_npcs_posting_profile_through_the_bot(): void {
		$npc_id = (int) Character::create( [
			'name' => 'The Dockside Hunger', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'is_npc' => 1,
		] );

		$response = $this->signed( 'GET', "/be/v1/{$this->slug}/characters/{$npc_id}/posting-profile", '88' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['is_npc'] );
	}

	public function test_a_player_cannot_fetch_someone_elses_posting_profile_through_the_bot(): void {
		$other_player    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$other_character = (int) Character::create( [
			'name' => 'Someone Else Entirely', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'wp_user_id' => $other_player,
		] );

		$response = $this->signed( 'GET', "/be/v1/{$this->slug}/characters/{$other_character}/posting-profile", '77' );

		$this->assertSame( 403, $response->get_status() );
	}
}
