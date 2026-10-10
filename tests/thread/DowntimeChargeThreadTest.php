<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Background_Ledger;
use BeyondElysium\Services\Downtime_Window;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A Storyteller answering a character's downtime says whether the answer cost the character an action. A charge draws
 * on one of the character's backgrounds through the ledger and goes with the answer if the answer is deleted.
 */
class DowntimeChargeThreadTest extends WP_UnitTestCase {

	private const DATE = '2026-11-14';

	private string $slug = 'thread-downtime-charge';
	private int $game_id;
	private int $player;
	private int $hst;
	private int $character_id;
	private int $plot_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Downtime Charge' ] );
		$this->player  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );

		$this->character_id = (int) Character::create( [
			'name' => 'Busy Vampire', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'wp_user_id' => $this->player, 'status' => 'active',
			'sheet_data' => [ 'vampire-backgrounds' => [ [ 'name' => 'Allies', 'count' => 2 ] ] ],
		] );

		wp_set_current_user( $this->hst );
		$this->plot_id = (int) Action_Allocator::create_own_plot( Character::find( $this->character_id ), self::DATE );
		// The player's own action, which an answer follows.
		Plot_Entry::create( [ 'plot_id' => $this->plot_id, 'author_id' => $this->player, 'entry_type' => 'action', 'content' => 'I look into the missing ledger.' ] );
	}

	/** @param array<string,mixed> $body */
	private function post_entry( int $plot_id, array $body, int $user = 0 ): \WP_REST_Response {
		wp_set_current_user( $user ?: $this->hst );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/plots/{$plot_id}/entries" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array_merge( [ 'entry_type' => 'response', 'content' => '<p>The coterie looks into it.</p>' ], $body ) ) );
		return rest_get_server()->dispatch( $request );
	}

	private function delete_entry( int $entry_id ): \WP_REST_Response {
		wp_set_current_user( $this->hst );
		return rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->slug}/entries/{$entry_id}" ) );
	}

	private function entry_id( \WP_REST_Response $response ): int {
		$data = $response->get_data();
		return (int) ( is_object( $data ) ? $data->id : $data['id'] );
	}

	/** @return array<string,mixed> */
	private function queue_row(): array {
		foreach ( Downtime_Window::queue_for_date( $this->game_id, self::DATE ) as $row ) {
			if ( (int) $row['plot_id'] === $this->plot_id ) {
				return $row;
			}
		}
		$this->fail( 'the plot is not in the queue' );
	}

	public function test_an_answer_on_a_dated_action_plot_must_say_whether_it_cost_an_action(): void {
		$response = $this->post_entry( $this->plot_id, [] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'action_charge_required', $response->get_data()['code'] );
		$this->assertSame( [], Plot_Entry::for_plot( $this->plot_id, [ 'entry_type' => 'response' ] ), 'a refused answer is not saved' );
	}

	public function test_no_action_charged_is_stored_and_records_nothing_in_the_ledger(): void {
		$response = $this->post_entry( $this->plot_id, [ 'action_charge' => [ 'charged' => false ] ] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( [ 'charged' => false ], Plot_Entry::find( $this->entry_id( $response ) )->action_charge );
		$this->assertSame( [], Background_Ledger::entries_for_plot( $this->plot_id ) );
	}

	public function test_a_charged_answer_records_one_ledger_use_and_remembers_it(): void {
		$response = $this->post_entry( $this->plot_id, [ 'action_charge' => [ 'charged' => true, 'name' => 'Allies', 'cost' => 1 ] ] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$uses = Background_Ledger::entries_for_plot( $this->plot_id );
		$this->assertCount( 1, $uses );
		$this->assertSame( 'Allies', $uses[0]['name'] );
		$this->assertSame( 1, (int) $uses[0]['cost'] );

		$charge = Plot_Entry::find( $this->entry_id( $response ) )->action_charge;
		$this->assertTrue( $charge['charged'] );
		$this->assertSame( (int) $uses[0]['id'], (int) $charge['use_id'] );
		$this->assertSame( 'Allies', $charge['name'] );
	}

	public function test_a_charge_against_a_background_the_character_does_not_hold_saves_nothing(): void {
		$response = $this->post_entry( $this->plot_id, [ 'action_charge' => [ 'charged' => true, 'name' => 'Resources', 'cost' => 1 ] ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( [], Plot_Entry::for_plot( $this->plot_id, [ 'entry_type' => 'response' ] ), 'no answer without its charge' );
		$this->assertSame( [], Background_Ledger::entries_for_plot( $this->plot_id ) );
	}

	public function test_a_malformed_charge_is_refused(): void {
		$this->assertSame( 400, $this->post_entry( $this->plot_id, [ 'action_charge' => [ 'charged' => 'maybe' ] ] )->get_status() );
		$this->assertSame( 400, $this->post_entry( $this->plot_id, [ 'action_charge' => [ 'charged' => true ] ] )->get_status(), 'a charge names a background' );
	}

	public function test_deleting_a_charged_answer_takes_its_ledger_use_with_it(): void {
		$response = $this->post_entry( $this->plot_id, [ 'action_charge' => [ 'charged' => true, 'name' => 'Allies' ] ] );
		$this->assertCount( 1, Background_Ledger::entries_for_plot( $this->plot_id ) );

		$deleted = $this->delete_entry( $this->entry_id( $response ) );

		$this->assertSame( 204, $deleted->get_status() );
		$this->assertSame( [], Background_Ledger::entries_for_plot( $this->plot_id ) );
	}

	public function test_a_response_on_a_plot_that_is_not_a_dated_action_plot_needs_no_charge(): void {
		$plain = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'A plain plot', 'created_by' => $this->hst ] );

		$response = $this->post_entry( $plain, [ 'action_charge' => [ 'charged' => true, 'name' => 'Allies' ] ] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertNull( Plot_Entry::find( $this->entry_id( $response ) )->action_charge );
		$this->assertSame( [], Background_Ledger::entries_for_plot( $plain ) );
	}

	public function test_the_queue_reports_each_state(): void {
		$this->assertNull( $this->queue_row()['charge'], 'unanswered' );

		$charged = $this->post_entry( $this->plot_id, [ 'action_charge' => [ 'charged' => true, 'name' => 'Allies', 'cost' => 1 ] ] );
		$row     = $this->queue_row();
		$this->assertSame( 'charged', $row['charge']['state'] );
		$this->assertSame( 'Allies', $row['charge']['name'] );
		$this->assertSame( 1, $row['charge']['cost'] );

		$use = Background_Ledger::entries_for_plot( $this->plot_id )[0];
		Background_Ledger::clear_entry( (int) $use['id'] );
		$this->assertSame( 'charge_removed', $this->queue_row()['charge']['state'] );

		$this->delete_entry( $this->entry_id( $charged ) );
		$this->post_entry( $this->plot_id, [ 'action_charge' => [ 'charged' => false ] ] );
		$this->assertSame( 'none', $this->queue_row()['charge']['state'] );
	}

	public function test_an_answer_written_before_the_choice_existed_reads_not_recorded(): void {
		Plot_Entry::create( [ 'plot_id' => $this->plot_id, 'author_id' => $this->hst, 'entry_type' => 'response', 'content' => 'Old answer.' ] );

		$this->assertSame( 'not_recorded', $this->queue_row()['charge']['state'] );
	}

	public function test_the_charge_is_for_storytellers_only_and_the_plot_names_its_character(): void {
		$answer = $this->post_entry( $this->plot_id, [ 'action_charge' => [ 'charged' => true, 'name' => 'Allies' ], 'audience' => 'plot', 'held' => false ] );
		$this->assertSame( 201, $answer->get_status(), wp_json_encode( $answer->get_data() ) );

		wp_set_current_user( $this->hst );
		$as_hst = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/plots/{$this->plot_id}" ) )->get_data();
		$this->assertSame( $this->character_id, (int) $as_hst->actor_character_id );
		$answers = array_values( array_filter( $as_hst->entries, static fn( $e ) => $e->entry_type === 'response' ) );
		$this->assertTrue( $answers[0]->action_charge['charged'] );

		wp_set_current_user( $this->player );
		$as_player = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/plots/{$this->plot_id}" ) )->get_data();
		foreach ( $as_player->entries as $entry ) {
			$this->assertNull( $entry->action_charge ?? null, 'a player never sees the bookkeeping' );
		}
	}

	public function test_the_column_exists_and_adding_it_twice_changes_nothing(): void {
		global $wpdb;
		\BeyondElysium\Database\Schema::add_action_charge_to_plot_entries();
		\BeyondElysium\Database\Schema::add_action_charge_to_plot_entries();

		$this->assertNotNull( $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}be_plot_entries LIKE 'action_charge'" ) ? 1 : null );
	}
}
