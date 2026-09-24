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
 * The generic entries route let any player post an action entry onto another character's action-allocation plot.
 */
class PlotEntryWritesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-entry-writes';
	private int $game_id;
	private int $player;
	private int $own_character;
	private int $own_plot;
	private int $other_plot;
	private int $open_plot;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) Game::find_by_slug( $this->slug )->id;

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		$other_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $other_player, 'player' );

		$this->own_character = Character::create( [
			'name' => 'Own Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
			'sheet_data' => [ 'vampire-backgrounds' => [ [ 'name' => 'Resources', 'count' => 3 ] ] ],
		] );
		$other_character = Character::create( [
			'name' => 'Other Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $other_player,
		] );

		$this->own_plot   = $this->allocation_plot( $this->own_character, 'Own Allocation' );
		$this->other_plot = $this->allocation_plot( $other_character, 'Other Allocation' );
		$this->open_plot  = Plot::create( [
			'game_id' => $this->game_id, 'title' => 'Open Plot', 'initiated_by' => 'st',
			'game_date' => '2026-09-01', 'created_by' => 1,
		] );
	}

	/**
	 * An action-allocation plot for one character, with a three-use Resources budget row.
	 */
	private function allocation_plot( int $character_id, string $title ): int {
		$plot_id = Plot::create( [
			'game_id' => $this->game_id, 'title' => $title, 'initiated_by' => 'player',
			'game_date' => '2026-09-01', 'created_by' => 1,
		] );
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $plot_id,
			'target_type' => 'character', 'target_id' => $character_id,
			'label' => Action_Allocator::ACTOR_LABEL, 'created_by' => 1,
		] );
		Plot_Entry::create( [
			'plot_id' => $plot_id, 'author_id' => 1, 'entry_type' => 'action',
			'content' => wp_json_encode( [ 'source' => 'allocator', 'name' => 'Resources', 'level' => 3, 'total' => 3, 'unused' => 3 ] ),
			'event_date' => '2026-09-01',
		] );
		return $plot_id;
	}

	private function post_entry( int $plot_id, string $content ) {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/plots/{$plot_id}/entries" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'entry_type' => 'action', 'content' => $content ] ) );
		return rest_get_server()->dispatch( $request );
	}

	private function resources_budget(): ?int {
		foreach ( Background_Ledger::spendable_for( $this->own_character ) as $row ) {
			if ( $row['name'] === 'Resources' ) {
				return $row['budget_total'];
			}
		}
		return null;
	}

	public function test_a_player_cannot_post_onto_another_characters_allocation_plot(): void {
		wp_set_current_user( $this->player );
		$before = count( Plot_Entry::for_plot( $this->other_plot ) );

		$response = $this->post_entry( $this->other_plot, 'Sabotage' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertCount( $before, Plot_Entry::for_plot( $this->other_plot ) );
	}

	public function test_a_forged_ledger_entry_is_refused_and_the_budget_is_unchanged(): void {
		wp_set_current_user( $this->player );
		$this->assertSame( 3, $this->resources_budget() );

		$response = $this->post_entry( $this->own_plot, wp_json_encode( [ 'source' => 'ledger', 'name' => 'Resources', 'cost' => -50 ] ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'reserved_entry', $response->as_error()->get_error_code() );
		$this->assertSame( 3, $this->resources_budget() );
	}

	public function test_a_forged_allocator_row_is_refused(): void {
		wp_set_current_user( $this->player );

		$response = $this->post_entry( $this->own_plot, wp_json_encode( [ 'source' => 'allocator', 'name' => 'Resources', 'unused' => 99 ] ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_player_still_posts_an_ordinary_action(): void {
		wp_set_current_user( $this->player );

		$this->assertSame( 201, $this->post_entry( $this->open_plot, 'I watch the docks all night.' )->get_status() );
		$this->assertSame( 201, $this->post_entry( $this->own_plot, 'I call in a favor with Resources.' )->get_status() );
	}

	public function test_a_ledger_cost_below_one_never_adds_uses(): void {
		$result = Background_Ledger::apply_spends(
			[ [ 'name' => 'Resources', 'unused' => 3 ] ],
			[ [ 'name' => 'Resources', 'cost' => -50 ] ]
		);

		$this->assertSame( 2, $result['subactions'][0]['unused'] );
	}
}
