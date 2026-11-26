<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\User_Settings;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Notice;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Notices;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * What the site tells a person in its own header: written once for each person an action is for, whatever their email
 * choice, and read and marked read by that person alone.
 */
class NoticesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-notices';
	private int $game_id;
	private int $character_id;
	private int $player_id;
	private int $other_id;
	private int $hst_id;
	private int $ast_id;
	private int $narrator_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => 'Thread Notices',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$make = function ( string $role, string $wp_role ): int {
			$id = self::factory()->user->create( [ 'role' => $wp_role ] );
			Game_Member::set_role( $this->game_id, $id, $role );
			return $id;
		};
		$this->player_id   = $make( 'player', 'subscriber' );
		$this->other_id    = $make( 'player', 'subscriber' );
		$this->hst_id      = $make( 'hst', 'editor' );
		$this->ast_id      = $make( 'ast', 'editor' );
		$this->narrator_id = $make( 'narrator', 'editor' );

		$this->character_id = Character::create( [
			'name' => 'Notice Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id,
			'sheet_data' => [ 'vampire-merits' => [] ],
		] );
		Character::update_xp( $this->character_id, 20, 20 );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/** @return array<int,object> */
	private function notices_of( int $person ): array {
		return Notice::for_person( $this->game_id, $person, 100 );
	}

	/** @param string[] $names @return array<int,object> */
	private function send( array $names ): array {
		wp_set_current_user( $this->player_id );
		$changes = [];
		foreach ( $names as $name ) {
			$changes[] = [
				'change_type' => 'add_trait',
				'category'    => 'vampire-merits',
				'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => $name ] ],
			];
		}
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes/submit" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'changes' => $changes ] ) );
		$response = $this->dispatch( $request );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data()['changes'];
	}

	/** @param array<int,object> $changes */
	private function batch_approve( array $changes ): void {
		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/changes/batch-approve" );
		$request->set_param( 'change_ids', array_map( static fn( $c ) => (int) $c->id, $changes ) );
		$this->assertSame( 200, $this->dispatch( $request )->get_status() );
	}

	// --- Written once for each person an action is for ------------------------------------------------------------

	public function test_approving_five_changes_together_writes_one_notice_for_the_player(): void {
		$changes = $this->send( [ 'Daredevil', 'Lucky', 'Common Sense', 'Ability Aptitude', 'Natural Leader' ] );

		$this->batch_approve( $changes );

		$notices = array_values( array_filter( $this->notices_of( $this->player_id ), static fn( $n ) => $n->kind === 'changes_decided' ) );
		$this->assertCount( 1, $notices );
		$this->assertSame( 5, $notices[0]->payload['approved'] );
		$this->assertSame( 0, $notices[0]->payload['refused'] );
		$this->assertSame( 'Notice Test Character', $notices[0]->payload['character_name'] );
		$this->assertCount( 5, $notices[0]->payload['lines'] );
	}

	public function test_a_mixed_answer_counts_each_kind_and_carries_the_storytellers_note(): void {
		$changes = $this->send( [ 'Daredevil', 'Lucky' ] );
		wp_set_current_user( $this->hst_id );
		$yes = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/changes/{$changes[0]->id}" );
		$yes->set_param( 'status', 'approved' );
		$this->dispatch( $yes );
		$no = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/changes/{$changes[1]->id}" );
		$no->set_param( 'status', 'rejected' );
		$no->set_param( 'notes', 'Not at this stage. [ST]Watch the player.[/ST]' );
		$this->dispatch( $no );

		$notices = array_values( array_filter( $this->notices_of( $this->player_id ), static fn( $n ) => $n->kind === 'changes_decided' ) );
		$this->assertCount( 2, $notices, 'two decisions, two actions, two notices' );
		$refusal = array_values( array_filter( $notices, static fn( $n ) => $n->payload['refused'] === 1 ) )[0];
		$this->assertSame( 'refused', $refusal->payload['lines'][0]['status'] );
		$this->assertStringContainsString( 'Not at this stage.', $refusal->payload['lines'][0]['note'] );
		$this->assertStringNotContainsString( 'Watch the player', $refusal->payload['lines'][0]['note'] );
	}

	public function test_a_change_a_storyteller_edited_is_told_to_the_player_with_the_note(): void {
		$changes = $this->send( [ 'Daredevil' ] );
		$change  = Change::find( (int) $changes[0]->id );
		$character = Character::find( $this->character_id );

		Notices::edited( $change, $character, $this->hst_id, 'Priced at four.' );

		$notice = $this->notices_of( $this->player_id )[0];
		$this->assertSame( 'change_edited', $notice->kind );
		$this->assertSame( 'Priced at four.', $notice->payload['note'] );
		$this->assertStringContainsString( 'Daredevil', $notice->payload['text'] );
	}

	public function test_an_xp_award_is_told_to_the_player_but_a_take_back_is_not(): void {
		wp_set_current_user( $this->hst_id );

		Change_Engine::apply_xp( $this->character_id, 3, 'Game night', $this->hst_id );
		Change_Engine::apply_xp( $this->character_id, -2, 'Correction', $this->hst_id );

		$notices = $this->notices_of( $this->player_id );
		$this->assertCount( 1, $notices );
		$this->assertSame( 'xp_awarded', $notices[0]->kind );
		$this->assertSame( 3, $notices[0]->payload['amount'] );
		$this->assertSame( 'Game night', $notices[0]->payload['reason'] );
	}

	public function test_an_undo_tells_the_player(): void {
		$changes = $this->send( [ 'Daredevil' ] );
		$this->batch_approve( $changes );
		wp_set_current_user( $this->hst_id );

		$undo = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/changes/{$changes[0]->id}/undo" );
		$undo->set_param( 'note', 'Entered by mistake.' );
		$this->assertSame( 200, $this->dispatch( $undo )->get_status() );

		$kinds = array_map( static fn( $n ) => $n->kind, $this->notices_of( $this->player_id ) );
		$this->assertContains( 'changes_undone', $kinds );
		$undone = array_values( array_filter( $this->notices_of( $this->player_id ), static fn( $n ) => $n->kind === 'changes_undone' ) )[0];
		$this->assertSame( 1, $undone->payload['undone'] );
		$this->assertSame( 'Entered by mistake.', $undone->payload['lines'][0]['note'] );
	}

	// --- Staff ----------------------------------------------------------------------------------------------

	public function test_work_that_waits_is_told_once_to_each_hst_and_ast_and_to_no_narrator(): void {
		$this->send( [ 'Daredevil', 'Lucky' ] );

		foreach ( [ $this->hst_id, $this->ast_id ] as $staff ) {
			$notices = $this->notices_of( $staff );
			$this->assertCount( 1, $notices );
			$this->assertSame( 'changes_waiting', $notices[0]->kind );
			$this->assertSame( 2, $notices[0]->payload['waiting'] );
			$this->assertSame( 2, $notices[0]->payload['total'] );
		}
		$this->assertSame( [], $this->notices_of( $this->narrator_id ) );
		$this->assertSame( [], $this->notices_of( $this->other_id ) );
		$this->assertSame( [], $this->notices_of( $this->player_id ) );
	}

	public function test_work_that_applies_whole_tells_no_one(): void {
		Game::update( $this->slug, [ 'settings' => [ 'auto_approve' => true ] ] );

		$this->send( [ 'Common Sense' ] );

		$this->assertSame( [], $this->notices_of( $this->hst_id ) );
	}

	// --- Not held back by what holds back email -------------------------------------------------------------

	public function test_a_player_who_turned_email_off_still_gets_notices_and_so_does_a_chronicle_with_notifications_off(): void {
		update_user_meta( $this->player_id, User_Settings::NOTIFICATIONS_OPT_OUT_META, '1' );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_games', [ 'notifications_enabled' => 0 ], [ 'id' => $this->game_id ] );

		$changes = $this->send( [ 'Daredevil' ] );
		$this->batch_approve( $changes );

		$kinds = array_map( static fn( $n ) => $n->kind, $this->notices_of( $this->player_id ) );
		$this->assertContains( 'changes_decided', $kinds );
		$this->assertNotEmpty( $this->notices_of( $this->hst_id ), 'staff are told too' );
	}

	// --- The routes -----------------------------------------------------------------------------------------

	private function get_notices( int $as ) {
		wp_set_current_user( $as );
		return $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/notices" ) );
	}

	public function test_a_person_reads_their_own_unread_count_and_newest_notices(): void {
		for ( $i = 1; $i <= 35; $i++ ) {
			Notice::add( $this->game_id, $this->player_id, 'xp_awarded', [ 'amount' => $i ] );
		}
		Notice::add( $this->game_id, $this->other_id, 'xp_awarded', [ 'amount' => 99 ] );

		$data = $this->get_notices( $this->player_id )->get_data();

		$this->assertSame( 35, $data['unread'] );
		$this->assertCount( 30, $data['items'] );
		$this->assertSame( 35, $data['items'][0]->payload['amount'], 'newest first' );
	}

	public function test_marking_read_touches_only_the_callers_own_notices(): void {
		$mine  = Notice::add( $this->game_id, $this->player_id, 'xp_awarded', [ 'amount' => 1 ] );
		$mine2 = Notice::add( $this->game_id, $this->player_id, 'xp_awarded', [ 'amount' => 2 ] );
		$theirs = Notice::add( $this->game_id, $this->other_id, 'xp_awarded', [ 'amount' => 3 ] );

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/notices/read" );
		$request->set_param( 'ids', [ $mine, $theirs ] );
		$data = $this->dispatch( $request )->get_data();

		$this->assertSame( 1, $data['unread'], 'one of their own is left' );
		$this->assertSame( 1, Notice::unread_count( $this->game_id, $this->other_id ), "another person's notice is untouched" );
		$this->assertNotNull( $mine2 );
	}

	public function test_marking_all_read_clears_the_callers_count(): void {
		Notice::add( $this->game_id, $this->player_id, 'xp_awarded', [ 'amount' => 1 ] );
		Notice::add( $this->game_id, $this->player_id, 'xp_awarded', [ 'amount' => 2 ] );
		Notice::add( $this->game_id, $this->other_id, 'xp_awarded', [ 'amount' => 3 ] );

		wp_set_current_user( $this->player_id );
		$data = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/notices/read" ) )->get_data();

		$this->assertSame( 0, $data['unread'] );
		$this->assertSame( 1, Notice::unread_count( $this->game_id, $this->other_id ) );
	}

	public function test_a_visitor_who_is_signed_out_is_refused(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/notices" ) );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	// --- Pruning --------------------------------------------------------------------------------------------

	public function test_notices_older_than_ninety_days_are_pruned(): void {
		$old = Notice::add( $this->game_id, $this->player_id, 'xp_awarded', [ 'amount' => 1 ] );
		$new = Notice::add( $this->game_id, $this->player_id, 'xp_awarded', [ 'amount' => 2 ] );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_notices', [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS ) ], [ 'id' => $old ] );

		Notice::prune();

		$ids = array_map( static fn( $n ) => $n->id, $this->notices_of( $this->player_id ) );
		$this->assertSame( [ $new ], $ids );
	}
}
