<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Audience;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 U3a: a player plot's membership. The owner (always connected via `apr_actor` or
 * `Plots_Controller::OWNER_LABEL`) and Storytellers may invite active, non-NPC characters;
 * a Storyteller may remove anyone, the owner only whoever they themselves added; the owner's
 * own connection is never reachable through this route at all, by construction (§2.3a).
 *
 * These tests fail against pre-U3a code: no `/plots/{id}/members` or
 * `/plots/{id}/member-candidates` route exists at all.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §2.3a, U3a
 */
class PlotMembersThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-plot-members';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Plot Members',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	private function make_player(): int {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );
		return $player;
	}

	private function make_manager(): int {
		$hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $hst, 'hst' );
		return $hst;
	}

	private function make_character( int $wp_user_id, array $overrides = [] ): int {
		return (int) Character::create( array_merge( [
			'name'       => 'Fixture Character',
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'wp_user_id' => $wp_user_id,
			'created_by' => $wp_user_id,
		], $overrides ) );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/** Owner + their own action plot (Character::ensure_plot(), linked apr_actor), plus a name. */
	private function make_owner_with_plot( string $name = 'Owner Character' ): array {
		$owner        = $this->make_player();
		$character_id = $this->make_character( $owner, [ 'name' => $name ] );
		$plot_id      = Character::plot_id( $character_id );
		return [ $owner, $character_id, (int) $plot_id ];
	}

	// -------------------------------------------------------------------------
	// get_member_candidates()
	// -------------------------------------------------------------------------

	public function test_candidates_lists_only_active_non_npc_characters_by_name_and_id(): void {
		[ $owner, , $plot_id ] = $this->make_owner_with_plot();
		$stranger_player = $this->make_player();
		$this->make_character( $stranger_player, [ 'name' => 'Active Ally' ] );
		$this->make_character( $stranger_player, [ 'name' => 'Retired Ally', 'status' => 'retired' ] );
		$this->make_character( $stranger_player, [ 'name' => 'An NPC', 'is_npc' => 1 ] );

		wp_set_current_user( $owner );
		$data  = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}/member-candidates" ) )->get_data();
		$names = array_map( static fn( $c ) => $c['name'], $data );

		$this->assertContains( 'Active Ally', $names );
		$this->assertNotContains( 'Retired Ally', $names );
		$this->assertNotContains( 'An NPC', $names );
		foreach ( $data as $row ) {
			$this->assertArrayHasKey( 'id', $row );
			$this->assertArrayHasKey( 'name', $row );
			$this->assertArrayNotHasKey( 'wp_user_id', $row );
			$this->assertArrayNotHasKey( 'status', $row );
		}
	}

	public function test_candidates_excludes_the_owner_and_existing_members(): void {
		[ $owner, , $plot_id ] = $this->make_owner_with_plot( 'The Owner' );
		$member_player = $this->make_player();
		$member_id     = $this->make_character( $member_player, [ 'name' => 'Already In' ] );

		wp_set_current_user( $owner );
		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members" );
		$add->set_param( 'character_id', $member_id );
		$this->dispatch( $add );

		$data  = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}/member-candidates" ) )->get_data();
		$names = array_map( static fn( $c ) => $c['name'], $data );

		$this->assertNotContains( 'The Owner', $names );
		$this->assertNotContains( 'Already In', $names );
	}

	public function test_a_non_owner_cannot_see_candidates_even_when_they_can_see_the_plot(): void {
		[ , , $plot_id ] = $this->make_owner_with_plot();
		wp_set_current_user( $this->make_manager() );
		$update = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$update->set_param( 'audience', Audience::EVERYONE );
		$this->dispatch( $update );

		$onlooker = $this->make_player();
		$this->make_character( $onlooker );
		wp_set_current_user( $onlooker );

		$this->assertSame(
			403,
			$this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}/member-candidates" ) )->get_status(),
			'seeing a plot is not the same as being allowed to invite people to it'
		);
	}

	public function test_candidates_404s_for_a_plot_the_requester_cannot_see_at_all(): void {
		[ , , $plot_id ] = $this->make_owner_with_plot();
		$stranger = $this->make_player();
		wp_set_current_user( $stranger );

		$this->assertSame(
			404,
			$this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}/member-candidates" ) )->get_status()
		);
	}

	// -------------------------------------------------------------------------
	// add_member()
	// -------------------------------------------------------------------------

	public function test_owner_invites_an_active_character_and_it_becomes_visible_to_them(): void {
		[ $owner, , $plot_id ] = $this->make_owner_with_plot();
		$invitee_player = $this->make_player();
		$invitee_id     = $this->make_character( $invitee_player );

		wp_set_current_user( $owner );
		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members" );
		$add->set_param( 'character_id', $invitee_id );
		$response = $this->dispatch( $add );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'plot_member', $response->get_data()->label );

		wp_set_current_user( $invitee_player );
		$this->assertSame( 200, $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" ) )->get_status() );
	}

	public function test_owner_cannot_invite_an_npc(): void {
		[ $owner, , $plot_id ] = $this->make_owner_with_plot();
		$npc_id = $this->make_character( $this->make_player(), [ 'is_npc' => 1 ] );

		wp_set_current_user( $owner );
		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members" );
		$add->set_param( 'character_id', $npc_id );

		$this->assertSame( 400, $this->dispatch( $add )->get_status() );
	}

	public function test_owner_cannot_invite_an_inactive_character(): void {
		[ $owner, , $plot_id ] = $this->make_owner_with_plot();
		$retired_id = $this->make_character( $this->make_player(), [ 'status' => 'retired' ] );

		wp_set_current_user( $owner );
		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members" );
		$add->set_param( 'character_id', $retired_id );

		$this->assertSame( 400, $this->dispatch( $add )->get_status() );
	}

	public function test_a_manager_may_invite_an_npc(): void {
		[ , , $plot_id ] = $this->make_owner_with_plot();
		$npc_id = $this->make_character( $this->make_player(), [ 'is_npc' => 1 ] );

		wp_set_current_user( $this->make_manager() );
		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members" );
		$add->set_param( 'character_id', $npc_id );

		$this->assertSame( 201, $this->dispatch( $add )->get_status() );
	}

	public function test_inviting_the_owners_own_character_is_refused(): void {
		[ $owner, $owner_character_id, $plot_id ] = $this->make_owner_with_plot();

		wp_set_current_user( $owner );
		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members" );
		$add->set_param( 'character_id', $owner_character_id );

		$this->assertSame( 400, $this->dispatch( $add )->get_status() );
	}

	public function test_a_non_owner_player_cannot_add_members(): void {
		[ , , $plot_id ] = $this->make_owner_with_plot();
		wp_set_current_user( $this->make_manager() );
		$update = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$update->set_param( 'audience', Audience::EVERYONE );
		$this->dispatch( $update );

		$other_player = $this->make_player();
		$other_id     = $this->make_character( $other_player );
		wp_set_current_user( $other_player );

		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members" );
		$add->set_param( 'character_id', $other_id );
		$this->assertSame( 403, $this->dispatch( $add )->get_status() );
	}

	public function test_adding_a_character_from_another_chronicle_404s(): void {
		[ $owner, , $plot_id ] = $this->make_owner_with_plot();
		$foreign_id = (int) Character::create( [
			'name'       => 'Elsewhere',
			'stack_slug' => 'vampire',
			'owner_slug' => 'a-different-chronicle',
			'created_by' => $owner,
		] );

		wp_set_current_user( $owner );
		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members" );
		$add->set_param( 'character_id', $foreign_id );

		$this->assertSame( 404, $this->dispatch( $add )->get_status() );
	}

	// -------------------------------------------------------------------------
	// remove_member()
	// -------------------------------------------------------------------------

	public function test_owner_removes_a_member_they_added(): void {
		[ $owner, , $plot_id ] = $this->make_owner_with_plot();
		$invitee_id = $this->make_character( $this->make_player() );

		wp_set_current_user( $owner );
		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members" );
		$add->set_param( 'character_id', $invitee_id );
		$connection_id = $this->dispatch( $add )->get_data()->id;

		$remove = $this->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members/{$connection_id}" ) );
		$this->assertSame( 204, $remove->get_status() );
		$this->assertNull( Connection::find( (int) $connection_id ) );
	}

	public function test_owner_cannot_remove_a_member_a_storyteller_added(): void {
		[ $owner, , $plot_id ] = $this->make_owner_with_plot();
		$invitee_id = $this->make_character( $this->make_player() );

		wp_set_current_user( $this->make_manager() );
		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members" );
		$add->set_param( 'character_id', $invitee_id );
		$connection_id = $this->dispatch( $add )->get_data()->id;

		wp_set_current_user( $owner );
		$remove = $this->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members/{$connection_id}" ) );
		$this->assertSame( 403, $remove->get_status() );
	}

	public function test_a_manager_may_remove_any_member(): void {
		[ $owner, , $plot_id ] = $this->make_owner_with_plot();
		$invitee_id = $this->make_character( $this->make_player() );

		wp_set_current_user( $owner );
		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members" );
		$add->set_param( 'character_id', $invitee_id );
		$connection_id = $this->dispatch( $add )->get_data()->id;

		wp_set_current_user( $this->make_manager() );
		$remove = $this->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members/{$connection_id}" ) );
		$this->assertSame( 204, $remove->get_status() );
	}

	public function test_the_owners_own_connection_can_never_be_removed_through_this_route(): void {
		[ $owner, $owner_character_id, $plot_id ] = $this->make_owner_with_plot();

		$owner_connection_id = null;
		foreach ( Connection::for_source( 'plot', $plot_id ) as $connection ) {
			if ( (int) $connection->target_id === $owner_character_id ) {
				$owner_connection_id = (int) $connection->id;
			}
		}
		$this->assertNotNull( $owner_connection_id, 'ensure_plot() must have written the owner\'s own connection' );

		wp_set_current_user( $this->make_manager() );
		$remove = $this->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members/{$owner_connection_id}" ) );

		$this->assertSame( 404, $remove->get_status(), 'a Storyteller too - the owner link is never a plot_member connection' );
		$this->assertNotNull( Connection::find( $owner_connection_id ), 'the owner connection itself must be untouched' );
	}

	// -------------------------------------------------------------------------
	// Entries_Controller has its own, separately-maintained is_unowned_allocation()
	// copy - proving membership works there too, not just on the plot header.
	// -------------------------------------------------------------------------

	public function test_an_invited_member_can_read_and_post_entries_on_someone_elses_action_plot(): void {
		[ $owner, , $plot_id ] = $this->make_owner_with_plot();
		$member_player = $this->make_player();
		$member_id     = $this->make_character( $member_player );

		wp_set_current_user( $owner );
		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members" );
		$add->set_param( 'character_id', $member_id );
		$this->dispatch( $add );

		wp_set_current_user( $member_player );
		$this->assertSame(
			200,
			$this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" ) )->get_status()
		);

		$post = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$post->set_param( 'entry_type', 'action' );
		$post->set_param( 'content', 'The co-narrator does a thing.' );
		$this->assertSame( 201, $this->dispatch( $post )->get_status() );
	}

	public function test_an_uninvited_stranger_still_cannot_read_entries_on_someone_elses_action_plot(): void {
		[ , , $plot_id ] = $this->make_owner_with_plot();
		$stranger = $this->make_player();
		$this->make_character( $stranger );

		wp_set_current_user( $stranger );
		$this->assertSame(
			404,
			$this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" ) )->get_status()
		);
	}

	public function test_removing_a_connection_from_a_different_plot_404s(): void {
		[ $owner, , $plot_id ] = $this->make_owner_with_plot();
		[ , , $other_plot_id ] = $this->make_owner_with_plot( 'A Different Owner' );
		$invitee_id = $this->make_character( $this->make_player() );

		wp_set_current_user( $this->make_manager() );
		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$other_plot_id}/members" );
		$add->set_param( 'character_id', $invitee_id );
		$connection_id = $this->dispatch( $add )->get_data()->id;

		wp_set_current_user( $owner );
		$remove = $this->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members/{$connection_id}" ) );
		$this->assertSame( 404, $remove->get_status() );
	}

	// -------------------------------------------------------------------------
	// is_owner - the client's own signal for whether to show upload/delete
	// controls (Attachments_Controller::may_manage_attachments() mirrors this
	// exact check, so a client gating on it never shows a control the server
	// would then 403).
	// -------------------------------------------------------------------------

	public function test_is_owner_is_true_for_the_plots_own_owner(): void {
		[ $owner, , $plot_id ] = $this->make_owner_with_plot();

		wp_set_current_user( $owner );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" ) )->get_data();

		$this->assertTrue( $data->is_owner );
	}

	public function test_is_owner_is_false_for_an_invited_member(): void {
		[ $owner, , $plot_id ] = $this->make_owner_with_plot();
		$member_player = $this->make_player();
		$member_id     = $this->make_character( $member_player );

		wp_set_current_user( $owner );
		$add = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members" );
		$add->set_param( 'character_id', $member_id );
		$this->dispatch( $add );

		wp_set_current_user( $member_player );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" ) )->get_data();

		$this->assertFalse( $data->is_owner );
	}

	public function test_is_owner_is_false_for_a_manager(): void {
		[ , , $plot_id ] = $this->make_owner_with_plot();

		wp_set_current_user( $this->make_manager() );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" ) )->get_data();

		$this->assertFalse( $data->is_owner );
	}
}
