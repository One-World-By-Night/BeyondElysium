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
 * The plugin's scheduled jobs: a daily sweep that closes what has outlived its time -
 * verification codes past their expiry, transfer offers and pending transfers nobody acted
 * on (1.0.0-review F-014, F-006), player-sent Grapevine files nobody reviewed (F-122), and
 * one digest email per user with any plot posts queued for daily delivery (1.1.0 §3.5) -
 * plus a quarter-hour release-batch sweep (1.1.0 §3.2). Both scheduled on `init` when
 * missing, cleared on deactivation.
 */
class Maintenance {

	const HOOK = 'be_daily_maintenance';

	/** Fires when a single scheduled batch comes due (§3.2) - scheduled directly against
	 *  Release_Engine::release(), with no wrapper method needed. */
	const RELEASE_SINGLE_HOOK = 'be_release_batch';

	/** The quarter-hour catch-all for a batch whose single event was missed, or that
	 *  predates this feature ever having scheduled one for it (§3.2). */
	const RELEASE_SWEEP_HOOK = 'be_release_sweep';

	/** WordPress ships hourly/twicedaily/daily/weekly only - none fine enough for
	 *  "visible within 15 minutes of its due time" (§3.2). */
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
	 * Schedules both sweeps unless they already are - an install that upgrades into this
	 * version gets the release sweep on its next page load, same as the daily one always has.
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
	 * Runs the daily sweep: expired transfers first, so a stale pending transfer's
	 * code is revoked with it, then every code past its expiry.
	 */
	public static function run(): void {
		Transfer::expire_stale();
		Attestation::sweep_expired();
		Submission::expire_stale();
		Notifications::send_daily_digests();
	}

	/**
	 * Runs the quarter-hour release-batch sweep (§3.2, and §3's own schedule-generation
	 * step): first generates any batch a chronicle's own recurring release-schedule rules
	 * call for right now (Release_Scheduler::run()), then releases every scheduled batch
	 * whose release_at has passed - including one this same pass just generated, so a
	 * scheduled batch is never left waiting a full quarter-hour for its own release.
	 * Release_Engine::release() is idempotent, so a batch a single event already released
	 * here is a harmless no-op, not a second round of emails.
	 */
	public static function run_release_sweep(): void {
		Release_Scheduler::run();

		foreach ( Release_Batch::due() as $batch ) {
			Release_Engine::release( (int) $batch->id );
		}
	}
}
