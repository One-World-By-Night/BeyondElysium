<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Maintenance;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Services\Release_Scheduler;
use WP_UnitTestCase;

/**
 * 1.1.1 §3: chronicle-level recurring release-schedule presets - weekly and monthly rules
 * that release whatever a Storyteller has already prepared as draft batches, on schedule.
 * The schedule controls *when*, never *what*: it never fabricates a batch, only promotes an
 * existing draft to due. Additive - a chronicle with no rules, or no drafts, is untouched.
 *
 * Not this feature's first shape - see Release_Scheduler's own class docblock for the real
 * bug this document's own required pre-deploy trace found before shipping (creating an empty
 * batch instead of releasing a prepared one).
 *
 * Uses today's own real weekday/day-of-month rather than a hardcoded one, so this test is
 * correct on whatever day it actually runs.
 *
 * @see BE_PROCESS/releases/1.1.1-design-workflow.md §3
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

	/** A real draft batch (no release_at) for a chronicle - what a Storyteller prepares ahead of the scheduled day. */
	private function make_draft( int $game_id, string $name = 'Prepared batch' ): int {
		return (int) Release_Batch::create( [ 'game_id' => $game_id, 'name' => $name ] );
	}

	/**
	 * A fixed early-day sentinel, not "now - 1 hour" - the latter wraps to a late-looking
	 * string ("23:xx") when run just after midnight, which the scheduler's own plain string
	 * time comparison (correct for same-day comparisons, all it is ever asked to do at
	 * 15-minute cron granularity) would misread as still in the future.
	 */
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
		// A fixed late-day sentinel, not "now + 1 hour" - the latter wraps to an
		// early-looking string ("00:xx") when run late at night, which the scheduler's
		// own plain string time comparison (correct for same-day comparisons, which is
		// all it is ever asked to do at 15-minute cron granularity) would misread as past.
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

		// One draft existed; two rules being due the same day doesn't duplicate it.
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

		// The cursor advanced after the first run, so the second run's own is_due() check
		// never re-touches this batch's release_at at all.
		$this->assertSame( $after_first, $after_second );
	}

	/**
	 * §3's own real point: a promoted batch is released the same pass, not left waiting for
	 * the next quarter-hour tick - Maintenance::run_release_sweep() is the real cron entry
	 * point, calling Release_Scheduler::run() before its own existing due-batch release loop.
	 */
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
