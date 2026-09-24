<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Release_Batch;

defined( 'ABSPATH' ) || exit;

/**
 * Releases a chronicle's own prepared draft batches on a recurring schedule.
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
	 * The chronicle's own release-schedule rules, or an empty array when it has none.
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
	 * Whether a rule is due right now: its weekday or day-of-month matches today, its time of day has passed, and it has
	 * not already fired today (`last_run_date`).
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
	 * Promotes every draft batch this chronicle already holds to scheduled-and-due, for a rule due right now.
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
