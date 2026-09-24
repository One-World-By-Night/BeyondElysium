<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Game sessions, sign-in attendance, and attendance XP.
 */
class GameSessionsThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-sessions';
	private int $game_id;
	private string $other_game_slug = 'thread-sessions-other';
	private int $other_game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		foreach ( [ $this->game_slug => 'game_id', $this->other_game_slug => 'other_game_id' ] as $slug => $prop ) {
			$wpdb->insert( $wpdb->prefix . 'be_games', [
				'slug'       => $slug,
				'name'       => 'Thread Sessions ' . $slug,
				'created_by' => 1,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
				'settings'   => wp_json_encode( [] ),
			] );
			$this->$prop = (int) $wpdb->insert_id;
		}
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

	private function make_narrator(): int {
		$narrator = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $narrator, 'narrator' );
		return $narrator;
	}

	private function make_character( string $owner_slug, array $overrides = [] ): int {
		return (int) Character::create( array_merge( [
			'name'       => 'Fixture Character',
			'stack_slug' => 'vampire',
			'owner_slug' => $owner_slug,
			'created_by' => 1,
		], $overrides ) );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function make_session( string $game_date = '2026-10-02' ): int {
		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions" );
		$request->set_param( 'game_date', $game_date );
		return $this->dispatch( $request )->get_data()->id;
	}

	// -------------------------------------------------------------------------
	// create_item()
	// -------------------------------------------------------------------------

	public function test_a_narrator_can_create_a_session(): void {
		wp_set_current_user( $this->make_narrator() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions" );
		$request->set_param( 'game_date', '2026-10-02' );
		$request->set_param( 'place', "Marcy's Diner" );

		$response = $this->dispatch( $request );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( "Marcy's Diner", $response->get_data()->place );
	}

	public function test_a_player_cannot_create_a_session(): void {
		wp_set_current_user( $this->make_player() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions" );
		$request->set_param( 'game_date', '2026-10-02' );

		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	public function test_creating_a_duplicate_date_409s(): void {
		$this->make_session( '2026-10-02' );

		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions" );
		$request->set_param( 'game_date', '2026-10-02' );

		$this->assertSame( 409, $this->dispatch( $request )->get_status() );
	}

	public function test_get_items_strips_xp_awarded_fields_for_a_non_manager(): void {
		$this->make_session();

		wp_set_current_user( $this->make_player() );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/sessions" ) )->get_data();

		$this->assertFalse( property_exists( $data[0], 'attendance_xp_awarded_at' ) );
	}

	public function test_downtime_fields_are_dropped_for_a_caller_without_be_manage_apr(): void {
		// A narrator holds be_manage_sessions but not be_manage_apr.
		$session_id = $this->make_session();

		wp_set_current_user( $this->make_narrator() );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/sessions/{$session_id}" );
		$request->set_param( 'downtime_opens_at', '2026-10-01 00:00:00' );
		$data = $this->dispatch( $request )->get_data();

		$this->assertNull( $data->downtime_opens_at );
	}

	// -------------------------------------------------------------------------
	// delete_item()
	// -------------------------------------------------------------------------

	public function test_deleting_a_session_with_attendance_409s(): void {
		$session_id   = $this->make_session();
		$character_id = $this->make_character( $this->game_slug );

		wp_set_current_user( $this->make_manager() );
		$attend = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/attendance" );
		$attend->set_param( 'character_id', $character_id );
		$this->dispatch( $attend );

		$delete = $this->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/sessions/{$session_id}" ) );
		$this->assertSame( 409, $delete->get_status() );
	}

	public function test_deleting_an_empty_session_succeeds(): void {
		$session_id = $this->make_session();
		wp_set_current_user( $this->make_manager() );

		$delete = $this->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/sessions/{$session_id}" ) );
		$this->assertSame( 204, $delete->get_status() );
	}

	// -------------------------------------------------------------------------
	// Attendance
	// -------------------------------------------------------------------------

	public function test_signing_in_the_same_character_twice_409s(): void {
		$session_id   = $this->make_session();
		$character_id = $this->make_character( $this->game_slug );
		wp_set_current_user( $this->make_manager() );

		$first = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/attendance" );
		$first->set_param( 'character_id', $character_id );
		$this->assertSame( 201, $this->dispatch( $first )->get_status() );

		$second = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/attendance" );
		$second->set_param( 'character_id', $character_id );
		$this->assertSame( 409, $this->dispatch( $second )->get_status() );
	}

	public function test_a_visitor_can_be_signed_in_by_name(): void {
		$session_id = $this->make_session();
		wp_set_current_user( $this->make_manager() );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/attendance" );
		$request->set_param( 'visitor_name', 'Ashford of the Other Chronicle' );
		$request->set_param( 'visitor_chronicle', 'Some Other Game' );
		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Ashford of the Other Chronicle', $response->get_data()->visitor_name );

		// A second visitor with no character_id at all must not collide with the first.
		$second = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/attendance" );
		$second->set_param( 'visitor_name', 'A Second Visitor' );
		$this->assertSame( 201, $this->dispatch( $second )->get_status() );
	}

	public function test_a_character_from_another_chronicle_is_refused(): void {
		$session_id = $this->make_session();
		$outsider_id = $this->make_character( $this->other_game_slug );
		wp_set_current_user( $this->make_manager() );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/attendance" );
		$request->set_param( 'character_id', $outsider_id );

		$this->assertSame( 404, $this->dispatch( $request )->get_status() );
	}

	public function test_an_npc_cannot_be_signed_in(): void {
		$session_id = $this->make_session();
		$npc_id     = $this->make_character( $this->game_slug, [ 'is_npc' => 1 ] );
		wp_set_current_user( $this->make_manager() );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/attendance" );
		$request->set_param( 'character_id', $npc_id );

		$this->assertSame( 400, $this->dispatch( $request )->get_status() );
	}

	public function test_removing_attendance(): void {
		$session_id   = $this->make_session();
		$character_id = $this->make_character( $this->game_slug );
		wp_set_current_user( $this->make_manager() );

		$attend = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/attendance" );
		$attend->set_param( 'character_id', $character_id );
		$attendance_id = $this->dispatch( $attend )->get_data()->id;

		$remove = $this->dispatch( new WP_REST_Request(
			'DELETE',
			"/be/v1/{$this->game_slug}/sessions/{$session_id}/attendance/{$attendance_id}"
		) );
		$this->assertSame( 204, $remove->get_status() );

		$list = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/sessions/{$session_id}/attendance" ) )->get_data();
		$this->assertSame( [], $list );
	}

	// -------------------------------------------------------------------------
	// award_attendance_xp()
	// -------------------------------------------------------------------------

	public function test_awarding_attendance_xp_lands_once_with_its_reason(): void {
		$session_id   = $this->make_session( '2026-10-02' );
		$character_id = $this->make_character( $this->game_slug );
		wp_set_current_user( $this->make_manager() );

		$attend = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/attendance" );
		$attend->set_param( 'character_id', $character_id );
		$this->dispatch( $attend );

		$award = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/award-attendance-xp" ) );
		$this->assertSame( 200, $award->get_status() );
		$this->assertSame( 1, $award->get_data()['awarded_count'] );

		$character = Character::find( $character_id );
		$this->assertSame( 1, (int) $character->xp_earned );
	}

	public function test_a_second_award_409s(): void {
		$session_id = $this->make_session();
		wp_set_current_user( $this->make_manager() );

		$this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/award-attendance-xp" ) );
		$second = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/award-attendance-xp" ) );

		$this->assertSame( 409, $second->get_status() );
	}

	public function test_force_allows_a_second_award(): void {
		$session_id = $this->make_session();
		wp_set_current_user( $this->make_manager() );

		$this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/award-attendance-xp" ) );

		$forced = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/award-attendance-xp" );
		$forced->set_param( 'force', true );

		$this->assertSame( 200, $this->dispatch( $forced )->get_status() );
	}

	public function test_award_amount_defaults_to_session_settings(): void {
		wp_set_current_user( $this->make_manager() );
		$settings = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/session-settings" );
		$settings->set_param( 'attendance_xp', 3 );
		$this->dispatch( $settings );

		$session_id   = $this->make_session();
		$character_id = $this->make_character( $this->game_slug );
		$attend       = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/attendance" );
		$attend->set_param( 'character_id', $character_id );
		$this->dispatch( $attend );

		$award = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/sessions/{$session_id}/award-attendance-xp" ) );
		$this->assertSame( 3, $award->get_data()['amount'] );
	}

	// -------------------------------------------------------------------------
	// session-settings
	// -------------------------------------------------------------------------

	public function test_session_settings_merge_without_replacing_other_settings(): void {
		wp_set_current_user( $this->make_manager() );

		$first = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/session-settings" );
		$first->set_param( 'attendance_xp', 2 );
		$this->dispatch( $first );

		$second = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/session-settings" );
		$second->set_param( 'report_xp', 5 );
		$data = $this->dispatch( $second )->get_data();

		$this->assertSame( 2, $data['sessions']['attendance_xp'] );
		$this->assertSame( 5, $data['sessions']['report_xp'] );
	}

	// -------------------------------------------------------------------------
	// release_schedule - a sibling of settings.sessions, saved and validated through the same route
	// -------------------------------------------------------------------------

	public function test_release_schedule_rules_are_saved_and_returned(): void {
		wp_set_current_user( $this->make_manager() );

		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/session-settings" );
		$request->set_param( 'release_schedule', [
			'rules' => [
				[ 'type' => 'weekly', 'weekday' => 'friday', 'time' => '18:00' ],
				[ 'type' => 'monthly', 'day_of_month' => 1, 'time' => '09:00' ],
			],
		] );
		$data = $this->dispatch( $request )->get_data();

		$this->assertCount( 2, $data['release_schedule']['rules'] );
		$this->assertSame( 'weekly', $data['release_schedule']['rules'][0]['type'] );
		$this->assertSame( 'friday', $data['release_schedule']['rules'][0]['weekday'] );
		$this->assertSame( 'monthly', $data['release_schedule']['rules'][1]['type'] );
		$this->assertSame( 1, $data['release_schedule']['rules'][1]['day_of_month'] );
	}

	public function test_a_malformed_release_schedule_rule_is_dropped_not_the_whole_request(): void {
		wp_set_current_user( $this->make_manager() );

		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/session-settings" );
		$request->set_param( 'release_schedule', [
			'rules' => [
				[ 'type' => 'weekly', 'weekday' => 'friday', 'time' => '18:00' ],
				[ 'type' => 'weekly', 'weekday' => 'not-a-real-day', 'time' => '18:00' ],
				[ 'type' => 'monthly', 'day_of_month' => 31, 'time' => '09:00' ],
			],
		] );
		$data = $this->dispatch( $request )->get_data();

		$this->assertCount( 1, $data['release_schedule']['rules'] );
		$this->assertSame( 'friday', $data['release_schedule']['rules'][0]['weekday'] );
	}

	public function test_saving_release_schedule_does_not_disturb_session_settings(): void {
		wp_set_current_user( $this->make_manager() );

		$first = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/session-settings" );
		$first->set_param( 'attendance_xp', 4 );
		$this->dispatch( $first );

		$second = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/session-settings" );
		$second->set_param( 'release_schedule', [
			'rules' => [ [ 'type' => 'weekly', 'weekday' => 'friday', 'time' => '18:00' ] ],
		] );
		$data = $this->dispatch( $second )->get_data();

		$this->assertSame( 4, $data['sessions']['attendance_xp'] );
		$this->assertCount( 1, $data['release_schedule']['rules'] );
	}
}
