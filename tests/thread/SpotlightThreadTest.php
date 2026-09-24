<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The spotlight check - every active, non-NPC character's own attention profile, flagged (no staff post ever, or none
 * within the chronicle's spotlight window) first.
 */
class SpotlightThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-spotlight';
	private int $game_id;
	private int $storyteller_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Spotlight' ] );
		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );
	}

	private function make_character( string $name, string $status = 'active', bool $is_npc = false ): int {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );
		return (int) Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $player, 'status' => $status,
			'is_npc' => $is_npc ? 1 : 0, 'created_by' => $this->storyteller_id,
		] );
	}

	private function make_plot( int $character_id, ?string $game_date = null, string $status = 'active' ): int {
		$plot_id = (int) Plot::create( [
			'game_id' => $this->game_id, 'title' => 'A Plot', 'status' => $status,
			'game_date' => $game_date, 'created_by' => $this->storyteller_id,
		] );
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $plot_id,
			'target_type' => 'character', 'target_id' => $character_id, 'label' => 'plot_member',
			'created_by' => $this->storyteller_id,
		] );
		return $plot_id;
	}

	private function post_entry( int $plot_id, int $author_id, string $entry_type = 'response', ?string $created_at = null ): void {
		$id = (int) Plot_Entry::create( [
			'plot_id' => $plot_id, 'author_id' => $author_id, 'entry_type' => $entry_type,
			'content' => 'Something happened.',
		] );
		if ( $created_at !== null ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'be_plot_entries', [ 'created_at' => $created_at ], [ 'id' => $id ] );
		}
	}

	private function get_spotlight() {
		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/spotlight" );
		return rest_get_server()->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Flag rules.
	// -------------------------------------------------------------------------

	public function test_a_character_with_no_staff_post_ever_is_flagged(): void {
		$this->make_character( 'Untouched' );

		$rows = $this->get_spotlight()->get_data();

		$this->assertTrue( $rows[0]['flagged'] );
		$this->assertNull( $rows[0]['last_staff_post_at'] );
	}

	public function test_a_recent_staff_post_clears_the_flag(): void {
		$character_id = $this->make_character( 'Attended To' );
		$plot_id      = $this->make_plot( $character_id );
		$this->post_entry( $plot_id, $this->storyteller_id, 'response' );

		$rows = $this->get_spotlight()->get_data();

		$this->assertFalse( $rows[0]['flagged'] );
		$this->assertNotNull( $rows[0]['last_staff_post_at'] );
	}

	public function test_a_stale_staff_post_past_the_spotlight_window_is_flagged(): void {
		$character_id = $this->make_character( 'Gone Quiet' );
		$plot_id      = $this->make_plot( $character_id );
		$this->post_entry( $plot_id, $this->storyteller_id, 'response', gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ) );

		Game::update( $this->slug, [ 'settings' => [ 'sessions' => [ 'spotlight_days' => 42 ] ] ] );

		$rows = $this->get_spotlight()->get_data();
		$this->assertTrue( $rows[0]['flagged'] );
	}

	public function test_a_player_post_never_counts_as_a_staff_post(): void {
		$character_id = $this->make_character( 'Self Posted' );
		$character    = Character::find( $character_id );
		$plot_id      = $this->make_plot( $character_id );
		$this->post_entry( $plot_id, (int) $character->wp_user_id, 'action' );

		$rows = $this->get_spotlight()->get_data();
		$this->assertTrue( $rows[0]['flagged'] );
	}

	public function test_a_note_by_staff_never_counts_as_a_post(): void {
		$character_id = $this->make_character( 'Note Only' );
		$plot_id      = $this->make_plot( $character_id );
		$this->post_entry( $plot_id, $this->storyteller_id, 'note' );

		$rows = $this->get_spotlight()->get_data();
		$this->assertTrue( $rows[0]['flagged'] );
	}

	public function test_a_staff_post_on_an_action_plot_still_counts(): void {
		$character_id = $this->make_character( 'Action Round' );
		$plot_id      = $this->make_plot( $character_id, '2026-01-01' );
		$this->post_entry( $plot_id, $this->storyteller_id, 'response' );

		$rows = $this->get_spotlight()->get_data();
		$this->assertFalse( $rows[0]['flagged'] );
	}

	// -------------------------------------------------------------------------
	// active_plots excludes action plots.
	// -------------------------------------------------------------------------

	public function test_active_plots_excludes_action_rounds(): void {
		// Character::create() already gives every character its own permanent plot (apr_actor, game_date null).
		$character_id = $this->make_character( 'Plotted' );
		$this->make_plot( $character_id, null, 'active' );
		$this->make_plot( $character_id, '2026-01-01', 'active' );

		$rows = $this->get_spotlight()->get_data();
		$this->assertSame( 1, $rows[0]['active_plots'] );
	}

	// -------------------------------------------------------------------------
	// NPCs and inactive characters never appear at all.
	// -------------------------------------------------------------------------

	public function test_an_npc_never_appears(): void {
		$this->make_character( 'An NPC', 'active', true );

		$this->assertSame( [], $this->get_spotlight()->get_data() );
	}

	public function test_an_inactive_character_never_appears(): void {
		$this->make_character( 'Retired', 'inactive' );

		$this->assertSame( [], $this->get_spotlight()->get_data() );
	}

	// -------------------------------------------------------------------------
	// Ordering: flagged first, then least recent attention.
	// -------------------------------------------------------------------------

	public function test_flagged_characters_sort_before_attended_to_ones(): void {
		$attended = $this->make_character( 'Attended' );
		$plot_id  = $this->make_plot( $attended );
		$this->post_entry( $plot_id, $this->storyteller_id, 'response' );
		$this->make_character( 'Flagged' );

		$rows = $this->get_spotlight()->get_data();

		$this->assertTrue( $rows[0]['flagged'] );
		$this->assertFalse( $rows[1]['flagged'] );
	}

	public function test_least_recently_attended_sorts_first_among_the_unflagged(): void {
		$older_char = $this->make_character( 'Older Post' );
		$older_plot = $this->make_plot( $older_char );
		$this->post_entry( $older_plot, $this->storyteller_id, 'response', gmdate( 'Y-m-d H:i:s', time() - ( 10 * DAY_IN_SECONDS ) ) );

		$newer_char = $this->make_character( 'Newer Post' );
		$newer_plot = $this->make_plot( $newer_char );
		$this->post_entry( $newer_plot, $this->storyteller_id, 'response', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		$rows = $this->get_spotlight()->get_data();
		$this->assertSame( 'Older Post', $rows[0]['name'] );
		$this->assertSame( 'Newer Post', $rows[1]['name'] );
	}

	// -------------------------------------------------------------------------
	// Permissions and the stats count.
	// -------------------------------------------------------------------------

	public function test_a_player_may_not_read_the_spotlight(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );

		wp_set_current_user( $player );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/spotlight" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_stats_characters_needing_attention_matches_the_spotlights_own_flagged_count(): void {
		$this->make_character( 'Flagged One' );
		$attended = $this->make_character( 'Attended One' );
		$plot_id  = $this->make_plot( $attended );
		$this->post_entry( $plot_id, $this->storyteller_id, 'response' );

		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/stats" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 1, $response->get_data()['characters_needing_attention'] );
	}
}
