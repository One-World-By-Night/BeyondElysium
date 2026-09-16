<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Saved_Query;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-092 (Pass H intake `t3-models`). A Storyteller's "Most Recent Search" is one row
 * per chronicle, found and updated on every run - and made when none is found. Two first runs at
 * once, from two tabs, each found none and each made one, and both stayed in the saved-query list
 * for good.
 */
class RecentSearchDuplicateThreadTest extends WP_UnitTestCase {

	private int $game_id = 424242;
	private int $user_id;
	private bool $raced = false;

	public function setUp(): void {
		parent::setUp();
		$this->user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
	}

	public function tear_down(): void {
		remove_filter( 'query', [ $this, 'another_tab_saves_first' ] );
		parent::tear_down();
	}

	/** The other tab's first run lands between this run's look for a row and its insert. */
	public function another_tab_saves_first( string $query ): string {
		global $wpdb;
		if ( ! $this->raced && str_starts_with( ltrim( $query ), "INSERT INTO `{$wpdb->prefix}be_queries`" ) ) {
			$this->raced = true;
			$this->recent_row( [ [ 'field' => 'clan', 'operator' => 'equals', 'value' => 'Brujah' ] ] );
		}
		return $query;
	}

	private function recent_row( array $conditions ): int {
		return (int) Saved_Query::create( [
			'game_id' => $this->game_id, 'name' => 'Most Recent Search', 'inventory' => 'char', 'match_all' => true,
			'conditions' => $conditions, 'created_by' => $this->user_id, 'is_recent_search' => true,
		] );
	}

	/**
	 * @return object[]
	 */
	private function recent_rows(): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}be_queries WHERE game_id = %d AND created_by = %d AND is_recent_search = 1",
			$this->game_id,
			$this->user_id
		) );
	}

	public function test_two_first_runs_at_once_leave_one_recent_search(): void {
		add_filter( 'query', [ $this, 'another_tab_saves_first' ] );
		$id = Saved_Query::save_recent( $this->game_id, $this->user_id, 'char', true, [] );
		remove_filter( 'query', [ $this, 'another_tab_saves_first' ] );

		$this->assertTrue( $this->raced );
		$rows = $this->recent_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( (int) $rows[0]->id, $id );
	}

	public function test_a_run_folds_recent_searches_already_doubled_into_one(): void {
		$first = $this->recent_row( [] );
		$this->recent_row( [] );

		$conditions = [ [ 'field' => 'name', 'operator' => 'contains', 'value' => 'Isolde' ] ];
		$id         = Saved_Query::save_recent( $this->game_id, $this->user_id, 'char', false, $conditions );

		$rows = $this->recent_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( $first, $id );
		// A JSON column keeps its own key order.
		$this->assertEquals( $conditions, json_decode( $rows[0]->conditions, true ) );
	}

	public function test_named_saved_queries_are_never_touched(): void {
		$named = (int) Saved_Query::create( [
			'game_id' => $this->game_id, 'name' => 'Brujah roster', 'inventory' => 'char', 'match_all' => true,
			'conditions' => [], 'created_by' => $this->user_id,
		] );
		$this->recent_row( [] );
		$this->recent_row( [] );

		Saved_Query::save_recent( $this->game_id, $this->user_id, 'char', true, [] );

		$this->assertNotNull( Saved_Query::find( $named ) );
	}
}
