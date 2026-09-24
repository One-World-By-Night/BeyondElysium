<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Models\Submission;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Release_Engine;
use BeyondElysium\Services\Release_Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's scheduled jobs: a daily sweep that closes what has outlived its time, and a quarter-hour
 * release-batch sweep.
 */
class Maintenance {

	const HOOK = 'be_daily_maintenance';

	/**
	 * Fires when a single scheduled batch comes due.
	 */
	const RELEASE_SINGLE_HOOK = 'be_release_batch';

	/**
	 * The quarter-hour sweep for any batch whose own event did not run.
	 */
	const RELEASE_SWEEP_HOOK = 'be_release_sweep';

	/**
	 * The fifteen-minute cron interval the release sweep runs on.
	 */
	const RELEASE_SWEEP_SCHEDULE = 'be_quarter_hour';

	/**
	 * Hooks both sweeps and their scheduling.
	 */
	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );
		add_action( 'init', [ self::class, 'schedule' ] );

		add_filter( 'cron_schedules', [ self::class, 'add_quarter_hour_schedule' ] );
		add_action( self::RELEASE_SWEEP_HOOK, [ self::class, 'run_release_sweep' ] );
		add_action( self::RELEASE_SINGLE_HOOK, static function ( int $batch_id ): void {
			Release_Engine::release( $batch_id );
		} );
	}

	/**
	 * Registers the `be_quarter_hour` cron schedule.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function add_quarter_hour_schedule( array $schedules ): array {
		$schedules[ self::RELEASE_SWEEP_SCHEDULE ] = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (Beyond Elysium)', 'beyond-elysium' ),
		];
		return $schedules;
	}

	/**
	 * Schedules both sweeps unless they already are.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
		if ( ! wp_next_scheduled( self::RELEASE_SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, self::RELEASE_SWEEP_SCHEDULE, self::RELEASE_SWEEP_HOOK );
		}
	}

	/**
	 * Removes both scheduled sweeps.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::RELEASE_SWEEP_HOOK );
	}

	/**
	 * Runs the daily sweep of expired and unreviewed items.
	 */
	public static function run(): void {
		Transfer::expire_stale();
		Attestation::sweep_expired();
		Submission::expire_stale();
		Notifications::send_daily_digests();
	}

	/**
	 * Runs the quarter-hour release-batch sweep: generates the batches recurring schedules call for, then releases every
	 * scheduled batch that has come due.
	 */
	public static function run_release_sweep(): void {
		Release_Scheduler::run();

		foreach ( Release_Batch::due() as $batch ) {
			Release_Engine::release( (int) $batch->id );
		}
	}
}
