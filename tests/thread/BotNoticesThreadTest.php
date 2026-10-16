<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/Bot_Signed_Request.php';

use BeyondElysium\Core\Bot_Signing;
use BeyondElysium\Models\Bot_Connection;
use BeyondElysium\Models\Bot_Notice;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Discord_Link;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Tests\Support\Bot_Signed_Request;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The bot's own event feed: a chronicle's undelivered notices, read and acknowledged over the signed service
 * routes, and fed by real events - a change decided, a change waiting, a downtime action in, a plot post, and a
 * Discord link waiting for approval.
 */
class BotNoticesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-bot-notices';
	private int $game_id;
	private int $connection_id;
	private string $secret;
	private int $storyteller;
	private int $other_storyteller;
	private int $player;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Notices by Night' ] );
		$connected      = Bot_Connection::connect( $this->game_id, '1', 'Guild', 1 );
		$this->connection_id = $connected['id'];
		$this->secret         = Bot_Signing::base64url_decode( $connected['secret'] );

		$this->storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller, 'hst' );
		$this->other_storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->other_storyteller, 'hst' );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );

		$this->character_id = (int) Character::create( [
			'name' => 'Notice Carrier', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
		] );
		Character::update_xp( $this->character_id, 20, 20 );
	}

	private function signed( string $method, string $route, string $discord_user_id = '', ?array $body = null, array $query = [] ) {
		$body_json = $body === null ? null : wp_json_encode( $body );
		$request   = Bot_Signed_Request::build( $method, $route, $this->connection_id, $this->secret, $discord_user_id, $query, $body_json );
		return rest_get_server()->dispatch( $request );
	}

	private function dispatch_as_site_user( int $user_id, string $method, string $route, array $body = [] ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		$response = rest_get_server()->dispatch( $request );
		wp_set_current_user( 0 );
		return $response;
	}

	// -------------------------------------------------------------------------
	// The model and the two service routes
	// -------------------------------------------------------------------------

	public function test_the_model_adds_lists_and_marks_delivered(): void {
		$id = Bot_Notice::add( $this->game_id, $this->player, 'change_decided', [ 'character_name' => 'Test' ] );

		$undelivered = Bot_Notice::undelivered_for_game( $this->game_id );
		$this->assertCount( 1, $undelivered );
		$this->assertSame( 'change_decided', $undelivered[0]->kind );
		$this->assertSame( 'Test', $undelivered[0]->payload['character_name'] );

		$marked = Bot_Notice::mark_delivered( $this->game_id, [ $id ] );
		$this->assertSame( 1, $marked );
		$this->assertCount( 0, Bot_Notice::undelivered_for_game( $this->game_id ) );
	}

	public function test_a_staff_notice_carries_a_null_wp_user_id(): void {
		Bot_Notice::add( $this->game_id, null, 'change_waiting', [ 'character_name' => 'Test' ] );

		$undelivered = Bot_Notice::undelivered_for_game( $this->game_id );
		$this->assertNull( $undelivered[0]->wp_user_id );
	}

	public function test_the_bot_can_read_and_ack_its_own_chronicles_notices(): void {
		Bot_Notice::add( $this->game_id, $this->player, 'change_decided', [ 'character_name' => 'Test' ] );

		$list = $this->signed( 'GET', "/be/v1/{$this->slug}/bot/notices" );
		$this->assertSame( 200, $list->get_status() );
		$this->assertCount( 1, $list->get_data() );
		$id = $list->get_data()[0]['id'];

		$ack = $this->signed( 'POST', "/be/v1/{$this->slug}/bot/notices/ack", '', [ 'ids' => [ $id ] ] );
		$this->assertSame( 200, $ack->get_status() );
		$this->assertSame( 1, $ack->get_data()['marked'] );

		$this->assertCount( 0, $this->signed( 'GET', "/be/v1/{$this->slug}/bot/notices" )->get_data() );
	}

	public function test_acking_cannot_reach_another_chronicles_notice(): void {
		$other_game_id = (int) Game::create( [ 'slug' => 'thread-bot-notices-other', 'name' => 'Elsewhere' ] );
		$other_id      = Bot_Notice::add( $other_game_id, null, 'change_waiting', [] );

		$ack = $this->signed( 'POST', "/be/v1/{$this->slug}/bot/notices/ack", '', [ 'ids' => [ $other_id ] ] );

		$this->assertSame( 0, $ack->get_data()['marked'] );
		$this->assertCount( 1, Bot_Notice::undelivered_for_game( $other_game_id ) );
	}

	// -------------------------------------------------------------------------
	// Real events
	// -------------------------------------------------------------------------

	public function test_approving_a_change_notices_the_player(): void {
		$change_id = Change::create( [
			'character_id' => $this->character_id, 'change_type' => 'add_trait', 'category' => 'trait',
			'change_data'  => [ 'block_slug' => 'thread-block', 'trait' => [ 'name' => 'Occult', 'count' => 3 ] ],
			'xp_cost'      => 5, 'status' => 'pending', 'submitted_by' => $this->player,
		] );

		$response = $this->dispatch_as_site_user( $this->storyteller, 'PUT', "/be/v1/{$this->slug}/changes/{$change_id}", [
			'status' => 'approved',
		] );
		$this->assertSame( 200, $response->get_status() );

		$undelivered = Bot_Notice::undelivered_for_game( $this->game_id );
		$decided = array_values( array_filter( $undelivered, static fn( $n ) => $n->kind === 'change_decided' ) );
		$this->assertCount( 1, $decided );
		$this->assertSame( $this->player, $decided[0]->wp_user_id );
	}

	public function test_submitting_a_change_that_lands_pending_notices_staff(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_games', [ 'settings' => wp_json_encode( [ 'approval_on_removal' => true ] ) ], [ 'id' => $this->game_id ] );

		$response = $this->dispatch_as_site_user( $this->player, 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes", [
			'character_id' => $this->character_id,
			'change_type'  => 'add_trait',
			'category'     => 'trait',
			'change_data'  => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Occult', 'count' => 3 ] ],
		] );
		$this->assertSame( 201, $response->get_status() );

		$undelivered = Bot_Notice::undelivered_for_game( $this->game_id );
		$waiting = array_values( array_filter( $undelivered, static fn( $n ) => $n->kind === 'change_waiting' ) );
		if ( $response->get_data()->status === 'pending' ) {
			$this->assertCount( 1, $waiting );
			$this->assertNull( $waiting[0]->wp_user_id );
		} else {
			$this->assertCount( 0, $waiting );
		}
	}

	public function test_a_downtime_action_notices_staff_exactly_once_however_many_storytellers_there_are(): void {
		$plot_id = Plot::create( [
			'game_id' => $this->game_id, 'title' => 'Allocation Plot', 'initiated_by' => 'player',
			'game_date' => '2026-09-01', 'created_by' => 1,
		] );
		\BeyondElysium\Models\Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $plot_id,
			'target_type' => 'character', 'target_id' => $this->character_id,
			'label' => Action_Allocator::ACTOR_LABEL, 'created_by' => 1,
		] );
		Plot_Entry::create( [
			'plot_id' => $plot_id, 'author_id' => 1, 'entry_type' => 'action',
			'content' => wp_json_encode( [ 'source' => 'allocator', 'name' => 'Resources', 'level' => 3, 'total' => 3, 'unused' => 3 ] ),
			'event_date' => '2026-09-01',
		] );

		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/plots/{$plot_id}/entries" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'entry_type' => 'action', 'content' => 'I watch the docks.' ] ) );
		$response = rest_get_server()->dispatch( $request );
		wp_set_current_user( 0 );

		$this->assertSame( 201, $response->get_status() );

		$undelivered = Bot_Notice::undelivered_for_game( $this->game_id );
		$downtime_in = array_values( array_filter( $undelivered, static fn( $n ) => $n->kind === 'downtime_in' ) );
		$this->assertCount( 1, $downtime_in, 'one shared staff notice, not one per Storyteller' );
		$this->assertNull( $downtime_in[0]->wp_user_id );
	}

	public function test_a_confirmed_link_notices_staff_but_an_auto_approved_staff_link_does_not(): void {
		$player_link = Discord_Link::start_or_renew( $this->game_id, '77', 'player-discord' );
		Discord_Link::confirm( Discord_Link::find( $player_link['link_id'] ), $this->player, [], false );

		$undelivered = Bot_Notice::undelivered_for_game( $this->game_id );
		$requests = array_values( array_filter( $undelivered, static fn( $n ) => $n->kind === 'link_request' ) );
		$this->assertCount( 1, $requests );
		$this->assertNull( $requests[0]->wp_user_id );

		$staff_link = Discord_Link::start_or_renew( $this->game_id, '88', 'hst-discord' );
		Discord_Link::confirm( Discord_Link::find( $staff_link['link_id'] ), $this->storyteller, [], true );

		$after = Bot_Notice::undelivered_for_game( $this->game_id );
		$this->assertCount( 1, array_values( array_filter( $after, static fn( $n ) => $n->kind === 'link_request' ) ), 'still only the one from the player link' );
	}
}
