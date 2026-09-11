<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Action_Allocator;
use WP_UnitTestCase;

/**
 * The database-touching half of the Action Allocator: persisting one plot per
 * character-date, re-running idempotently, carrying state from a real prior plot, and
 * the `IfDoneSetDone` completion rule read back from real entries. The pure computation
 * itself is covered without a database in `tests/unit/ActionAllocatorTest.php`.
 *
 * @see BE_PROCESS/workflow-0.5.md Step 4e/4f
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

	public function test_second_week_carries_unused_and_growth_from_the_first(): void {
		Action_Allocator::persist( $this->character_id, '2026-01-01' );

		// Simulate the ST partially spending week 1's Resources subaction and recording
		// growth, by editing the persisted entry directly the way an ST's PUT would.
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

	public function test_is_complete_is_false_until_every_subaction_has_action_and_result(): void {
		$plot_id = Action_Allocator::persist( $this->character_id, '2026-01-01' );
		$this->assertFalse( Action_Allocator::is_complete( $plot_id ) );

		foreach ( Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] ) as $entry ) {
			$data             = json_decode( $entry->content, true );
			$data['action']   = 'did something';
			$data['result']   = 'it worked';
			Plot_Entry::update( (int) $entry->id, [ 'content' => wp_json_encode( $data ) ] );
		}

		$this->assertTrue( Action_Allocator::is_complete( $plot_id ) );
	}

	public function test_a_players_own_free_text_action_entry_does_not_break_completion_check(): void {
		$plot_id = Action_Allocator::persist( $this->character_id, '2026-01-01' );

		// A player's own plain-text action post on the same plot (entry_type 'action'
		// but not allocator JSON) must be ignored by is_complete()'s decode, not crash it.
		Plot_Entry::create( [
			'plot_id' => $plot_id, 'author_id' => 1, 'entry_type' => 'action',
			'content' => 'I spend Bureaucracy 2 to smooth over the paperwork.',
		] );

		$this->assertFalse( Action_Allocator::is_complete( $plot_id ) );
	}
}
