<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Background_Ledger;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-054 (Pass H intake, `t1-print-reports`, verified). The Master Action Report and
 * the Action and Rumor Report read every action entry as a player's free-text post:
 * - Character came from `Character::find( author_id )`, but `author_id` is the WordPress user
 *   who posted, so the column read "-", or named whichever character's row id matched that user id.
 * - An allocation's budget lines and its Background uses are action entries holding JSON, so the
 *   Action column printed that JSON, while the budget numbers sat in columns marked unmapped.
 * - Result was the first Storyteller response posted after the action anywhere on the plot, so
 *   on a plot where two players act, one player's row showed the answer written for the other.
 */
class MasterActionReportThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-master-action';
	private int $game_id;
	private int $hst;
	private int $alice;
	private int $bob;
	private int $alice_character;
	private int $bob_character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Master Action' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->alice   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->bob     = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		Game_Member::set_role( $this->game_id, $this->alice, 'player' );
		Game_Member::set_role( $this->game_id, $this->bob, 'player' );

		$this->alice_character = Character::create( [ 'name' => 'Alice Neonate', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'status' => 'active', 'wp_user_id' => $this->alice ] );
		$this->bob_character   = Character::create( [ 'name' => 'Bob Ghoul', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'status' => 'active', 'wp_user_id' => $this->bob ] );
	}

	private function report( string $key ): array {
		wp_set_current_user( $this->hst );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/reports/{$key}" ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$document = $response->get_data();
		return array_map( static fn( array $row ) => array_combine( $document['columns'], $row ), $document['rows'] );
	}

	private function post( int $as, int $plot_id, string $type, string $content, string $at ): void {
		wp_set_current_user( $as );
		$id = Plot_Entry::create( [ 'plot_id' => $plot_id, 'author_id' => $as, 'entry_type' => $type, 'content' => $content ] );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_plot_entries', [ 'created_at' => $at ], [ 'id' => $id ] );
	}

	private function shared_plot( string $title, array $characters ): int {
		$plot = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => $title, 'initiated_by' => 'st' ] );
		foreach ( $characters as $character ) {
			Connection::create( [ 'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $plot, 'target_type' => 'character', 'target_id' => $character ] );
		}
		return $plot;
	}

	public function test_a_background_use_reads_as_its_characters_action_with_the_storytellers_result_and_budget(): void {
		wp_set_current_user( $this->hst );
		Action_Allocator::persist( $this->alice_character, '2026-10-01' );
		wp_set_current_user( $this->alice );
		$use = Background_Ledger::record( $this->alice_character, '2026-10-01', [ 'name' => Action_Allocator::PERSONAL_NAME, 'text' => 'Shadow the Prince', 'cost' => 1 ] );
		Background_Ledger::update_entry( (int) $use['id'], [ 'result' => 'Saw him meet the Sheriff' ] );

		$rows = array_values( array_filter( $this->report( 'master-action-report' ), static fn( $r ) => $r['Action'] === 'Shadow the Prince' ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Alice Neonate', $rows[0]['Character'] );
		$this->assertSame( Action_Allocator::PERSONAL_NAME, $rows[0]['Type'] );
		$this->assertSame( 'Saw him meet the Sheriff', $rows[0]['Result'] );
		$total = (int) Action_Allocator::subactions_for_plot( Action_Allocator::find_own_plot_id( $this->alice_character, '2026-10-01' ) )[ Action_Allocator::PERSONAL_NAME ]['total'];
		$this->assertSame( (string) $total, $rows[0]['Total'] );
		$this->assertSame( (string) max( 0, $total - 1 ), $rows[0]['Unused'] );
	}

	public function test_no_row_ever_shows_the_stored_json(): void {
		wp_set_current_user( $this->hst );
		Action_Allocator::persist( $this->alice_character, '2026-10-01' );
		wp_set_current_user( $this->alice );
		Background_Ledger::record( $this->alice_character, '2026-10-01', [ 'name' => Action_Allocator::PERSONAL_NAME, 'text' => 'Hunt', 'cost' => 1 ] );

		foreach ( [ 'master-action-report', 'action-and-rumor-report' ] as $key ) {
			foreach ( $this->report( $key ) as $row ) {
				foreach ( $row as $column => $cell ) {
					$this->assertStringNotContainsString( '"source":', (string) $cell, "{$key} {$column}" );
				}
			}
		}
	}

	public function test_a_post_names_the_posters_character_on_the_plot_not_a_row_matching_their_user_id(): void {
		$plot = $this->shared_plot( 'Quiet Errand', [ $this->alice_character ] );
		$this->post( $this->alice, $plot, 'action', 'Deliver the letter', '2026-10-01 10:00:00' );
		$this->post( $this->hst, $plot, 'response', 'The letter arrives sealed.', '2026-10-01 11:00:00' );

		$row = array_values( array_filter( $this->report( 'master-action-report' ), static fn( $r ) => $r['Action'] === 'Deliver the letter' ) )[0];

		$this->assertSame( 'Alice Neonate', $row['Character'] );
		$this->assertSame( 'The letter arrives sealed.', $row['Result'], 'one player acting: the next response is theirs' );
	}

	public function test_on_a_plot_where_several_players_act_no_one_is_handed_anothers_answer(): void {
		$plot = $this->shared_plot( 'Harbor Trouble', [ $this->alice_character, $this->bob_character ] );
		$this->post( $this->alice, $plot, 'action', 'Search the warehouse', '2026-10-01 10:00:00' );
		$this->post( $this->bob, $plot, 'action', 'Guard the door', '2026-10-01 10:05:00' );
		$this->post( $this->hst, $plot, 'response', 'Bob hears footsteps.', '2026-10-01 10:10:00' );

		$rows = array_column( $this->report( 'master-action-report' ), null, 'Action' );

		$this->assertSame( 'Alice Neonate', $rows['Search the warehouse']['Character'] );
		$this->assertSame( 'Bob Ghoul', $rows['Guard the door']['Character'] );
		$this->assertStringNotContainsString( 'footsteps', $rows['Search the warehouse']['Result'] );
		$this->assertStringNotContainsString( 'footsteps', $rows['Guard the door']['Result'] );
		$this->assertStringContainsString( 'Harbor Trouble', $rows['Search the warehouse']['Result'], 'the line points at the plot instead' );
	}

	public function test_turn_by_turn_answers_each_go_to_the_post_they_follow(): void {
		$plot = $this->shared_plot( 'Court Night', [ $this->alice_character, $this->bob_character ] );
		$this->post( $this->alice, $plot, 'action', 'Petition the Prince', '2026-10-01 10:00:00' );
		$this->post( $this->hst, $plot, 'response', 'The Prince hears Alice out.', '2026-10-01 10:10:00' );
		$this->post( $this->bob, $plot, 'action', 'Carry the gift', '2026-10-01 10:20:00' );
		$this->post( $this->hst, $plot, 'response', 'The gift is accepted.', '2026-10-01 10:30:00' );

		$rows = array_column( $this->report( 'master-action-report' ), null, 'Action' );

		$this->assertSame( 'The Prince hears Alice out.', $rows['Petition the Prince']['Result'] );
		$this->assertSame( 'The gift is accepted.', $rows['Carry the gift']['Result'] );
	}

	public function test_a_budget_line_nobody_used_still_prints_its_numbers(): void {
		wp_set_current_user( $this->hst );
		$plot  = Action_Allocator::persist( $this->alice_character, '2026-10-01' );
		$total = (int) Action_Allocator::subactions_for_plot( $plot )[ Action_Allocator::PERSONAL_NAME ]['total'];

		$rows = array_values( array_filter(
			$this->report( 'action-and-rumor-report' ),
			static fn( $r ) => $r['Character'] === 'Alice Neonate' && $r['Type'] === Action_Allocator::PERSONAL_NAME
		) );

		$this->assertCount( 1, $rows );
		$this->assertSame( '—', $rows[0]['Action'] );
		$this->assertSame( '—', $rows[0]['Result'] );
		$this->assertSame( (string) $total, $rows[0]['Total'] );
		$this->assertSame( '2026-10-01', $rows[0]['Date'] );
	}
}
