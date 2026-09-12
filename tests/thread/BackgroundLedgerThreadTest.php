<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Background_Ledger;
use WP_UnitTestCase;

/**
 * The database-touching half of the background-use ledger: recording,
 * editing, and clearing a real use against a real character and allocation
 * plot, and the "no allocation has ever been run yet" path a use must
 * still be recordable through (§4.1). apply_spends() itself is covered
 * without a database in tests/unit/BackgroundLedgerTest.php.
 *
 * @see BE_PROCESS/background-ledger-apr-design.md §5, §11 Trace 1
 */
class BackgroundLedgerThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-ledger-game';
	private int $character_id;

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Ledger Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [
				'apr' => [ 'personal_actions' => 3, 'carry_unused' => true, 'add_common' => true, 'background_actions' => [ 'Resources' ], 'actions_per_level' => [] ],
			] ),
		] );

		$this->character_id = Character::create( [
			'name' => 'Ledger Test Character', 'stack_slug' => 'ledger-test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'sheet_data' => [
				'ledger-test-stack-backgrounds' => [
					[ 'name' => 'Bureaucracy', 'count' => 2 ],
					[ 'name' => 'Resources', 'count' => 3 ],
				],
			],
		] );

		Manager::insert( 'schema_blocks', [
			'slug' => 'ledger-test-stack-backgrounds', 'name' => 'Backgrounds', 'section_type' => 'trait_list',
			'definition' => wp_json_encode( [ 'items' => [
				[ 'name' => 'Bureaucracy', 'source' => 'Influences' ],
				[ 'name' => 'Resources', 'source' => 'Backgrounds' ],
			] ] ),
			'is_system' => 0, 'created_by' => 1,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
	}

	public function test_spendable_for_lists_every_held_background_unbudgeted_before_any_allocation(): void {
		$spendable = Background_Ledger::spendable_for( $this->character_id );
		$by_name   = array_column( $spendable, null, 'name' );

		$this->assertNull( $by_name['Bureaucracy']['budget_total'] );
		$this->assertNull( $by_name['Bureaucracy']['budget_name'] );
		$this->assertSame( 'Influences', $by_name['Bureaucracy']['source'] );
		$this->assertSame( 2, $by_name['Bureaucracy']['level'] );
	}

	public function test_spendable_for_annotates_a_real_budget_once_an_allocation_exists(): void {
		Action_Allocator::persist( $this->character_id, '2026-01-01' );

		$spendable = Background_Ledger::spendable_for( $this->character_id );
		$by_name   = array_column( $spendable, null, 'name' );

		$this->assertSame( 4, $by_name['Bureaucracy']['budget_total'], '2 * 2 dots' );
		$this->assertSame( 'Bureaucracy', $by_name['Bureaucracy']['budget_name'] );
	}

	public function test_spendable_for_a_stack_with_no_backgrounds_block_is_empty_not_an_error(): void {
		$bare = Character::create( [
			'name' => 'Bare Stack Character', 'stack_slug' => 'thread-ledger-bare-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );

		$this->assertSame( [], Background_Ledger::spendable_for( $bare ) );
	}

	public function test_record_writes_a_ledger_entry_and_snapshots_the_level(): void {
		$entry = Background_Ledger::record( $this->character_id, '2026-01-01', [
			'name' => 'Bureaucracy', 'cost' => 1, 'text' => 'Filed the paperwork.',
		] );

		$this->assertSame( 'ledger', $entry['source'] );
		$this->assertSame( 'Bureaucracy', $entry['name'] );
		$this->assertSame( 2, $entry['level'] );
		$this->assertSame( 1, $entry['cost'] );
		$this->assertSame( '', $entry['result'], 'result starts empty, filled in later by an ST' );
	}

	public function test_record_rejects_a_name_the_character_does_not_hold(): void {
		$result = Background_Ledger::record( $this->character_id, '2026-01-01', [ 'name' => 'Never Held' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_held', $result->get_error_code() );
	}

	public function test_record_allows_personal_even_though_it_is_not_a_catalog_background(): void {
		$entry = Background_Ledger::record( $this->character_id, '2026-01-01', [ 'name' => Action_Allocator::PERSONAL_NAME ] );
		$this->assertSame( 'Personal', $entry['name'] );
	}

	public function test_record_creates_a_bare_plot_when_no_allocation_has_ever_been_run(): void {
		$this->assertNull( Action_Allocator::find_own_plot_id( $this->character_id, '2026-02-01' ) );

		$entry = Background_Ledger::record( $this->character_id, '2026-02-01', [ 'name' => 'Resources' ] );

		$this->assertNotNull( Action_Allocator::find_own_plot_id( $this->character_id, '2026-02-01' ), 'a bare plot now exists for this date' );
		$this->assertSame( $entry['plot_id'], Action_Allocator::find_own_plot_id( $this->character_id, '2026-02-01' ) );
	}

	public function test_for_character_date_returns_empty_when_no_plot_exists_yet(): void {
		$this->assertSame( [], Background_Ledger::for_character_date( $this->character_id, '2099-01-01' ) );
	}

	public function test_update_entry_edits_text_result_and_cost(): void {
		$entry = Background_Ledger::record( $this->character_id, '2026-01-01', [ 'name' => 'Bureaucracy' ] );

		$ok = Background_Ledger::update_entry( (int) $entry['id'], [ 'text' => 'Revised account.', 'result' => 'Approved.', 'cost' => 2 ] );
		$this->assertTrue( $ok );

		$refreshed = Background_Ledger::for_character_date( $this->character_id, '2026-01-01' )[0];
		$this->assertSame( 'Revised account.', $refreshed['text'] );
		$this->assertSame( 'Approved.', $refreshed['result'] );
		$this->assertSame( 2, $refreshed['cost'] );
	}

	public function test_update_entry_refuses_an_allocator_budget_row(): void {
		$plot_id = Action_Allocator::persist( $this->character_id, '2026-01-01' );
		$allocator_entry = Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] )[0];

		$this->assertFalse( Background_Ledger::update_entry( (int) $allocator_entry->id, [ 'text' => 'should not apply' ] ) );
	}

	public function test_clear_entry_deletes_a_ledger_entry_but_refuses_an_allocator_entry(): void {
		$plot_id = Action_Allocator::persist( $this->character_id, '2026-01-01' );
		$entry   = Background_Ledger::record( $this->character_id, '2026-01-01', [ 'name' => 'Bureaucracy' ] );

		$this->assertTrue( Background_Ledger::clear_entry( (int) $entry['id'] ) );
		$this->assertNull( Plot_Entry::find( (int) $entry['id'] ) );

		$allocator_entries = array_filter(
			Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] ),
			static fn( $e ) => strpos( $e->content, '"source":"allocator"' ) !== false
		);
		$this->assertNotEmpty( $allocator_entries );
		$this->assertFalse( Background_Ledger::clear_entry( (int) array_values( $allocator_entries )[0]->id ) );
	}

	public function test_clear_for_character_deletes_across_dates_bounded_by_range(): void {
		Background_Ledger::record( $this->character_id, '2026-01-01', [ 'name' => 'Bureaucracy' ] );
		Background_Ledger::record( $this->character_id, '2026-02-01', [ 'name' => 'Resources' ] );
		Background_Ledger::record( $this->character_id, '2026-03-01', [ 'name' => 'Bureaucracy' ] );

		$cleared = Background_Ledger::clear_for_character( $this->character_id, '2026-01-15', '2026-02-15' );

		$this->assertSame( 1, $cleared, 'only the February entry falls inside the bound' );
		$this->assertCount( 1, Background_Ledger::for_character_date( $this->character_id, '2026-01-01' ) );
		$this->assertCount( 0, Background_Ledger::for_character_date( $this->character_id, '2026-02-01' ) );
		$this->assertCount( 1, Background_Ledger::for_character_date( $this->character_id, '2026-03-01' ) );
	}

	public function test_clear_for_character_with_no_bounds_clears_every_date(): void {
		Background_Ledger::record( $this->character_id, '2026-01-01', [ 'name' => 'Bureaucracy' ] );
		Background_Ledger::record( $this->character_id, '2026-02-01', [ 'name' => 'Resources' ] );

		$cleared = Background_Ledger::clear_for_character( $this->character_id );

		$this->assertSame( 2, $cleared );
	}

	public function test_clear_for_date_clears_every_character_on_that_date_chronicle_wide(): void {
		$other_character = Character::create( [
			'name' => 'Second Ledger Character', 'stack_slug' => 'ledger-test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'sheet_data' => [ 'ledger-test-stack-backgrounds' => [ [ 'name' => 'Resources', 'count' => 1 ] ] ],
		] );

		Background_Ledger::record( $this->character_id, '2026-01-01', [ 'name' => 'Bureaucracy' ] );
		Background_Ledger::record( $other_character, '2026-01-01', [ 'name' => 'Resources' ] );
		Background_Ledger::record( $this->character_id, '2026-02-01', [ 'name' => 'Resources' ] );

		global $wpdb;
		$game_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}be_games WHERE slug = %s", $this->game_slug ) );

		$cleared = Background_Ledger::clear_for_date( $game_id, '2026-01-01' );

		$this->assertSame( 2, $cleared );
		$this->assertCount( 0, Background_Ledger::for_character_date( $this->character_id, '2026-01-01' ) );
		$this->assertCount( 0, Background_Ledger::for_character_date( $other_character, '2026-01-01' ) );
		$this->assertCount( 1, Background_Ledger::for_character_date( $this->character_id, '2026-02-01' ), 'a different date is untouched' );
	}
}
