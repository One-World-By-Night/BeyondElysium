<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The Game Calendar report reads real Game_Session rows.
 */
class GameCalendarReportThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-game-calendar';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Game Calendar',
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

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_an_empty_chronicle_gets_the_honest_empty_state(): void {
		wp_set_current_user( $this->make_player() );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/reports/game-calendar" ) )->get_data();

		$this->assertSame( [], $data['rows'] );
		$this->assertNotEmpty( $data['note'] );
	}

	public function test_a_real_session_appears_as_a_row(): void {
		Game_Session::create( [
			'game_id'    => $this->game_id,
			'game_date'  => '2026-10-02',
			'start_time' => '7pm',
			'place'      => "Marcy's Diner",
			'notes'      => 'Bring snacks.',
			'created_by' => 1,
		] );

		wp_set_current_user( $this->make_player() );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/reports/game-calendar" ) )->get_data();

		$this->assertCount( 1, $data['rows'] );
		$this->assertSame( '2026-10-02', $data['rows'][0]['date'] );
		$this->assertSame( "Marcy's Diner", $data['rows'][0]['place'] );
		$this->assertSame( '', $data['note'], 'the empty note is only shown when there really are no sessions' );
	}

	public function test_st_marked_notes_are_stripped_for_a_non_manager(): void {
		Game_Session::create( [
			'game_id'    => $this->game_id,
			'game_date'  => '2026-10-02',
			'notes'      => 'Public part. [ST]Secret ST part.[/ST]',
			'created_by' => 1,
		] );

		wp_set_current_user( $this->make_player() );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/reports/game-calendar" ) )->get_data();

		$this->assertStringNotContainsString( 'Secret ST part', $data['rows'][0]['notes'] );
		$this->assertStringContainsString( 'Public part', $data['rows'][0]['notes'] );
	}
}
