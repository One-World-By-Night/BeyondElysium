<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Maintenance;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Services\Release_Scheduler;
use WP_UnitTestCase;

/**
 * Chronicle-level recurring release-schedule presets.
 */
class ReleaseSchedulerThreadTest extends WP_UnitTestCase {

	private function make_game( string $slug, array $settings = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $slug,
			'name'       => $slug,
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( $settings ),
		] );
		return (int) $wpdb->insert_id;
	}

	/**
	 * A real draft batch (no release_at) for a chronicle.
	 */
	private function make_draft( int $game_id, string $name = 'Prepared batch' ): int {
		return (int) Release_Batch::create( [ 'game_id' => $game_id, 'name' => $name ] );
	}

	private function past_time(): string {
		return '00:01';
	}

	public function test_a_chronicle_with_no_rules_is_untouched(): void {
		$slug = 'thread-scheduler-none';
		$game_id = $this->make_game( $slug, [] );
		$this->make_draft( $game_id );

		Release_Scheduler::run();

		$this->assertSame( 'draft', Release_Batch::for_game( $game_id )[0]->status );
	}

	public function test_a_chronicle_with_a_due_rule_but_no_drafts_releases_nothing(): void {
		$slug = 'thread-scheduler-no-drafts';
		$game_id = $this->make_game( $slug, [
			'release_schedule' => [
				'rules' => [
					[ 'type' => 'weekly', 'weekday' => strtolower( current_time( 'l' ) ), 'time' => $this->past_time() ],
				],
			],
		] );

		Release_Scheduler::run();

		$this->assertCount( 0, Release_Batch::for_game( $game_id ) );
	}

	public function test_a_weekly_rule_due_today_releases_a_prepared_draft(): void {
		$slug = 'thread-scheduler-weekly';
		$game_id = $this->make_game( $slug, [
			'release_schedule' => [
				'rules' => [
					[ 'type' => 'weekly', 'weekday' => strtolower( current_time( 'l' ) ), 'time' => $this->past_time() ],
				],
			],
		] );
		$this->make_draft( $game_id );

		Release_Scheduler::run();

		$batches = Release_Batch::for_game( $game_id );
		$this->assertCount( 1, $batches );
		$this->assertSame( 'scheduled', $batches[0]->status );
		$this->assertNotNull( $batches[0]->release_at );
	}

	public function test_a_weekly_rule_for_a_different_weekday_does_not_fire(): void {
		$slug = 'thread-scheduler-other-weekday';
		$today = strtolower( current_time( 'l' ) );
		$other = current( array_diff( [ 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ], [ $today ] ) );
		$game_id = $this->make_game( $slug, [
			'release_schedule' => [
				'rules' => [ [ 'type' => 'weekly', 'weekday' => $other, 'time' => $this->past_time() ] ],
			],
		] );
		$this->make_draft( $game_id );

		Release_Scheduler::run();

		$this->assertSame( 'draft', Release_Batch::for_game( $game_id )[0]->status );
	}

	public function test_a_rule_whose_time_has_not_yet_passed_does_not_fire(): void {
		$slug   = 'thread-scheduler-future-time';
		$future = '23:59';
		if ( current_time( 'H:i' ) >= $future ) {
			$this->markTestSkipped( 'Cannot construct a later same-day time right now.' );
		}
		$game_id = $this->make_game( $slug, [
			'release_schedule' => [
				'rules' => [ [ 'type' => 'weekly', 'weekday' => strtolower( current_time( 'l' ) ), 'time' => $future ] ],
			],
		] );
		$this->make_draft( $game_id );

		Release_Scheduler::run();

		$this->assertSame( 'draft', Release_Batch::for_game( $game_id )[0]->status );
	}

	public function test_a_monthly_rule_due_today_releases_a_prepared_draft(): void {
		$slug = 'thread-scheduler-monthly';
		if ( (int) current_time( 'j' ) > 28 ) {
			$this->markTestSkipped( 'Today is past day 28; a day-of-month rule cannot name it.' );
		}
		$game_id = $this->make_game( $slug, [
			'release_schedule' => [
				'rules' => [ [ 'type' => 'monthly', 'day_of_month' => (int) current_time( 'j' ), 'time' => $this->past_time() ] ],
			],
		] );
		$this->make_draft( $game_id );

		Release_Scheduler::run();

		$this->assertSame( 'scheduled', Release_Batch::for_game( $game_id )[0]->status );
	}

	public function test_two_rules_due_the_same_day_still_release_each_draft_once(): void {
		$slug = 'thread-scheduler-merge';
		if ( (int) current_time( 'j' ) > 28 ) {
			$this->markTestSkipped( 'Today is past day 28; the monthly rule cannot name it.' );
		}
		$game_id = $this->make_game( $slug, [
			'release_schedule' => [
				'rules' => [
					[ 'type' => 'weekly', 'weekday' => strtolower( current_time( 'l' ) ), 'time' => $this->past_time() ],
					[ 'type' => 'monthly', 'day_of_month' => (int) current_time( 'j' ), 'time' => $this->past_time() ],
				],
			],
		] );
		$this->make_draft( $game_id );

		Release_Scheduler::run();

		$this->assertCount( 1, Release_Batch::for_game( $game_id ) );
	}

	public function test_multiple_prepared_drafts_all_release_together(): void {
		$slug = 'thread-scheduler-multi-draft';
		$game_id = $this->make_game( $slug, [
			'release_schedule' => [
				'rules' => [
					[ 'type' => 'weekly', 'weekday' => strtolower( current_time( 'l' ) ), 'time' => $this->past_time() ],
				],
			],
		] );
		$this->make_draft( $game_id, 'Rumors' );
		$this->make_draft( $game_id, 'Downtime answers' );

		Release_Scheduler::run();

		$batches = Release_Batch::for_game( $game_id );
		$this->assertCount( 2, $batches );
		foreach ( $batches as $batch ) {
			$this->assertSame( 'scheduled', $batch->status );
		}
	}

	public function test_a_rule_already_fired_today_does_not_fire_again(): void {
		$slug = 'thread-scheduler-idempotent';
		$game_id = $this->make_game( $slug, [
			'release_schedule' => [
				'rules' => [
					[
						'type'          => 'weekly',
						'weekday'       => strtolower( current_time( 'l' ) ),
						'time'          => $this->past_time(),
						'last_run_date' => current_time( 'Y-m-d' ),
					],
				],
			],
		] );
		$this->make_draft( $game_id );

		Release_Scheduler::run();

		$this->assertSame( 'draft', Release_Batch::for_game( $game_id )[0]->status );
	}

	public function test_running_twice_the_same_day_does_not_re_release_an_already_scheduled_batch(): void {
		$slug = 'thread-scheduler-run-twice';
		$game_id = $this->make_game( $slug, [
			'release_schedule' => [
				'rules' => [
					[ 'type' => 'weekly', 'weekday' => strtolower( current_time( 'l' ) ), 'time' => $this->past_time() ],
				],
			],
		] );
		$this->make_draft( $game_id );

		Release_Scheduler::run();
		$after_first = Release_Batch::for_game( $game_id )[0]->release_at;

		Release_Scheduler::run();
		$after_second = Release_Batch::for_game( $game_id )[0]->release_at;

		// The cursor advanced after the first run.
		$this->assertSame( $after_first, $after_second );
	}

	public function test_maintenance_sweep_promotes_and_releases_in_the_same_pass(): void {
		$slug = 'thread-scheduler-sweep';
		$game_id = $this->make_game( $slug, [
			'release_schedule' => [
				'rules' => [
					[ 'type' => 'weekly', 'weekday' => strtolower( current_time( 'l' ) ), 'time' => $this->past_time() ],
				],
			],
		] );
		$this->make_draft( $game_id );

		Maintenance::run_release_sweep();

		$batches = Release_Batch::for_game( $game_id );
		$this->assertCount( 1, $batches );
		$this->assertSame( 'released', $batches[0]->status );
		$this->assertNotNull( $batches[0]->notified_at );
	}
}
