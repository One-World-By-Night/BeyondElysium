<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Submission;
use BeyondElysium\Models\Transfer;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's one scheduled job: a daily sweep that closes what has outlived
 * its time - verification codes past their expiry, transfer offers and
 * pending transfers nobody acted on (1.0.0-review F-014, F-006), and
 * player-sent Grapevine files nobody reviewed (F-122). Scheduled on `init`
 * when missing, cleared on deactivation.
 */
class Maintenance {

	const HOOK = 'be_daily_maintenance';

	/**
	 * Hooks the sweep and its scheduling.
	 */
	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );
		add_action( 'init', [ self::class, 'schedule' ] );
	}

	/**
	 * Schedules the daily sweep unless it already is - an install that
	 * upgrades into this version gets it on its next page load.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Removes the scheduled sweep.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Runs the sweep: expired transfers first, so a stale pending transfer's
	 * code is revoked with it, then every code past its expiry.
	 */
	public static function run(): void {
		Transfer::expire_stale();
		Attestation::sweep_expired();
		Submission::expire_stale();
	}
}
