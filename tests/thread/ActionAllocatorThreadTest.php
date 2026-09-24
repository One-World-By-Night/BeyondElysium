<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Background_Ledger;
use WP_UnitTestCase;

/**
 * The database-touching half of the Action Allocator: persisting one plot per character-date, re-running
 * idempotently, carrying state from a real prior plot, and the `IfDoneSetDone` completion rule read back from real
 * entries.
 */
class ActionAllocatorThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-allocator-game';
	private int $character_id;

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Allocator Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [
				'apr' => [
					'personal_actions'   => 3,
					'carry_unused'       => true,
					'add_common'         => true,
					'background_actions' => [ 'Resources' ],
					'actions_per_level'  => [],
				],
			] ),
		] );

		$this->character_id = Character::create( [
			'name' => 'Allocator Test Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'sheet_data' => [
				'test-stack-backgrounds' => [
					[ 'name' => 'Bureaucracy', 'count' => 2 ],
					[ 'name' => 'Resources', 'count' => 3 ],
				],
			],
		] );

		Manager::insert( 'schema_blocks', [
			'slug' => 'test-stack-backgrounds', 'name' => 'Backgrounds', 'section_type' => 'trait_list',
			'definition' => wp_json_encode( [
				'items' => [
					[ 'name' => 'Bureaucracy', 'source' => 'Influences' ],
					[ 'name' => 'Resources', 'source' => 'Backgrounds' ],
				],
			] ),
			'is_system' => 0, 'created_by' => 1,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
	}

	public function test_persist_creates_one_plot_with_an_entry_per_subaction(): void {
		$plot_id = Action_Allocator::persist( $this->character_id, '2026-01-01' );

		$entries = Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] );
		$this->assertCount( 3, $entries, 'Personal + Bureaucracy (Influence) + Resources (configured Background)' );
	}

	public function test_persist_is_idempotent_for_the_same_character_and_date(): void {
		$first  = Action_Allocator::persist( $this->character_id, '2026-01-01' );
		$second = Action_Allocator::persist( $this->character_id, '2026-01-01' );

		$this->assertSame( $first, $second, 're-running must update the existing plot, not create a second one' );

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}be_plots WHERE id = %d",
			$first
		) );
		$this->assertSame( 1, $count );

		// Re-running must not duplicate entries either - still exactly one per subaction.
		$entries = Plot_Entry::for_plot( $first, [ 'entry_type' => 'action' ] );
		$this->assertCount( 3, $entries );
	}

	public function test_persist_never_deletes_a_free_text_post_or_a_ledger_entry_on_re_allocation(): void {
		$plot_id = Action_Allocator::persist( $this->character_id, '2026-01-01' );

		$free_text_id = Plot_Entry::create( [
			'plot_id' => $plot_id, 'author_id' => 1, 'entry_type' => 'action',
			'content' => 'I spend Bureaucracy 2 to smooth over the paperwork.',
		] );
		$ledger_entry = Background_Ledger::record( $this->character_id, '2026-01-01', [ 'name' => 'Bureaucracy', 'text' => 'Called in a favor.' ] );

		$again = Action_Allocator::persist( $this->character_id, '2026-01-01' );
		$this->assertSame( $plot_id, $again );

		$this->assertNotNull( Plot_Entry::find( $free_text_id ), 'the free-text post survives' );
		$this->assertNotNull( Plot_Entry::find( (int) $ledger_entry['id'] ), 'the ledger entry survives' );

		// The allocator's own subactions were still refreshed - exactly 3, not stale duplicates.
		$allocator_entries = array_filter(
			Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] ),
			static fn( $e ) => strpos( $e->content, '"source":"allocator"' ) !== false
		);
		$this->assertCount( 3, $allocator_entries );
	}

	public function test_second_week_carries_unused_and_growth_from_the_first(): void {
		Action_Allocator::persist( $this->character_id, '2026-01-01' );

		$plot_id = Action_Allocator::persist( $this->character_id, '2026-01-01' );
		$entries = Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] );
		foreach ( $entries as $entry ) {
			$data = json_decode( $entry->content, true );
			if ( $data['name'] === 'Resources' ) {
				$data['unused'] = 1;
				$data['growth'] = 2;
				Plot_Entry::update( (int) $entry->id, [ 'content' => wp_json_encode( $data ) ] );
			}
		}

		$week2 = Action_Allocator::allocate( $this->character_id, '2026-01-08' );
		$by_name = array_column( $week2, null, 'name' );

		$this->assertSame( 1, $by_name['Resources']['unused'], 'unused carries forward from the prior week' );
		$this->assertSame( 2, $by_name['Resources']['growth'], 'growth carries forward from the prior week' );
	}

	public function test_no_prior_allocation_returns_fresh_totals(): void {
		$subactions = Action_Allocator::allocate( $this->character_id, '2026-01-01' );
		$by_name    = array_column( $subactions, null, 'name' );

		$this->assertSame( 3, $by_name['Personal']['total'] );
		$this->assertSame( 4, $by_name['Bureaucracy']['total'] ); // 2 * 2 dots
		$this->assertSame( 0, $by_name['Personal']['growth'] );
	}

	public function test_a_character_with_no_backgrounds_still_gets_the_personal_subaction(): void {
		$bare_character = Character::create( [
			'name' => 'Bare Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );

		$subactions = Action_Allocator::allocate( $bare_character, '2026-01-01' );

		$this->assertCount( 1, $subactions );
		$this->assertSame( 'Personal', $subactions[0]['name'] );
	}

	/**
	 * The allocator entry's own action/result fields can never carry this.
	 */
	public function test_is_complete_requires_a_ledger_entry_with_a_result_for_every_budgeted_subaction(): void {
		$plot_id = Action_Allocator::persist( $this->character_id, '2026-01-01' );
		$this->assertFalse( Action_Allocator::is_complete( $plot_id ), 'no ledger entries recorded yet' );

		$entries = [];
		foreach ( [ Action_Allocator::PERSONAL_NAME, 'Bureaucracy', 'Resources' ] as $name ) {
			$entries[ $name ] = Background_Ledger::record( $this->character_id, '2026-01-01', [ 'name' => $name ] );
		}
		$this->assertFalse( Action_Allocator::is_complete( $plot_id ), 'entries exist but none has a result yet' );

		// Filling in every entry but one still leaves the plot incomplete.
		Background_Ledger::update_entry( (int) $entries['Personal']['id'], [ 'result' => 'spent freely' ] );
		Background_Ledger::update_entry( (int) $entries['Bureaucracy']['id'], [ 'result' => 'paperwork filed' ] );
		$this->assertFalse( Action_Allocator::is_complete( $plot_id ), 'Resources has no result yet' );

		Background_Ledger::update_entry( (int) $entries['Resources']['id'], [ 'result' => 'favor called in' ] );
		$this->assertTrue( Action_Allocator::is_complete( $plot_id ) );
	}

	public function test_a_players_own_free_text_action_entry_does_not_break_completion_check(): void {
		$plot_id = Action_Allocator::persist( $this->character_id, '2026-01-01' );

		Plot_Entry::create( [
			'plot_id' => $plot_id, 'author_id' => 1, 'entry_type' => 'action',
			'content' => 'I spend Bureaucracy 2 to smooth over the paperwork.',
		] );

		$this->assertFalse( Action_Allocator::is_complete( $plot_id ) );
	}
}
