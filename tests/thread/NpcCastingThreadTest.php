<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Npc_Casting;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 §3.8: a chronicle member cast to play an NPC for one session, and the brief they read
 * about it - the NPC's real and public name, the session's date/time/place, its resolved
 * sections with every Storyteller-only block removed except `npc-roleplaying-notes`, and the
 * casting's own free-text brief. The access window opens at casting and stays open through
 * the day after the session's game_date.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.8
 */
class NpcCastingThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-npc-casting';
	private int $game_id;
	private int $storyteller_id;
	private int $cast_player_id;
	private int $other_player_id;
	private int $npc_id;
	private int $session_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread NPC Casting',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );

		$this->cast_player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->cast_player_id, 'player' );

		$this->other_player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->other_player_id, 'player' );

		$this->npc_id = (int) Character::create( [
			'name'       => 'The Bartender',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug,
			'is_npc'     => 1,
			'created_by' => $this->storyteller_id,
			'sheet_data' => [
				'npc-roleplaying-notes' => [ 'Wants' => 'Free the city', 'Knows' => 'Everything' ],
			],
		] );

		$this->session_id = (int) Game_Session::create( [
			'game_id'    => $this->game_id,
			'game_date'  => current_time( 'Y-m-d' ),
			'created_by' => $this->storyteller_id,
		] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function cast( int $session_id, int $character_id, int $wp_user_id, ?string $brief = null ): \WP_REST_Response {
		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/castings" );
		$request->set_param( 'session_id', $session_id );
		$request->set_param( 'character_id', $character_id );
		$request->set_param( 'wp_user_id', $wp_user_id );
		if ( $brief !== null ) {
			$request->set_param( 'brief', $brief );
		}
		return $this->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Creation and validation.
	// -------------------------------------------------------------------------

	public function test_a_manager_can_cast_a_member_to_play_an_npc(): void {
		$response = $this->cast( $this->session_id, $this->npc_id, $this->cast_player_id, 'Play them as tired tonight.' );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $this->cast_player_id, (int) $response->get_data()->wp_user_id );
		$this->assertSame( 'Play them as tired tonight.', $response->get_data()->brief );
	}

	public function test_casting_a_non_npc_is_rejected(): void {
		$pc = (int) Character::create( [
			'name' => 'A PC', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug, 'created_by' => $this->storyteller_id,
		] );

		$response = $this->cast( $this->session_id, $pc, $this->cast_player_id );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'not_an_npc', $response->get_data()['code'] );
	}

	public function test_casting_a_non_member_is_rejected(): void {
		$stranger = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$response = $this->cast( $this->session_id, $this->npc_id, $stranger );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'not_a_member', $response->get_data()['code'] );
	}

	public function test_casting_the_same_npc_twice_at_one_session_is_refused(): void {
		$this->cast( $this->session_id, $this->npc_id, $this->cast_player_id );
		$response = $this->cast( $this->session_id, $this->npc_id, $this->other_player_id );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'already_cast', $response->get_data()['code'] );
	}

	// -------------------------------------------------------------------------
	// Listing.
	// -------------------------------------------------------------------------

	public function test_a_manager_sees_every_casting_a_player_sees_only_their_own(): void {
		$npc_two = (int) Character::create( [
			'name' => 'The Bouncer', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug, 'is_npc' => 1, 'created_by' => $this->storyteller_id,
		] );
		$this->cast( $this->session_id, $this->npc_id, $this->cast_player_id );
		$this->cast( $this->session_id, $npc_two, $this->other_player_id );

		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/castings" );
		$request->set_param( 'session_id', $this->session_id );
		$this->assertCount( 2, $this->dispatch( $request )->get_data() );

		wp_set_current_user( $this->cast_player_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/castings" );
		$request->set_param( 'session_id', $this->session_id );
		$mine = $this->dispatch( $request )->get_data();
		$this->assertCount( 1, $mine );
		$this->assertSame( $this->cast_player_id, (int) $mine[0]->wp_user_id );
	}

	// -------------------------------------------------------------------------
	// The brief and its access window.
	// -------------------------------------------------------------------------

	public function test_the_cast_player_reads_the_brief_with_roleplaying_notes_and_agenda_intact(): void {
		$casting_id = $this->cast( $this->session_id, $this->npc_id, $this->cast_player_id, 'Prep note.' )->get_data()->id;

		wp_set_current_user( $this->cast_player_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/castings/{$casting_id}/brief" );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$slugs = array_column( $data['sections'], 'block_slug' );
		$this->assertContains( 'npc-roleplaying-notes', $slugs );

		$notes_section = current( array_filter( $data['sections'], static fn( $s ) => $s['block_slug'] === 'npc-roleplaying-notes' ) );
		$this->assertNotFalse( $notes_section );
		$found_wants = false;
		foreach ( $notes_section['rows'] as $row ) {
			if ( str_contains( $row, 'Wants: Free the city' ) ) {
				$found_wants = true;
			}
		}
		$this->assertTrue( $found_wants, 'the brief must include the agenda field values' );

		$this->assertSame( [ [ 'Brief for this game', 'Prep note.' ] ], $data['prose'] );
	}

	public function test_a_different_player_cannot_read_the_brief(): void {
		$casting_id = $this->cast( $this->session_id, $this->npc_id, $this->cast_player_id )->get_data()->id;

		wp_set_current_user( $this->other_player_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/castings/{$casting_id}/brief" );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_the_access_window_closes_the_day_after_the_session(): void {
		$casting_id = $this->cast( $this->session_id, $this->npc_id, $this->cast_player_id )->get_data()->id;
		Game_Session::update( $this->session_id, [ 'game_date' => '2020-01-01' ] );

		wp_set_current_user( $this->cast_player_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/castings/{$casting_id}/brief" );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_manager_can_always_read_the_brief_even_after_the_window_closes(): void {
		$casting_id = $this->cast( $this->session_id, $this->npc_id, $this->cast_player_id )->get_data()->id;
		Game_Session::update( $this->session_id, [ 'game_date' => '2020-01-01' ] );

		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/castings/{$casting_id}/brief" );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_the_brief_pdf_is_served_to_the_cast_player_and_denied_to_others(): void {
		$casting_id = $this->cast( $this->session_id, $this->npc_id, $this->cast_player_id )->get_data()->id;

		wp_set_current_user( $this->cast_player_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/castings/{$casting_id}/brief.pdf" );
		$response = $this->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['bytes'] );

		wp_set_current_user( $this->other_player_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/castings/{$casting_id}/brief.pdf" );
		$response = $this->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Update, delete, and the session's own is_in_use() gate.
	// -------------------------------------------------------------------------

	public function test_updating_a_casting_changes_the_cast_member(): void {
		$casting_id = $this->cast( $this->session_id, $this->npc_id, $this->cast_player_id )->get_data()->id;

		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/castings/{$casting_id}" );
		$request->set_param( 'wp_user_id', $this->other_player_id );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->other_player_id, (int) Npc_Casting::find( $casting_id )->wp_user_id );
	}

	public function test_deleting_a_casting(): void {
		$casting_id = $this->cast( $this->session_id, $this->npc_id, $this->cast_player_id )->get_data()->id;

		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/castings/{$casting_id}" );
		$response = $this->dispatch( $request );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( Npc_Casting::find( $casting_id ) );
	}

	public function test_a_session_with_a_casting_cannot_be_deleted(): void {
		$this->cast( $this->session_id, $this->npc_id, $this->cast_player_id );

		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/sessions/{$this->session_id}" );
		$response = $this->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// my-upcoming and the broad member picker.
	// -------------------------------------------------------------------------

	public function test_my_upcoming_castings_lists_a_future_casting_for_a_plain_player(): void {
		$this->cast( $this->session_id, $this->npc_id, $this->cast_player_id );

		wp_set_current_user( $this->cast_player_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/castings/my-upcoming" );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( 'The Bartender', $response->get_data()[0]->character_name );
	}

	public function test_get_eligible_members_lists_every_role_not_just_staff(): void {
		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/castings/members" );
		$response = $this->dispatch( $request );

		$ids = array_column( $response->get_data(), 'id' );
		$this->assertContains( $this->cast_player_id, $ids );
		$this->assertContains( $this->storyteller_id, $ids );
	}

	public function test_staff_queue_castings_section_includes_an_upcoming_casting(): void {
		$this->cast( $this->session_id, $this->npc_id, $this->storyteller_id );

		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/my/queue" );
		$response = $this->dispatch( $request );

		$this->assertCount( 1, $response->get_data()['castings'] );
	}
}
