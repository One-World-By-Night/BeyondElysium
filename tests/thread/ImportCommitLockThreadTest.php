<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-070 (Pass H intake `t1-import`). Committing an import read "has this job been
 * committed?" and wrote the answer only once the whole import had run. Two commits of one job
 * that overlapped - two tabs, two Storytellers, a retry after a slow response - both ran it: an
 * Overwrite's XP change applied twice, a file's items and characters landed twice, a game file
 * made two chronicles. A commit now holds the job while it runs; one that arrives meanwhile is
 * turned away, and one that arrives after gets the stored result.
 *
 * One PHP process cannot run two requests at once, so the overlapping commit is the lock row a
 * running commit holds, written directly.
 */
class ImportCommitLockThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-import-commit-lock';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Import Commit Lock' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function parsed(): array {
		return [
			'version' => 2.399, 'players' => [], 'characters' => [], 'locations' => [], 'rotes' => [], 'actions' => [],
			'plots' => [], 'rumors' => [], 'queries' => [], 'chronicle_title' => 'Locked Chronicle', 'extended_health' => false,
			'items' => [ [
				'name' => 'Lock Test Blade', 'item_type' => 'Weapon', 'item_subtype' => 'Melee', 'level' => 1, 'bonus' => 0,
				'damage_type' => 'Lethal', 'damage_amount' => 1, 'concealability' => 'Jacket', 'temper_list' => [ 'traits' => [] ],
				'ability_list' => [ 'traits' => [] ], 'negative_list' => [ 'traits' => [] ], 'availability' => [ 'traits' => [] ],
				'powers' => '', 'appearance' => '', 'notes' => '',
			] ],
		];
	}

	private function job( string $transient_prefix ): string {
		$job_id = wp_generate_uuid4();
		set_transient( $transient_prefix . $job_id, [ 'game_id' => $this->game_id, 'parsed' => $this->parsed(), 'source_file' => 'lock.gex' ], HOUR_IN_SECONDS );
		return $job_id;
	}

	private function hold( string $job_id ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->options, [ 'option_name' => "be_import_commit_{$job_id}", 'option_value' => (string) time(), 'autoload' => 'off' ] );
	}

	private function let_go( string $job_id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, [ 'option_name' => "be_import_commit_{$job_id}" ] );
	}

	private function blades(): int {
		return count( array_filter( World_Object::for_game( $this->game_id, [ 'object_type' => 'item', 'per_page' => 100 ] ), static fn( $o ) => $o->name === 'Lock Test Blade' ) );
	}

	private function commit( string $route, array $body = [] ) {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_file_commit_that_overlaps_a_running_one_is_turned_away(): void {
		$job_id = $this->job( 'be_import_job_' );
		$route  = "/be/v1/{$this->slug}/import/{$job_id}/commit";
		$this->hold( $job_id );

		$this->assertSame( 409, $this->commit( $route )->get_status() );
		$this->assertSame( 0, $this->blades() );

		$this->let_go( $job_id );
		$this->assertSame( 200, $this->commit( $route )->get_status() );
		$this->assertSame( 200, $this->commit( $route )->get_status(), 'a commit after it gets the stored result' );
		$this->assertSame( 1, $this->blades() );
	}

	public function test_a_game_file_commit_that_overlaps_a_running_one_makes_no_second_chronicle(): void {
		$job_id = $this->job( 'be_game_import_job_' );
		$route  = "/be/v1/import/game/{$job_id}/commit";
		$before = count( Game::all() );
		$this->hold( $job_id );

		$this->assertSame( 409, $this->commit( $route, [ 'target' => [ 'action' => 'create_new', 'name' => 'Locked Chronicle' ] ] )->get_status() );
		$this->assertCount( $before, Game::all() );

		$this->let_go( $job_id );
		$this->assertSame( 200, $this->commit( $route, [ 'target' => [ 'action' => 'create_new', 'name' => 'Locked Chronicle' ] ] )->get_status() );
		$this->assertCount( $before + 1, Game::all() );
	}

	public function test_a_commit_that_fails_lets_the_job_be_committed_again(): void {
		global $wpdb;
		$job_id = $this->job( 'be_import_job_' );
		$route  = "/be/v1/{$this->slug}/import/{$job_id}/commit";
		$fail   = static fn( string $query ) => str_starts_with( $query, 'INSERT INTO' ) && str_contains( $query, 'be_world_objects' ) ? 'INSERT INTO be_thread_no_such_table VALUES (1)' : $query;
		add_filter( 'query', $fail );
		$quiet  = $wpdb->suppress_errors( true );
		$failed = $this->commit( $route );
		$wpdb->suppress_errors( $quiet );
		remove_filter( 'query', $fail );

		$this->assertSame( 500, $failed->get_status() );
		$this->assertSame( 200, $this->commit( $route )->get_status() );
		$this->assertSame( 1, $this->blades() );
	}
}
