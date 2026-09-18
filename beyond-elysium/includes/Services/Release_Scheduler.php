<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Release_Batch;

defined( 'ABSPATH' ) || exit;

/**
 * Releases a chronicle's own prepared draft batches on a recurring schedule (1.1.1 §3) -
 * weekly (a named weekday) and monthly (a day of the month, 1-28) rules, stored in
 * `be_games.settings.release_schedule.rules`. The schedule controls *when*, never *what*:
 * on the scheduled day, every batch already sitting in draft for that chronicle is released
 * as-is, exactly what a Storyteller would get manually setting that batch's own release_at -
 * nothing is fabricated. Additive: a chronicle with no rules is untouched, a chronicle whose
 * drafts are all empty on the scheduled day releases nothing, and a manual one-off batch
 * (`Release_Batch::create()`/setting `release_at` directly) works exactly as it always has.
 *
 * This is not the first shape this took. The original version created a brand-new, empty
 * batch the moment a rule became due, then released it in the same pass - which meant
 * whatever a Storyteller had actually prepared in a draft batch that week never went out at
 * all; only an empty, pointless one did. Found by this document's own required pre-deploy
 * trace (§3, happy path) before shipping, not after - see that trace for the full walkthrough.
 *
 * Run from `Core\Maintenance::run_release_sweep()`, immediately before the existing
 * due-batch release loop, so a batch promoted this pass releases the same pass rather than
 * waiting for the next quarter-hour tick.
 *
 * Overlap rule (owner ruling, 2026-09-17): when more than one rule is due for a chronicle on
 * the same calendar day, every currently-draft batch still only releases once - there is
 * nothing to duplicate, since the schedule promotes existing drafts rather than creating one
 * per rule.
 *
 * @see BE_PROCESS/releases/1.1.1-design-workflow.md §3
 */
class Release_Scheduler {

	/** @var string[] Valid `weekly` rule weekday values - shared with the REST controller's own validation. */
	const WEEKDAYS = [ 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ];

	/**
	 * Releases every draft batch for a chronicle whose schedule has a rule due right now.
	 *
	 * @return void
	 */
	public static function run(): void {
		foreach ( Game::all() as $game ) {
			$rules = self::rules_for( $game );
			if ( empty( $rules ) ) {
				continue;
			}
			self::release_drafts_for_game( $game, $rules );
		}
	}

	/**
	 * The chronicle's own release-schedule rules, or an empty array when it has none -
	 * `settings.release_schedule.rules` decodes as nested stdClass, matching every other
	 * `settings` reader in this codebase (Spotlight::for_game(), Sessions_Controller's own
	 * two-step cast).
	 *
	 * @param object $game
	 * @return array<int,object>
	 */
	private static function rules_for( object $game ): array {
		$settings = $game->settings ? (array) $game->settings : [];
		$schedule = isset( $settings['release_schedule'] ) ? (array) $settings['release_schedule'] : [];
		return isset( $schedule['rules'] ) && is_array( $schedule['rules'] ) ? $schedule['rules'] : [];
	}

	/**
	 * Whether a rule is due right now: its own weekday/day-of-month matches today, its own
	 * time-of-day has already passed, and it has not already fired today (`last_run_date`,
	 * the rule's own idempotency cursor - there is no batch row yet at this point to lock
	 * the way Release_Batch::find_for_update() locks an existing one).
	 *
	 * @param object $rule
	 * @param string $today `Y-m-d`.
	 * @param string $now_time `H:i`.
	 * @return bool
	 */
	private static function is_due( object $rule, string $today, string $now_time ): bool {
		if ( ( $rule->last_run_date ?? '' ) === $today ) {
			return false;
		}

		$time = (string) ( $rule->time ?? '00:00' );
		if ( $now_time < $time ) {
			return false;
		}

		if ( ( $rule->type ?? '' ) === 'weekly' ) {
			return strtolower( (string) ( $rule->weekday ?? '' ) ) === strtolower( current_time( 'l' ) );
		}

		if ( ( $rule->type ?? '' ) === 'monthly' ) {
			$day_of_month = max( 1, min( 28, (int) ( $rule->day_of_month ?? 0 ) ) );
			return $day_of_month === (int) current_time( 'j' );
		}

		return false;
	}

	/**
	 * Promotes every draft batch this chronicle already holds to scheduled-and-due, for a
	 * rule due right now, then advances each fired rule's own cursor. A chronicle with no
	 * draft batches has nothing to promote - a quiet week, not an error - but the cursor
	 * still advances so the same rule doesn't re-check every sweep for the rest of the day.
	 *
	 * @param object          $game
	 * @param array<int,object> $rules
	 * @return void
	 */
	private static function release_drafts_for_game( object $game, array $rules ): void {
		$today    = current_time( 'Y-m-d' );
		$now_time = current_time( 'H:i' );

		$due = [];
		foreach ( $rules as $i => $rule ) {
			if ( self::is_due( $rule, $today, $now_time ) ) {
				$due[ $i ] = $rule;
			}
		}
		if ( empty( $due ) ) {
			return;
		}

		foreach ( Release_Batch::for_game( (int) $game->id, 'draft' ) as $draft ) {
			Release_Batch::update( (int) $draft->id, [
				'release_at' => current_time( 'mysql' ),
				'status'     => 'scheduled',
			] );
		}

		$settings                            = (array) $game->settings;
		$schedule                            = isset( $settings['release_schedule'] ) ? (array) $settings['release_schedule'] : [];
		$stored_rules                        = isset( $schedule['rules'] ) && is_array( $schedule['rules'] ) ? $schedule['rules'] : [];
		foreach ( array_keys( $due ) as $i ) {
			if ( isset( $stored_rules[ $i ] ) ) {
				$stored_rules[ $i ]              = (array) $stored_rules[ $i ];
				$stored_rules[ $i ]['last_run_date'] = $today;
			}
		}
		$schedule['rules']       = $stored_rules;
		$settings['release_schedule'] = $schedule;

		Game::update( $game->slug, [ 'settings' => $settings ] );
	}
}
