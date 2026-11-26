<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\After_Game_Report;
use BeyondElysium\Models\Attendance;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Notice;
use BeyondElysium\Models\Npc_Casting;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Action_Allocator;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A player's home: their own characters with what they have to spend, what is due, what is new and what is next.
 */
class PlayerHomeThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-player-home';
	private int $game_id;
	private int $hst;
	private int $player;
	private int $isolde;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Player Home' ] );
		$this->hst     = $this->member( 'hst', 'editor' );
		$this->player  = $this->member( 'player' );
		$this->isolde  = $this->character( 'Isolde Marchetti', $this->player );
		Character::update_xp( $this->isolde, 30, 10 );
	}

	private function member( string $role, string $wp_role = 'subscriber' ): int {
		$user = self::factory()->user->create( [ 'role' => $wp_role ] );
		Game_Member::set_role( $this->game_id, $user, $role );
		return $user;
	}

	private function character( string $name, int $owner, array $extra = [] ): int {
		return (int) Character::create( array_merge( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_slug' => $this->slug,
			'wp_user_id' => $owner, 'status' => 'active', 'created_by' => 1,
		], $extra ) );
	}

	private function home( int $as ) {
		wp_set_current_user( $as );
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/home/player" ) );
	}

	private function today( string $modifier = 'today' ): string {
		return gmdate( 'Y-m-d', strtotime( $modifier ) );
	}

	private function stamp( string $modifier ): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( $modifier ) );
	}

	private function session( array $fields ): int {
		return (int) Game_Session::create( array_merge( [ 'game_id' => $this->game_id, 'created_by' => $this->hst ], $fields ) );
	}

	private function send_actions( int $character_id, string $game_date ): void {
		$character = Character::find( $character_id );
		$plot_id   = (int) Action_Allocator::create_own_plot( $character, $game_date );
		Plot_Entry::create( [ 'plot_id' => $plot_id, 'entry_type' => 'action', 'content' => 'I do a thing.', 'author_id' => (int) $character->wp_user_id ] );
	}

	// -- the cards ----------------------------------------------------------------------------

	public function test_the_cards_carry_xp_held_xp_and_waiting_changes(): void {
		Change::create( [
			'character_id' => $this->isolde, 'change_type' => 'modify_trait', 'status' => 'pending',
			'change_data' => [ 'trait' => [ 'name' => 'Occult' ] ], 'xp_cost' => 4, 'submitted_by' => $this->player,
		] );

		$response = $this->home( $this->player );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( current_time( 'Y-m-d' ), $response->get_data()['today'] );
		$cards = $response->get_data()['characters'];
		$this->assertCount( 1, $cards );
		$this->assertSame( 'Isolde Marchetti', $cards[0]['name'] );
		$this->assertSame( 10, $cards[0]['xp_unspent'] );
		$this->assertSame( 4, $cards[0]['xp_held'] );
		$this->assertSame( 1, $cards[0]['waiting'] );
	}

	public function test_only_the_callers_own_player_characters_appear(): void {
		$other = $this->member( 'player' );
		$this->character( 'Someone Else', $other );
		$this->character( 'Sabbat NPC', $this->player, [ 'is_npc' => 1 ] );

		$names = array_column( $this->home( $this->player )->get_data()['characters'], 'name' );

		$this->assertSame( [ 'Isolde Marchetti' ], $names );
	}

	public function test_a_storyteller_gets_their_own_characters_here_and_not_their_npcs(): void {
		$own = $this->character( 'Hst Character', $this->hst );
		$this->character( 'Hst Npc', $this->hst, [ 'is_npc' => 1 ] );

		$cards = $this->home( $this->hst )->get_data()['characters'];

		$this->assertSame( [ $own ], array_column( $cards, 'id' ) );
	}

	public function test_someone_outside_the_chronicle_is_refused(): void {
		$outsider = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertSame( 403, $this->home( $outsider )->get_status() );
	}

	// -- downtime -----------------------------------------------------------------------------

	public function test_an_open_window_with_nothing_sent_is_due(): void {
		$date     = $this->today( '+5 days' );
		$deadline = $this->stamp( '+2 days' );
		$this->session( [ 'game_date' => $date, 'downtime_opens_at' => $this->stamp( '-1 day' ), 'downtime_deadline_at' => $deadline ] );

		$due = $this->home( $this->player )->get_data()['due']['downtime'];

		$this->assertCount( 1, $due );
		$this->assertSame( $this->isolde, $due[0]['character_id'] );
		$this->assertSame( $date, $due[0]['game_date'] );
		$this->assertSame( $deadline, $due[0]['deadline'] );
		$this->assertNull( $due[0]['plot_id'] );
	}

	public function test_a_character_that_sent_actions_is_not_due(): void {
		$date = $this->today( '+5 days' );
		$this->session( [ 'game_date' => $date, 'downtime_opens_at' => $this->stamp( '-1 day' ), 'downtime_deadline_at' => $this->stamp( '+2 days' ) ] );
		$this->send_actions( $this->isolde, $date );

		$this->assertSame( [], $this->home( $this->player )->get_data()['due']['downtime'] );
	}

	public function test_a_window_that_is_closed_or_not_open_yet_is_not_due(): void {
		$this->session( [ 'game_date' => $this->today( '+5 days' ), 'downtime_opens_at' => $this->stamp( '-3 days' ), 'downtime_deadline_at' => $this->stamp( '-1 day' ) ] );
		$this->session( [ 'game_date' => $this->today( '+12 days' ), 'downtime_opens_at' => $this->stamp( '+3 days' ), 'downtime_deadline_at' => $this->stamp( '+9 days' ) ] );

		$this->assertSame( [], $this->home( $this->player )->get_data()['due']['downtime'] );
	}

	public function test_a_session_with_no_window_is_not_due(): void {
		$this->session( [ 'game_date' => $this->today( '+5 days' ) ] );

		$this->assertSame( [], $this->home( $this->player )->get_data()['due']['downtime'] );
	}

	public function test_an_extension_replaces_the_deadline_for_that_character_only(): void {
		$date      = $this->today( '+5 days' );
		$extension = $this->stamp( '+1 day' );
		$this->session( [
			'game_date' => $date, 'downtime_opens_at' => $this->stamp( '-3 days' ), 'downtime_deadline_at' => $this->stamp( '-1 day' ),
			'downtime_extensions' => [ (string) $this->isolde => $extension ],
		] );
		$other_player = $this->member( 'player' );
		$this->character( 'No Extension', $other_player );

		$mine   = $this->home( $this->player )->get_data()['due']['downtime'];
		$theirs = $this->home( $other_player )->get_data()['due']['downtime'];

		$this->assertCount( 1, $mine );
		$this->assertSame( $extension, $mine[0]['deadline'] );
		$this->assertSame( [], $theirs );
	}

	public function test_an_earlier_extension_closes_the_window_for_that_character(): void {
		$this->session( [
			'game_date' => $this->today( '+5 days' ), 'downtime_opens_at' => $this->stamp( '-3 days' ), 'downtime_deadline_at' => $this->stamp( '+2 days' ),
			'downtime_extensions' => [ (string) $this->isolde => $this->stamp( '-1 hour' ) ],
		] );

		$this->assertSame( [], $this->home( $this->player )->get_data()['due']['downtime'] );
	}

	public function test_an_inactive_character_is_never_due_for_downtime(): void {
		Character::update_header( $this->isolde, [ 'status' => 'retired' ] );
		$this->session( [ 'game_date' => $this->today( '+5 days' ), 'downtime_opens_at' => $this->stamp( '-1 day' ), 'downtime_deadline_at' => $this->stamp( '+2 days' ) ] );

		$data = $this->home( $this->player )->get_data();

		$this->assertSame( [], $data['due']['downtime'] );
		$this->assertSame( 'retired', $data['characters'][0]['status'] );
	}

	// -- reports ------------------------------------------------------------------------------

	private function attended_session( ?string $due_at, string $days_ago = '-3 days' ): int {
		$id = $this->session( [ 'game_date' => $this->today( $days_ago ), 'reports_due_at' => $due_at ] );
		Attendance::record( $id, $this->game_id, [ 'character_id' => $this->isolde, 'recorded_by' => $this->hst ] );
		return $id;
	}

	public function test_a_report_is_due_for_an_attended_session_until_it_is_written(): void {
		$session = $this->attended_session( $this->stamp( '+2 days' ) );

		$due = $this->home( $this->player )->get_data()['due']['reports'];
		$this->assertCount( 1, $due );
		$this->assertSame( $session, $due[0]['session_id'] );
		$this->assertSame( $this->isolde, $due[0]['character_id'] );

		After_Game_Report::create( [ 'game_id' => $this->game_id, 'session_id' => $session, 'character_id' => $this->isolde, 'wp_user_id' => $this->player, 'did' => 'Did a thing.' ] );
		$this->assertSame( [], $this->home( $this->player )->get_data()['due']['reports'] );
	}

	public function test_no_report_is_due_without_attendance_or_after_the_due_date_or_with_none_set(): void {
		$this->session( [ 'game_date' => $this->today( '-4 days' ), 'reports_due_at' => $this->stamp( '+2 days' ) ] );
		$this->attended_session( $this->stamp( '-1 hour' ), '-6 days' );
		$this->attended_session( null, '-8 days' );

		$this->assertSame( [], $this->home( $this->player )->get_data()['due']['reports'] );
	}

	// -- what is next -------------------------------------------------------------------------

	public function test_the_next_session_carries_the_persons_own_castings(): void {
		$npc  = $this->character( 'Ashkelon', 0, [ 'is_npc' => 1 ] );
		$next = $this->session( [ 'game_date' => $this->today( '+6 days' ), 'start_time' => '7pm', 'place' => 'The Chantry' ] );
		$later = $this->session( [ 'game_date' => $this->today( '+20 days' ) ] );
		Npc_Casting::create( [ 'session_id' => $next, 'game_id' => $this->game_id, 'character_id' => $npc, 'wp_user_id' => $this->player, 'created_by' => $this->hst ] );
		Npc_Casting::create( [ 'session_id' => $later, 'game_id' => $this->game_id, 'character_id' => $npc, 'wp_user_id' => $this->player, 'created_by' => $this->hst ] );

		$session = $this->home( $this->player )->get_data()['next_session'];

		$this->assertSame( $next, $session['id'] );
		$this->assertSame( 'The Chantry', $session['place'] );
		$this->assertSame( [ 'Ashkelon' ], $session['castings'] );
	}

	public function test_with_no_session_ahead_there_is_no_next_session(): void {
		$this->session( [ 'game_date' => $this->today( '-9 days' ) ] );

		$this->assertNull( $this->home( $this->player )->get_data()['next_session'] );
	}

	public function test_the_latest_plots_are_the_callers_own_and_at_most_five(): void {
		for ( $i = 1; $i <= 6; $i++ ) {
			$plot = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => "Plot {$i}", 'created_by' => 1, 'audience' => 'everyone' ] );
			Connection::create( [
				'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $plot,
				'target_type' => 'character', 'target_id' => $this->isolde, 'label' => 'plot_member', 'created_by' => 1,
			] );
		}
		$stranger = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'Not Mine', 'created_by' => 1, 'audience' => 'everyone' ] );
		$this->assertGreaterThan( 0, $stranger );

		$plots = $this->home( $this->player )->get_data()['plots'];

		$this->assertCount( 5, $plots );
		$this->assertNotContains( 'Not Mine', array_column( $plots, 'title' ) );
	}

	// -- notices ------------------------------------------------------------------------------

	public function test_the_newest_unread_notices_come_with_the_unread_count(): void {
		for ( $i = 1; $i <= 7; $i++ ) {
			Notice::add( $this->game_id, $this->player, 'plot_post', [ 'plot_title' => "Plot {$i}" ] );
		}
		$read = Notice::add( $this->game_id, $this->player, 'plot_post', [ 'plot_title' => 'Read One' ] );
		Notice::mark_read( $this->game_id, $this->player, [ $read ] );
		Notice::add( $this->game_id, $this->hst, 'plot_post', [ 'plot_title' => 'Not Yours' ] );

		$notices = $this->home( $this->player )->get_data()['notices'];

		$this->assertSame( 7, $notices['unread'] );
		$this->assertCount( 5, $notices['items'] );
		$this->assertSame( 'Plot 7', $notices['items'][0]->payload['plot_title'] );
		$this->assertNotContains( 'Read One', array_map( static fn( $n ) => $n->payload['plot_title'], $notices['items'] ) );
	}
}
