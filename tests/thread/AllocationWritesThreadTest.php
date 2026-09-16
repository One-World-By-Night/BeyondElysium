<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/RowLockProbe.php';

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Background_Ledger;
use BeyondElysium\Tests\Support\RowLockProbe;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-091 (Pass H intake `t3-content-controllers`). Allocating a character's actions
 * made the date's plot, the link marking it as theirs, and one entry per action as separate
 * writes. One failing part-way left a plot with no owner - which every member could then open,
 * with the character's action budget in it - or a plot with some of its actions missing. Nothing
 * held two allocations for the same character apart either: on local MySQL, six at once made six
 * plots for one character and date. Recording a background use shared the plot half, and
 * reported a use as recorded when its entry never saved.
 */
class AllocationWritesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-allocation-writes';
	private int $game_id;
	private int $character_id;
	private string $broken_table = '';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => 'Allocation Writes',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [ 'apr' => [ 'personal_actions' => 2, 'background_actions' => [ 'Resources' ], 'actions_per_level' => [] ] ] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->character_id = (int) Character::create( [
			'name' => 'Allocation Writes Character', 'stack_slug' => 'aw-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'sheet_data' => [ 'aw-stack-backgrounds' => [ [ 'name' => 'Resources', 'count' => 3 ] ] ],
		] );
		Manager::insert( 'schema_blocks', [
			'slug' => 'aw-stack-backgrounds', 'name' => 'Backgrounds', 'section_type' => 'trait_list',
			'definition' => wp_json_encode( [ 'items' => [ [ 'name' => 'Resources', 'source' => 'Backgrounds' ] ] ] ),
			'is_system' => 0, 'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		remove_filter( 'query', [ $this, 'break_inserts' ] );
		parent::tear_down();
	}

	/** Fails every insert into one table, as a lost connection or lock timeout would. */
	public function break_inserts( string $query ): string {
		global $wpdb;
		return $this->broken_table !== '' && str_starts_with( ltrim( $query ), "INSERT INTO `{$wpdb->prefix}be_{$this->broken_table}`" )
			? 'INSERT INTO be_no_such_table VALUES (1)'
			: $query;
	}

	/**
	 * @return mixed Whatever the call returns, with inserts into `$table` failing.
	 */
	private function with_failing_inserts( string $table, callable $call ) {
		global $wpdb;
		$this->broken_table = $table;
		add_filter( 'query', [ $this, 'break_inserts' ] );
		$quiet = $wpdb->suppress_errors( true );
		try {
			return $call();
		} finally {
			$wpdb->suppress_errors( $quiet );
			remove_filter( 'query', [ $this, 'break_inserts' ] );
			$this->broken_table = '';
		}
	}

	private function plots_on( string $date ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}be_plots WHERE game_id = %d AND game_date = %s", $this->game_id, $date ) );
	}

	public function test_an_allocation_whose_owner_link_fails_leaves_no_plot(): void {
		$plot_id = $this->with_failing_inserts( 'connections', fn() => Action_Allocator::persist( $this->character_id, '2026-11-01' ) );

		$this->assertSame( 0, $plot_id );
		$this->assertSame( 0, $this->plots_on( '2026-11-01' ), 'A plot with no owner is open to every member.' );
	}

	public function test_an_allocation_whose_action_entries_fail_leaves_no_plot(): void {
		$plot_id = $this->with_failing_inserts( 'plot_entries', fn() => Action_Allocator::persist( $this->character_id, '2026-11-01' ) );

		$this->assertSame( 0, $plot_id );
		$this->assertSame( 0, $this->plots_on( '2026-11-01' ) );
	}

	public function test_the_route_says_an_allocation_failed(): void {
		$response = $this->with_failing_inserts( 'plot_entries', function () {
			$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/plots/allocate-actions" );
			$request->set_body_params( [ 'character_id' => $this->character_id, 'game_date' => '2026-11-01', 'commit' => true ] );
			return rest_get_server()->dispatch( $request );
		} );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'allocation_failed', $response->as_error()->get_error_code() );
	}

	public function test_a_background_use_that_fails_to_save_is_not_reported_as_recorded(): void {
		$result = $this->with_failing_inserts( 'plot_entries', fn() => Background_Ledger::record( $this->character_id, '2026-11-01', [ 'name' => 'Resources', 'text' => 'Bought a building' ] ) );

		$this->assertWPError( $result );
		$this->assertSame( 0, $this->plots_on( '2026-11-01' ) );
	}

	public function test_another_allocation_for_the_character_waits_for_the_first(): void {
		// A committed character, so a second connection's lock attempt measures this allocation's hold.
		$isolde = Character::find_by_name_in_game( 'Isolde Marchetti', 'be-demo' );
		$this->assertNotNull( $isolde );

		global $wpdb;
		$lockable = null;
		$probe    = function ( $query ) use ( $isolde, $wpdb, &$lockable ) {
			if ( $lockable === null && str_starts_with( ltrim( $query ), "INSERT INTO `{$wpdb->prefix}be_plots`" ) ) {
				$lockable = RowLockProbe::could_lock( 'characters', (int) $isolde->id );
			}
			return $query;
		};

		add_filter( 'query', $probe );
		$plot_id = Action_Allocator::persist( (int) $isolde->id, '2026-11-01' );
		remove_filter( 'query', $probe );

		$this->assertGreaterThan( 0, $plot_id );
		$this->assertFalse( $lockable, 'A second allocation could start for the same character mid-write.' );
	}
}
