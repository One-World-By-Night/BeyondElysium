<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Release_Batch;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a character's downtime is open for a game date (1.1.0 §3.3), and the Storyteller
 * queue of that date's action plots.
 *
 * A game date's window is stored on its session (`downtime_opens_at`/`downtime_deadline_at`,
 * §3.1) - no session for that date, or a session with both fields empty, means no window at
 * all, today's behavior unchanged. A character's own `downtime_extensions` entry replaces the
 * deadline for that character only, never the open time.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.3
 */
class Downtime_Window {

	const NONE     = 'none';
	const NOT_OPEN = 'not_open';
	const OPEN     = 'open';
	const CLOSED   = 'closed';

	/**
	 * A character's downtime state for a game date. `none` when the date has no session, or a
	 * session with neither window field set - the caller should not enforce anything in that
	 * case, matching today's behavior.
	 *
	 * @param int    $game_id
	 * @param string $game_date `Y-m-d`.
	 * @param int    $character_id
	 * @return string One of NONE/NOT_OPEN/OPEN/CLOSED.
	 */
	public static function state( int $game_id, string $game_date, int $character_id ): string {
		$session = Game_Session::find_by_date( $game_id, $game_date );
		if ( ! $session ) {
			return self::NONE;
		}

		$extensions = is_array( $session->downtime_extensions ) ? $session->downtime_extensions : [];
		$extension  = $extensions[ (string) $character_id ] ?? null;

		return self::state_for(
			$session->downtime_opens_at,
			$session->downtime_deadline_at,
			is_string( $extension ) ? $extension : null,
			current_time( 'mysql' )
		);
	}

	/**
	 * The pure rule behind state(): an extension, when present, replaces the deadline for that
	 * character only - it never moves the open time. Neither field set at all means there is no
	 * window to enforce, distinct from a window that is simply open with no deadline.
	 *
	 * @param string|null $opens     `Y-m-d H:i:s`, or null for "already open from the start."
	 * @param string|null $deadline  `Y-m-d H:i:s`, or null for "never closes."
	 * @param string|null $extension This character's own deadline override, or null.
	 * @param string      $now       `Y-m-d H:i:s`, the same format `current_time('mysql')` returns.
	 * @return string One of NONE/NOT_OPEN/OPEN/CLOSED.
	 */
	public static function state_for( ?string $opens, ?string $deadline, ?string $extension, string $now ): string {
		$effective_deadline = ! empty( $extension ) ? $extension : $deadline;

		if ( empty( $opens ) && empty( $effective_deadline ) ) {
			return self::NONE;
		}
		if ( ! empty( $opens ) && $now < $opens ) {
			return self::NOT_OPEN;
		}
		if ( ! empty( $effective_deadline ) && $now > $effective_deadline ) {
			return self::CLOSED;
		}
		return self::OPEN;
	}

	/**
	 * The Storyteller queue for one game date (§3.3): one row per action-allocation plot for
	 * that date, unanswered first, then oldest action first.
	 *
	 * @param int    $game_id
	 * @param string $game_date `Y-m-d`.
	 * @return array[] Each: plot_id, character_id, character_name, player_id, player_name,
	 *                  action_count, last_action_at, answered, answer_release_state, window_state,
	 *                  assigned_to (§3.6, null when unassigned).
	 */
	public static function queue_for_date( int $game_id, string $game_date ): array {
		$rows = [];

		foreach ( Action_Allocator::plots_for_date( $game_id, $game_date ) as $plot ) {
			$character = Character::find( (int) $plot->character_id );
			if ( ! $character ) {
				continue;
			}

			[ $action_count, $last_action_at, $answer ] = self::last_action_and_answer( (int) $plot->plot_id );
			$player                                     = $character->wp_user_id ? get_userdata( (int) $character->wp_user_id ) : null;

			$rows[] = [
				'plot_id'              => (int) $plot->plot_id,
				'character_id'         => (int) $character->id,
				'character_name'       => (string) $character->name,
				'player_id'            => $character->wp_user_id ? (int) $character->wp_user_id : null,
				'player_name'          => $player ? $player->display_name : null,
				'action_count'         => $action_count,
				'last_action_at'       => $last_action_at,
				'answered'             => $answer !== null,
				'answer_release_state' => self::answer_release_state( $answer ),
				'window_state'         => self::state( $game_id, $game_date, (int) $character->id ),
				'assigned_to'          => $plot->assigned_to !== null ? (int) $plot->assigned_to : null,
			];
		}

		usort( $rows, static function ( $a, $b ) {
			if ( $a['answered'] !== $b['answered'] ) {
				return $a['answered'] <=> $b['answered'];
			}
			return (string) $a['last_action_at'] <=> (string) $b['last_action_at'];
		} );

		return $rows;
	}

