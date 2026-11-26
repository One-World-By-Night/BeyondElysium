<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\After_Game_Report;
use BeyondElysium\Models\Attendance;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Discord_Link;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Join_Request;
use BeyondElysium\Models\Npc_Casting;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Action_Allocator;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A Storyteller's home: what waits on them, the next game night and who has had no attention, each part only for
 * someone who holds the capability behind it.
 */
class StorytellerHomeThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-storyteller-home';
	private int $game_id;
	private int $hst;
	private int $ast;
	private int $narrator;
	private int $boons;
	private int $player;
	private int $isolde;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id  = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Storyteller Home' ] );
		$this->hst      = $this->member( 'hst', 'editor' );
		$this->ast      = $this->member( 'ast', 'editor' );
		$this->narrator = $this->member( 'narrator', 'editor' );
		$this->boons    = $this->member( 'boons', 'editor' );
		$this->player   = $this->member( 'player' );
		$this->isolde   = $this->character( 'Isolde Marchetti', $this->player );
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
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/home/storyteller" ) );
	}

	private function stamp( string $modifier ): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( $modifier ) );
	}

	private function day( string $modifier ): string {
		return gmdate( 'Y-m-d', strtotime( $modifier ) );
	}

	private function pending( string $type = 'modify_trait' ): void {
		Change::create( [
			'character_id' => $this->isolde, 'change_type' => $type, 'status' => 'pending',
			'change_data' => [ 'trait' => [ 'name' => 'Occult' ] ], 'xp_cost' => 1, 'submitted_by' => $this->player,
		] );
	}

	/**
	 * A downtime plot for a night with an action posted and no answer, assigned to one person or to no one.
	 */
	private function downtime_plot( int $character_id, string $game_date, ?int $assigned_to ): int {
		$plot_id = (int) Action_Allocator::create_own_plot( Character::find( $character_id ), $game_date );
		if ( $assigned_to !== null ) {
			Plot::update( $plot_id, [ 'assigned_to' => $assigned_to ] );
		}
		Plot_Entry::create( [ 'plot_id' => $plot_id, 'entry_type' => 'action', 'content' => 'I do a thing.', 'author_id' => $this->player ] );
		return $plot_id;
	}

	// -- who sees what ------------------------------------------------------------------------

	public function test_a_player_is_refused(): void {
		$this->assertSame( 403, $this->home( $this->player )->get_status() );
	}

	public function test_an_hst_and_an_ast_see_every_part(): void {
		foreach ( [ $this->hst, $this->ast ] as $person ) {
			$data = $this->home( $person )->get_data();

			$this->assertNotNull( $data['waiting']['approvals'] );
			$this->assertNotNull( $data['waiting']['downtime'] );
			$this->assertNotNull( $data['waiting']['plots'] );
			$this->assertNotNull( $data['waiting']['players'] );
			$this->assertNotNull( $data['spotlight'] );
		}
	}

	public function test_a_narrator_sees_downtime_plots_and_the_night_and_no_approvals_or_players(): void {
		$data = $this->home( $this->narrator )->get_data();

		$this->assertNull( $data['waiting']['approvals'] );
		$this->assertNull( $data['waiting']['players'] );
		$this->assertNotNull( $data['waiting']['downtime'] );
		$this->assertNotNull( $data['waiting']['plots'] );
		$this->assertSame( [], $data['waiting']['castings'] );
		$this->assertNotNull( $data['spotlight'] );
	}

	public function test_the_boons_role_sees_castings_and_nothing_else(): void {
		$npc = $this->character( 'Ashkelon', 0, [ 'is_npc' => 1 ] );
		$session = (int) Game_Session::create( [ 'game_id' => $this->game_id, 'game_date' => $this->day( '+4 days' ), 'created_by' => $this->hst ] );
		Npc_Casting::create( [ 'session_id' => $session, 'game_id' => $this->game_id, 'character_id' => $npc, 'wp_user_id' => $this->boons, 'created_by' => $this->hst ] );

		$response = $this->home( $this->boons );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $data['waiting']['approvals'] );
		$this->assertNull( $data['waiting']['downtime'] );
		$this->assertNull( $data['waiting']['plots'] );
		$this->assertNull( $data['waiting']['players'] );
		$this->assertNull( $data['next_session'] );
		$this->assertNull( $data['spotlight'] );
		$this->assertSame( [ [ 'character_name' => 'Ashkelon', 'game_date' => $this->day( '+4 days' ) ] ], $data['waiting']['castings'] );
	}

	// -- what waits ---------------------------------------------------------------------------

	public function test_the_approval_line_counts_changes_and_xp_requests(): void {
		$this->pending();
		$this->pending();
		$this->pending( 'xp_adjust' );

		$approvals = $this->home( $this->hst )->get_data()['waiting']['approvals'];

		$this->assertSame( 3, $approvals['changes'] );
		$this->assertSame( 1, $approvals['xp_requests'] );
		$this->assertSame( 0, $approvals['files'] );
	}

	public function test_the_players_line_counts_join_requests_and_discord_links(): void {
		$applicant = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Join_Request::open( $this->game_id, $applicant, 'Hello' );
		$link = Discord_Link::start_or_renew( $this->game_id, '1', 'one#1' );
		Discord_Link::confirm( Discord_Link::find_by_live_token( $link['token'] ), $this->player, [ $this->isolde ], false );

		$players = $this->home( $this->hst )->get_data()['waiting']['players'];

		$this->assertSame( 1, $players['join_requests'] );
		$this->assertSame( 1, $players['links'] );
	}

	public function test_downtime_and_plot_posts_split_mine_from_nobody_has_taken(): void {
		$date = $this->day( '+5 days' );
		$other = $this->character( 'Second Character', $this->member( 'player' ) );
		$this->downtime_plot( $this->isolde, $date, $this->hst );
		$this->downtime_plot( $other, $date, null );

		$plot = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'The Chantry', 'created_by' => 1, 'audience' => 'everyone' ] );
		Plot::update( $plot, [ 'assigned_to' => $this->hst ] );
		Plot_Entry::create( [ 'plot_id' => $plot, 'entry_type' => 'action', 'content' => 'A player writes.', 'author_id' => $this->player ] );

		$waiting = $this->home( $this->hst )->get_data()['waiting'];

		$this->assertSame( [ 'mine' => 1, 'unassigned' => 1 ], $waiting['downtime'] );
		$this->assertSame( [ 'mine' => 1, 'unassigned' => 0 ], $waiting['plots'] );
	}

	public function test_what_waits_on_one_storyteller_is_not_counted_for_another(): void {
		$this->downtime_plot( $this->isolde, $this->day( '+5 days' ), $this->hst );

		$waiting = $this->home( $this->ast )->get_data()['waiting'];

		$this->assertSame( [ 'mine' => 0, 'unassigned' => 0 ], $waiting['downtime'] );
	}

	public function test_the_queue_is_kept_for_a_minute_per_person(): void {
		$date = $this->day( '+5 days' );
		$this->downtime_plot( $this->isolde, $date, $this->hst );
		$this->assertSame( 1, $this->home( $this->hst )->get_data()['waiting']['downtime']['mine'] );

		$other = $this->character( 'Second Character', $this->member( 'player' ) );
		$this->downtime_plot( $other, $date, $this->hst );

		$this->assertSame( 1, $this->home( $this->hst )->get_data()['waiting']['downtime']['mine'], 'a second read inside the minute is the first read' );

		delete_transient( sprintf( 'be_home_queue_%d_%d', $this->game_id, $this->hst ) );
		$this->assertSame( 2, $this->home( $this->hst )->get_data()['waiting']['downtime']['mine'] );
	}

	// -- the night ----------------------------------------------------------------------------

	public function test_the_next_night_counts_who_has_sent_downtime(): void {
		$date = $this->day( '+5 days' );
		$deadline = $this->stamp( '+2 days' );
		$session  = (int) Game_Session::create( [
			'game_id' => $this->game_id, 'game_date' => $date, 'created_by' => $this->hst,
			'start_time' => '7pm', 'place' => 'The Chantry',
			'downtime_opens_at' => $this->stamp( '-1 day' ), 'downtime_deadline_at' => $deadline,
		] );
		$second = $this->character( 'Second Character', $this->member( 'player' ) );
		$this->character( 'Third Character', $this->member( 'player' ) );
		$this->character( 'Not Counted', 0, [ 'is_npc' => 1 ] );
		$this->downtime_plot( $this->isolde, $date, null );
		Action_Allocator::create_own_plot( Character::find( $second ), $date );

		$night = $this->home( $this->hst )->get_data()['next_session'];

		$this->assertSame( $session, $night['id'] );
		$this->assertSame( 'The Chantry', $night['place'] );
		$this->assertSame( $deadline, $night['downtime_deadline_at'] );
		$this->assertSame( [ 'sent' => 1, 'total' => 3 ], $night['downtime'] );
		$this->assertNull( $night['reports'] );
	}

	public function test_a_night_with_no_downtime_window_has_no_progress(): void {
		Game_Session::create( [ 'game_id' => $this->game_id, 'game_date' => $this->day( '+5 days' ), 'created_by' => $this->hst ] );

		$this->assertNull( $this->home( $this->hst )->get_data()['next_session']['downtime'] );
	}

	public function test_reports_count_who_came_and_who_has_written(): void {
		Game_Session::create( [ 'game_id' => $this->game_id, 'game_date' => $this->day( '+5 days' ), 'created_by' => $this->hst ] );
		$past   = (int) Game_Session::create( [
			'game_id' => $this->game_id, 'game_date' => $this->day( '-3 days' ), 'created_by' => $this->hst,
			'reports_due_at' => $this->stamp( '+2 days' ),
		] );
		$second = $this->character( 'Second Character', $this->member( 'player' ) );
		Attendance::record( $past, $this->game_id, [ 'character_id' => $this->isolde, 'recorded_by' => $this->hst ] );
		Attendance::record( $past, $this->game_id, [ 'character_id' => $second, 'recorded_by' => $this->hst ] );
		After_Game_Report::create( [ 'game_id' => $this->game_id, 'session_id' => $past, 'character_id' => $second, 'wp_user_id' => 0, 'did' => 'Did.' ] );

		$reports = $this->home( $this->hst )->get_data()['next_session']['reports'];

		$this->assertSame( $this->day( '-3 days' ), $reports['game_date'] );
		$this->assertSame( 2, $reports['attended'] );
		$this->assertSame( 1, $reports['written'] );
	}

	public function test_with_no_night_ahead_there_is_none(): void {
		Game_Session::create( [ 'game_id' => $this->game_id, 'game_date' => $this->day( '-9 days' ), 'created_by' => $this->hst ] );

		$this->assertNull( $this->home( $this->hst )->get_data()['next_session'] );
	}

	// -- spotlight ----------------------------------------------------------------------------

	public function test_spotlight_names_characters_with_no_storyteller_post(): void {
		$spotlight = $this->home( $this->hst )->get_data()['spotlight'];

		$this->assertSame( 1, $spotlight['flagged'] );
		$this->assertSame( 42, $spotlight['days'] );
		$this->assertSame( [ 'Isolde Marchetti' ], $spotlight['names'] );
	}

	public function test_the_stats_card_and_the_home_read_one_kept_spotlight_that_a_change_drops(): void {
		$this->home( $this->hst );
		$this->assertIsArray( get_transient( 'be_spotlight_' . $this->slug ) );

		\BeyondElysium\REST\Game_Stats_Controller::invalidate( $this->slug );

		$this->assertFalse( get_transient( 'be_spotlight_' . $this->slug ) );
	}

	public function test_spotlight_lists_at_most_five_names(): void {
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->character( "Extra {$i}", $this->member( 'player' ) );
		}

		$spotlight = $this->home( $this->hst )->get_data()['spotlight'];

		$this->assertSame( 7, $spotlight['flagged'] );
		$this->assertCount( 5, $spotlight['names'] );
	}
}
