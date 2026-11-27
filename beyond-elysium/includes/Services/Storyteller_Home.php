<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\After_Game_Report;
use BeyondElysium\Models\Attendance;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Coordinator_Request;
use BeyondElysium\Models\Discord_Link;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Join_Request;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * What a Storyteller's home in one chronicle shows: what waits on them, the next game night and who has had no
 * attention. Each part is present only for someone who holds the capability behind it.
 */
class Storyteller_Home {

	/**
	 * How long the two slow reads are kept, in seconds.
	 */
	private const CACHE_TTL = MINUTE_IN_SECONDS;

	/**
	 * @param object $game
	 * @param int    $wp_user_id
	 * @param bool   $manage_characters Holds `be_manage_characters` here.
	 * @param bool   $manage_plots      Holds `be_manage_plots` here.
	 * @param bool   $manage_sessions   Holds `be_manage_sessions` here.
	 * @return array<string,mixed>
	 */
	public static function build( object $game, int $wp_user_id, bool $manage_characters, bool $manage_plots, bool $manage_sessions ): array {
		$queue = ( $manage_plots || $manage_characters ) ? self::queue( $game, $wp_user_id ) : null;

		return [
			'today'        => current_time( 'Y-m-d' ),
			'waiting'      => [
				'approvals' => $manage_characters ? self::approvals( $game ) : null,
				'downtime'  => $manage_plots && $queue !== null ? $queue['downtime'] : null,
				'plots'     => $manage_plots && $queue !== null ? $queue['plots'] : null,
				'players'   => $manage_characters ? self::players( $game ) : null,
				'castings'  => $queue['castings'] ?? self::castings( $game, $wp_user_id ),
			],
			'next_session' => ( $manage_sessions || $manage_plots ) ? self::next_session( $game ) : null,
			'spotlight'    => ( $manage_plots || $manage_characters ) ? self::spotlight( $game ) : null,
		];
	}

	/**
	 * What waits in the Approval Queue.
	 *
	 * @return array{changes:int,xp_requests:int,files:int,new_characters:int,with_coordinators:int}
	 */
	private static function approvals( object $game ): array {
		$slug = (string) $game->slug;
		return [
			'changes'        => Change::count_for_game( $slug, [ 'status' => 'pending' ] ),
			'xp_requests'    => Change::count_for_game( $slug, [ 'status' => 'pending', 'change_type' => 'xp_adjust' ] ),
			'files'          => Submission::count_waiting( (int) $game->id ),
			'new_characters' => self::new_characters_waiting( $game ),
			'with_coordinators' => Coordinator_Send::mode( $game ) === null ? 0 : Coordinator_Request::with_coordinator_count( (int) $game->id ),
		];
	}

	/**
	 * Pending player characters made by members of the chronicle; someone still asking to join is counted by their request.
	 */
	private static function new_characters_waiting( object $game ): int {
		return (int) Manager::get_var(
			'SELECT COUNT(*) FROM ' . Manager::table( 'characters' ) . ' c'
			. " WHERE c.owner_type = 'chronicle' AND c.owner_slug = %s AND c.status = 'pending' AND c.is_npc = 0"
			. ' AND c.wp_user_id IN ( SELECT m.wp_user_id FROM ' . Manager::table( 'game_members' ) . ' m WHERE m.game_id = %d )',
			(string) $game->slug,
			(int) $game->id
		);
	}

	/**
	 * Join requests and Discord links that wait for an answer.
	 *
	 * @return array{join_requests:int,links:int}
	 */
	private static function players( object $game ): array {
		return [
			'join_requests' => count( Join_Request::for_game( (int) $game->id, 'waiting' ) ),
			'links'         => count( Discord_Link::for_game( (int) $game->id, Discord_Link::CONFIRMED ) ),
		];
	}