	/**
	 * Every unanswered action-allocation plot assigned to one staff member, across every game
	 * date - the My Queue downtime section (1.1.0 §3.6: "unanswered action plots assigned to
	 * me, any game date"). Answered plots are left out entirely, not just sorted last, unlike
	 * queue_for_date()'s own single-date view.
	 *
	 * @param int $wp_user_id
	 * @param int $game_id
	 * @return array[] Each: plot_id, character_id, character_name, game_date, action_count, last_action_at.
	 */
	public static function unanswered_assigned_to( int $wp_user_id, int $game_id ): array {
		$rows = [];

		foreach ( Action_Allocator::plots_assigned_to( $wp_user_id, $game_id ) as $plot ) {
			$character = Character::find( (int) $plot->character_id );
			if ( ! $character ) {
				continue;
			}

			[ $action_count, $last_action_at, $answer ] = self::last_action_and_answer( (int) $plot->plot_id );
			if ( $answer !== null || $action_count === 0 ) {
				continue;
			}

			$rows[] = [
				'plot_id'        => (int) $plot->plot_id,
				'character_id'   => (int) $character->id,
				'character_name' => (string) $character->name,
				'game_date'      => $plot->game_date,
				'action_count'   => $action_count,
				'last_action_at' => $last_action_at,
			];
		}

		usort( $rows, static fn( $a, $b ) => (string) $a['last_action_at'] <=> (string) $b['last_action_at'] );

		return $rows;
	}

	/**
	 * One action-allocation plot's action count, most recent action timestamp, and its current
	 * answer (the response entry, if any, whose id is newer than the newest action's - matching
	 * an answer to the action it actually answers by insertion order, never a string timestamp
	 * comparison, which two entries created within the same second can tie on).
	 *
	 * @param int $plot_id
	 * @return array{0:int,1:?string,2:?object} [action_count, last_action_at, answer entry or null].
	 */
	private static function last_action_and_answer( int $plot_id ): array {
		$actions        = Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] );
		$last_action_at = null;
		$last_action_id = 0;
		foreach ( $actions as $action ) {
			if ( $last_action_at === null || $action->created_at > $last_action_at ) {
				$last_action_at = $action->created_at;
			}
			$last_action_id = max( $last_action_id, (int) $action->id );
		}

		$answer = null;
		foreach ( Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'response' ] ) as $response ) {
			if ( $last_action_id > 0 && (int) $response->id > $last_action_id
				&& ( $answer === null || (int) $response->id > (int) $answer->id ) ) {
				$answer = $response;
			}
		}

		return [ count( $actions ), $last_action_at, $answer ];
	}

	/**
	 * @param object|null $answer A decoded plot_entries row, or null when there is none yet.
	 * @return string 'not_answered', 'immediate', 'draft', 'scheduled', or 'released'.
	 */
	private static function answer_release_state( $answer ): string {
		if ( $answer === null ) {
			return 'not_answered';
		}
		if ( empty( $answer->held ) ) {
			return 'immediate';
		}
		if ( empty( $answer->release_batch_id ) ) {
			return 'draft';
		}
		$batch = Release_Batch::find( (int) $answer->release_batch_id );
		return $batch ? (string) $batch->status : 'draft';
	}
}
