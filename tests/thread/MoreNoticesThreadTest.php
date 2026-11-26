<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\User_Settings;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Discord_Link;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Notice;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Release_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Each thing a person is told by email or by Revenant is also told in their own header, to the same people, once each,
 * and never to the person who did it.
 */
class MoreNoticesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-more-notices';
	private int $game_id;
	private int $hst;
	private int $ast;
	private int $narrator;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id  = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'More Notices' ] );
		$this->hst      = $this->member( 'hst', 'editor' );
		$this->ast      = $this->member( 'ast', 'editor' );
		$this->narrator = $this->member( 'narrator', 'editor' );

		add_filter( 'pre_wp_mail', '__return_true' );
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', '__return_true' );
		parent::tearDown();
	}

	private function member( string $role, string $wp_role = 'subscriber' ): int {
		$user = self::factory()->user->create( [ 'role' => $wp_role ] );
		Game_Member::set_role( $this->game_id, $user, $role );
		return $user;
	}

	/** @return array{0:int,1:int} [wp_user_id, character_id] */
	private function player_with_character( string $name, ?int $player = null ): array {
		$player ??= $this->member( 'player' );
		$id       = (int) Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_slug' => $this->slug,
			'wp_user_id' => $player, 'status' => 'active', 'created_by' => 1,
		] );
		return [ $player, $id ];
	}

	private function plot( string $title = 'The Chantry', ?int $assigned_to = null ): int {
		$id = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => $title, 'created_by' => 1, 'audience' => 'everyone' ] );
		if ( $assigned_to !== null ) {
			Plot::update( $id, [ 'assigned_to' => $assigned_to ] );
		}
		return $id;
	}

	private function connect( int $plot_id, int $character_id ): void {
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $plot_id,
			'target_type' => 'character', 'target_id' => $character_id, 'label' => 'plot_member', 'created_by' => 1,
		] );
	}

	private function post( int $as, string $route, array $params = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'POST', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function entry( int $as, int $plot_id, string $type ) {
		return $this->post( $as, "/be/v1/{$this->slug}/plots/{$plot_id}/entries", [ 'entry_type' => $type, 'content' => 'Something happened.' ] );
	}

	/** @return object[] */
	private function notices( int $user, ?string $kind = null ): array {
		$all = Notice::for_person( $this->game_id, $user, 100 );
		return array_values( $kind === null ? $all : array_filter( $all, static fn( $n ) => $n->kind === $kind ) );
	}

	// -- plot posts ---------------------------------------------------------------------------

	public function test_a_storytellers_post_tells_each_connected_player_once(): void {
		[ $player, $first ] = $this->player_with_character( 'Isolde' );
		[ , $second ]       = $this->player_with_character( 'Isolde Too', $player );
		$plot               = $this->plot();
		$this->connect( $plot, $first );
		$this->connect( $plot, $second );

		$this->assertSame( 201, $this->entry( $this->hst, $plot, 'response' )->get_status() );

		$told = $this->notices( $player, 'plot_post' );
		$this->assertCount( 1, $told );
		$this->assertSame( $plot, $told[0]->payload['plot_id'] );
		$this->assertSame( 'The Chantry', $told[0]->payload['plot_title'] );
		$this->assertCount( 0, $this->notices( $this->hst ) );
	}

	public function test_an_opted_out_player_still_gets_the_plot_post_notice(): void {
		[ $player, $character ] = $this->player_with_character( 'Isolde' );
		update_user_meta( $player, User_Settings::NOTIFICATIONS_OPT_OUT_META, '1' );
		$plot = $this->plot();
		$this->connect( $plot, $character );

		$this->entry( $this->hst, $plot, 'response' );

		$this->assertCount( 1, $this->notices( $player, 'plot_post' ) );
	}

	public function test_a_players_actions_tell_the_plots_assignee_and_not_the_poster(): void {
		[ $player, $character ] = $this->player_with_character( 'Isolde' );
		$plot                   = $this->plot( 'Downtime', $this->ast );
		$this->connect( $plot, $character );

		$this->entry( $player, $plot, 'action' );

		$this->assertCount( 1, $this->notices( $this->ast, 'post_waiting' ) );
		$this->assertCount( 0, $this->notices( $this->hst, 'post_waiting' ) );
		$this->assertCount( 0, $this->notices( $player, 'post_waiting' ) );
	}

	public function test_an_unassigned_post_tells_each_storyteller_and_narrator(): void {
		[ $player, $character ] = $this->player_with_character( 'Isolde' );
		$plot                   = $this->plot( 'Downtime' );
		$this->connect( $plot, $character );

		$this->entry( $player, $plot, 'action' );

		foreach ( [ $this->hst, $this->ast, $this->narrator ] as $person ) {
			$this->assertCount( 1, $this->notices( $person, 'post_waiting' ) );
		}
		$this->assertCount( 0, $this->notices( $player, 'post_waiting' ) );
	}

	// -- releases -----------------------------------------------------------------------------

	public function test_a_release_tells_a_player_once_for_the_batch(): void {
		[ $player, $first ] = $this->player_with_character( 'Isolde' );
		[ , $second ]       = $this->player_with_character( 'Isolde Too', $player );
		update_user_meta( $player, User_Settings::NOTIFICATIONS_OPT_OUT_META, '1' );

		$rumor = $this->plot( 'A Rumor' );
		$this->connect( $rumor, $first );
		$this->connect( $rumor, $second );
		$batch = $this->batch( [ $rumor ] );

		Release_Engine::release( $batch );

		$told = $this->notices( $player, 'released' );
		$this->assertCount( 1, $told );
		$this->assertSame( $batch, $told[0]->payload['batch_id'] );
		$this->assertSame( 1, $told[0]->payload['rumors'] );
		$this->assertEqualsCanonicalizing( [ 'Isolde', 'Isolde Too' ], $told[0]->payload['character_names'] );
	}

	/**
	 * @param int[] $plot_ids
	 */
	private function batch( array $plot_ids ): int {
		wp_set_current_user( $this->hst );
		$create = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/release-batches" );
		$create->set_param( 'name', 'Batch' );
		$batch_id = (int) rest_get_server()->dispatch( $create )->get_data()->id;
		foreach ( $plot_ids as $plot_id ) {
			$this->post( $this->hst, "/be/v1/{$this->slug}/release-batches/{$batch_id}/items", [ 'type' => 'plot', 'id' => $plot_id ] );
		}
		return $batch_id;
	}

	// -- becoming visible ---------------------------------------------------------------------

	public function test_connecting_an_item_tells_its_player_whatever_their_email_choice(): void {
		[ $player, $character ] = $this->player_with_character( 'Isolde' );
		update_user_meta( $player, User_Settings::PLOT_NOTIFY_META, 'off' );
		$item = (int) World_Object::create( [ 'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'A Tarnished Locket', 'audience' => 'storytellers' ] );

		$response = $this->post( $this->hst, '/be/v1/' . $this->slug . '/connections', [
			'source_type' => 'character', 'source_id' => $character,
			'target_type' => 'world_object', 'target_id' => $item, 'label' => 'holds',
		] );

		$this->assertSame( 201, $response->get_status() );
		$told = $this->notices( $player, 'now_visible' );
		$this->assertCount( 1, $told );
		$this->assertSame( 'item', $told[0]->payload['entity'] );
		$this->assertSame( 'A Tarnished Locket', $told[0]->payload['title'] );
		$this->assertNotSame( '', $told[0]->payload['url'] );
	}

	// -- joining ------------------------------------------------------------------------------

	public function test_a_join_request_tells_the_hst_and_ast_and_no_one_else(): void {
		$applicant = self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Ana Souza' ] );

		$this->assertSame( 201, $this->post( $applicant, "/be/v1/{$this->slug}/join", [ 'message' => 'Let me in.' ] )->get_status() );

		foreach ( [ $this->hst, $this->ast ] as $person ) {
			$told = $this->notices( $person, 'join_requested' );
			$this->assertCount( 1, $told );
			$this->assertSame( 'Ana Souza', $told[0]->payload['applicant'] );
		}
		$this->assertCount( 0, $this->notices( $this->narrator, 'join_requested' ) );
		$this->assertCount( 0, $this->notices( $applicant, 'join_requested' ) );
	}

	public function test_an_approved_request_tells_the_newcomer_and_a_refusal_tells_no_one(): void {
		$yes = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$no  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->post( $yes, "/be/v1/{$this->slug}/join", [ 'message' => 'Hello' ] );
		$this->post( $no, "/be/v1/{$this->slug}/join", [ 'message' => 'Hello' ] );

		$ids = [];
		foreach ( [ $yes, $no ] as $person ) {
			$ids[ $person ] = (int) \BeyondElysium\Models\Join_Request::find_waiting( $this->game_id, $person )->id;
		}
		$this->post( $this->hst, "/be/v1/{$this->slug}/players/join-requests/{$ids[ $yes ]}/approve" );
		$this->post( $this->hst, "/be/v1/{$this->slug}/players/join-requests/{$ids[ $no ]}/refuse", [ 'note' => 'Full.' ] );

		$this->assertCount( 1, $this->notices( $yes, 'join_answered' ) );
		$this->assertCount( 0, $this->notices( $no ) );
	}

	// -- Discord links ------------------------------------------------------------------------

	public function test_a_players_discord_link_request_tells_the_hst_and_ast(): void {
		[ $player, $character ] = $this->player_with_character( 'Isolde' );
		$result                 = Discord_Link::start_or_renew( $this->game_id, '77', 'isolde#1' );

		$confirm = $this->post( $player, "/be/v1/{$this->slug}/discord-links/confirm", [
			'token' => $result['token'], 'character_ids' => [ $character ],
		] );

		$this->assertSame( 200, $confirm->get_status() );
		foreach ( [ $this->hst, $this->ast ] as $person ) {
			$told = $this->notices( $person, 'link_requested' );
			$this->assertCount( 1, $told );
			$this->assertSame( [ 'Isolde' ], $told[0]->payload['character_names'] );
		}
		$this->assertCount( 0, $this->notices( $this->narrator, 'link_requested' ) );
	}

	public function test_a_storytellers_own_link_is_approved_at_once_and_tells_no_one(): void {
		$result = Discord_Link::start_or_renew( $this->game_id, '78', 'hst#1' );

		$this->post( $this->hst, "/be/v1/{$this->slug}/discord-links/confirm", [ 'token' => $result['token'], 'character_ids' => [] ] );

		$this->assertCount( 0, $this->notices( $this->ast, 'link_requested' ) );
	}
}
