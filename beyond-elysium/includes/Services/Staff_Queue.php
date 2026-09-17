<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Npc_Casting;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;

defined( 'ABSPATH' ) || exit;

/**
 * The four sections of `GET /{game}/my/queue` (1.1.0 §3.6): what a Storyteller or Narrator is
 * personally on the hook for in one chronicle, plus what nobody owns yet.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.6
 */
class Staff_Queue {

	/**
	 * Unanswered action-allocation plots assigned to one staff member, any game date.
	 *
	 * @param int $wp_user_id
	 * @param int $game_id
	 * @return array[] Downtime_Window::unanswered_assigned_to()'s own row shape.
	 */
	public static function downtime( int $wp_user_id, int $game_id ): array {
		return Downtime_Window::unanswered_assigned_to( $wp_user_id, $game_id );
	}

	/**
	 * Ordinary plots (never an action-allocation plot - that belongs to downtime() instead)
	 * assigned to one staff member, whose newest player post is newer than its newest staff
	 * post - the plots actually waiting on this person, not every plot they merely own.
	 *
	 * @param int $wp_user_id
	 * @param int $game_id
	 * @return array[] Each: plot_id, title, newest_player_post_at, newest_staff_post_at.
	 */
	public static function plots( int $wp_user_id, int $game_id ): array {
		$rows = [];

		foreach ( Plot::for_game( $game_id, [ 'assigned_to' => $wp_user_id ] ) as $plot ) {
			if ( Action_Allocator::actor_character_id( (int) $plot->id ) !== null ) {
				continue;
			}

			$waiting = self::waiting_on_staff( (int) $plot->id );
			if ( $waiting === null ) {
				continue;
			}

			$rows[] = array_merge( [ 'plot_id' => (int) $plot->id, 'title' => (string) $plot->title ], $waiting );
		}

		usort( $rows, static fn( $a, $b ) => (string) $a['newest_player_post_at'] <=> (string) $b['newest_player_post_at'] );

		return $rows;
	}

	/**
	 * My castings for sessions today or later (§3.8) - any chronicle member's, not just a
	 * Storyteller's, since anyone may be cast; this section of My Queue is simply the one
	 * place a cast Storyteller would see it alongside their other work.
	 *
	 * @param int $wp_user_id
	 * @param int $game_id
	 * @return object[] Each: casting_id, character_id, character_name, session_id, game_date,
	 *                  start_time, place - Npc_Casting::upcoming_for_user()'s own row shape.
	 */
	public static function castings( int $wp_user_id, int $game_id ): array {
		return Npc_Casting::upcoming_for_user( $wp_user_id, $game_id, current_time( 'Y-m-d' ) );
	}

	/**
	 * Counts of unanswered downtime and unanswered plot posts nobody owns, so nothing falls
	 * through an empty assignment.
	 *
	 * @param int $game_id
	 * @return array{downtime: int, plots: int}
	 */
	public static function unassigned( int $game_id ): array {
		$downtime_count = 0;
		foreach ( Plot::for_game( $game_id, [ 'assigned_to' => null ] ) as $plot ) {
			// game_date IS NULL is a character's own permanent home plot (Character::ensure_plot()),
			// not a dated round - it carries the same apr_actor connection but is never itself
			// something to answer, matching Action_Allocator::plots_assigned_to()'s own exclusion.
			if ( $plot->game_date === null || Action_Allocator::actor_character_id( (int) $plot->id ) === null ) {
				continue;
			}
			[ $action_count, , $answer ] = self::action_plot_state( (int) $plot->id );
			if ( $action_count > 0 && $answer === null ) {
				++$downtime_count;
			}
		}

		$plots_count = 0;
		foreach ( Plot::for_game( $game_id, [ 'assigned_to' => null ] ) as $plot ) {
			if ( Action_Allocator::actor_character_id( (int) $plot->id ) !== null ) {
				continue;
			}
			if ( self::waiting_on_staff( (int) $plot->id ) !== null ) {
				++$plots_count;
			}
		}

		return [ 'downtime' => $downtime_count, 'plots' => $plots_count ];
	}

	/**
	 * Whether an ordinary plot's newest player (`action`) post is newer than its newest staff
	 * post - a held entry or a Storyteller-only `note`/`rumor_level` never counts as a staff
	 * post, matching §3.5's own definition of what a Storyteller "post" is. Null when there is
	 * no player post at all, or the newest post already belongs to staff.
	 *
	 * @param int $plot_id
	 * @return array{newest_player_post_at: string, newest_staff_post_at: ?string}|null
	 */
	private static function waiting_on_staff( int $plot_id ): ?array {
		$newest_player_id = 0;
		$newest_player_at = null;
		foreach ( Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] ) as $entry ) {
			if ( (int) $entry->id > $newest_player_id ) {
				$newest_player_id = (int) $entry->id;
				$newest_player_at = $entry->created_at;
			}
		}
		if ( $newest_player_id === 0 ) {
			return null;
		}

		$newest_staff_id = 0;
		$newest_staff_at = null;
		foreach ( Plot_Entry::for_plot( $plot_id ) as $entry ) {
			if ( in_array( $entry->entry_type, [ 'action', 'note', 'rumor_level' ], true ) || ! empty( $entry->held ) ) {
				continue;
			}
			if ( (int) $entry->id > $newest_staff_id ) {
				$newest_staff_id = (int) $entry->id;
				$newest_staff_at = $entry->created_at;
			}
		}

		if ( $newest_staff_id > $newest_player_id ) {
			return null;
		}

		return [ 'newest_player_post_at' => (string) $newest_player_at, 'newest_staff_post_at' => $newest_staff_at ];
	}

	/**
	 * An action-allocation plot's action count, last action time, and current answer - the
	 * same primitive Downtime_Window's own queue uses, duplicated at this narrow scope rather
	 * than exposed there, since unassigned() only ever needs the answered/unanswered boolean,
	 * never the full queue row shape.
	 *
	 * @param int $plot_id
	 * @return array{0:int,1:?string,2:?object}
	 */
	private static function action_plot_state( int $plot_id ): array {
		$actions        = Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] );
		$last_action_at = null;
		$last_action_id = 0;
		foreach ( $actions as $action ) {
			if ( $last_action_at === null || $action->created_at > $last_action_at ) {
				$last_action_at = $action->created_at;
			}
			$last_action_id = max( $last_action_id, (int) $action->id );
		}
		if ( $last_action_id === 0 ) {
			return [ count( $actions ), $last_action_at, null ];
		}

		$answer = null;
		foreach ( Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'response' ] ) as $response ) {
			if ( (int) $response->id > $last_action_id && ( $answer === null || (int) $response->id > (int) $answer->id ) ) {
				$answer = $response;
			}
		}

		return [ count( $actions ), $last_action_at, $answer ];
	}
}