	/**
	 * A person's castings on sessions today or later.
	 *
	 * @return array<int,array{character_name:string,game_date:string}>
	 */
	private static function castings( object $game, int $wp_user_id ): array {
		return array_map(
			static fn( $row ) => [ 'character_name' => (string) $row->character_name, 'game_date' => (string) $row->game_date ],
			Staff_Queue::castings( $wp_user_id, (int) $game->id )
		);
	}

	/**
	 * The person's own downtime and plot posts, what nobody has taken, and their castings; read through the queue,
	 * which walks every plot, and kept for a minute.
	 *
	 * @return array{downtime:array{mine:int,unassigned:int},plots:array{mine:int,unassigned:int},castings:array<int,array<string,string>>}
	 */
	private static function queue( object $game, int $wp_user_id ): array {
		$key    = sprintf( 'be_home_queue_%d_%d', (int) $game->id, $wp_user_id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$unassigned = Staff_Queue::unassigned( (int) $game->id );
		$queue      = [
			'downtime' => [ 'mine' => count( Staff_Queue::downtime( $wp_user_id, (int) $game->id ) ), 'unassigned' => $unassigned['downtime'] ],
			'plots'    => [ 'mine' => count( Staff_Queue::plots( $wp_user_id, (int) $game->id ) ), 'unassigned' => $unassigned['plots'] ],
			'castings' => self::castings( $game, $wp_user_id ),
		];
		set_transient( $key, $queue, self::CACHE_TTL );
		return $queue;
	}

	/**
	 * The next game night, how many characters have sent downtime for it, and how many who came to the last one have
	 * written their report.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function next_session( object $game ): ?array {
		$today    = current_time( 'Y-m-d' );
		$now      = current_time( 'mysql' );
		$sessions = Game_Session::with_open_dates( (int) $game->id, $today, $now );

		$next = null;
		$last = null;
		foreach ( $sessions as $session ) {
			if ( (string) $session->game_date >= $today ) {
				$next ??= $session;
			} elseif ( (string) $session->reports_due_at >= $now ) {
				$last = $session;
			}
		}
		if ( $next === null ) {
			return null;
		}

		return [
			'id'                   => (int) $next->id,
			'game_date'            => (string) $next->game_date,
			'start_time'           => $next->start_time,
			'place'                => $next->place,
			'downtime_deadline_at' => $next->downtime_deadline_at,
			'downtime'             => ( $next->downtime_opens_at !== null || $next->downtime_deadline_at !== null )
				? self::downtime_progress( $game, (string) $next->game_date )
				: null,
			'reports'              => $last !== null ? self::reports_progress( $last ) : null,
		];
	}

	/**
	 * How many of the chronicle's active player characters have sent actions for a night.
	 *
	 * @return array{sent:int,total:int}
	 */
	private static function downtime_progress( object $game, string $game_date ): array {
		$plots = Action_Allocator::plots_for_date( (int) $game->id, $game_date );
		$sent  = Plot_Entry::plot_ids_with_actions( array_map( static fn( $row ) => (int) $row->plot_id, $plots ) );
		return [
			'sent'  => count( $sent ),
			'total' => Character::count_for_game( (string) $game->slug, [ 'status' => 'active', 'is_npc' => 0 ] ),
		];
	}

	/**
	 * How many of those who came to a game night have written their report.
	 *
	 * @return array{game_date:string,written:int,attended:int,due_at:string}
	 */
	private static function reports_progress( object $session ): array {
		$attended = Attendance::character_ids_for_session( (int) $session->id );
		$written  = array_intersect( $attended, After_Game_Report::character_ids_for_session( (int) $session->id ) );
		return [
			'game_date' => (string) $session->game_date,
			'written'   => count( $written ),
			'attended'  => count( $attended ),
			'due_at'    => (string) $session->reports_due_at,
		];
	}

	/**
	 * How many characters have had no Storyteller post for the chronicle's spotlight days, and the first few names.
	 *
	 * @return array{flagged:int,days:int,names:string[]}
	 */
	private static function spotlight( object $game ): array {
		return Spotlight::summary( (int) $game->id, (string) $game->slug, $game->settings ? (array) $game->settings : [] );
	}
}
