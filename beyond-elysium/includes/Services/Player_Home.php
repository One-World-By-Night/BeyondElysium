<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\After_Game_Report;
use BeyondElysium\Models\Attendance;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Notice;
use BeyondElysium\Models\Npc_Casting;

defined( 'ABSPATH' ) || exit;

/**
 * What a player's home in one chronicle shows: their characters, what is due, what is new and what is next.
 */
class Player_Home {

	/**
	 * How many unread notices and plots the home lists.
	 */
	private const LIST_LIMIT = 5;

	/**
	 * @param object $game
	 * @param int    $wp_user_id
	 * @param bool   $can_manage_plots Whether the person manages plots in this chronicle.
	 * @return array<string,mixed>
	 */
	public static function build( object $game, int $wp_user_id, bool $can_manage_plots ): array {
		$now    = current_time( 'mysql' );
		$today  = current_time( 'Y-m-d' );
		$mine   = array_values( array_filter(
			Character::find_for_user( $wp_user_id, (string) $game->slug ),
			static fn( $character ) => empty( $character->is_npc )
		) );
		$active = array_values( array_filter( $mine, static fn( $character ) => $character->status === 'active' ) );
		$due    = self::due( $game, $mine, $active, $today, $now );

		return [
			'today'        => $today,
			'characters'   => array_map( [ self::class, 'card' ], $mine ),
			'due'          => $due,
			'notices'      => self::notices( $game, $wp_user_id ),
			'next_session' => self::next_session( $game, $wp_user_id, $today ),
			'plots'        => array_map(
				static fn( $plot ) => [ 'id' => (int) $plot->id, 'title' => (string) $plot->title, 'updated_at' => (string) $plot->updated_at ],
				array_slice( My_Plots::for_user( $game, $wp_user_id, $can_manage_plots ), 0, self::LIST_LIMIT )
			),
		];
	}

	/**
	 * One character's card.
	 *
	 * @param object $character
	 * @return array<string,mixed>
	 */
	private static function card( object $character ): array {
		$id = (int) $character->id;
		return [
			'id'         => $id,
			'name'       => (string) $character->name,
			'stack_slug' => (string) $character->stack_slug,
			'status'     => (string) $character->status,
			'image_url'  => ! empty( $character->image_id ) ? wp_get_attachment_image_url( (int) $character->image_id, 'thumbnail' ) : null,
			'xp_unspent' => (int) ( $character->xp_unspent ?? 0 ),
			'xp_held'    => Change::held_xp( $id ),
			'waiting'    => Change::count_for_character( $id, [ 'status' => 'pending' ] ),
		];
	}

	/**
	 * What has a deadline: downtime that is open and has sent nothing, and reports still to write.
	 *
	 * @param object   $game
	 * @param object[] $mine   The person's player characters.
	 * @param object[] $active The ones that are active.
	 * @return array{downtime:array<int,array<string,mixed>>,reports:array<int,array<string,mixed>>}
	 */
	private static function due( object $game, array $mine, array $active, string $today, string $now ): array {
		$downtime = [];
		$reports  = [];

		foreach ( Game_Session::with_open_dates( (int) $game->id, $today, $now ) as $session ) {
			if ( (string) $session->game_date >= $today ) {
				foreach ( $active as $character ) {
					$waiting = Downtime_Window::awaiting_actions( $session, $character, $now );
					if ( $waiting === null ) {
						continue;
					}
					$downtime[] = [
						'character_id'   => (int) $character->id,
						'character_name' => (string) $character->name,
						'game_date'      => (string) $session->game_date,
						'deadline'       => $waiting['deadline'],
						'plot_id'        => $waiting['plot_id'],
					];
				}
			}

			$due_at = (string) $session->reports_due_at;
			if ( $due_at === '' || $due_at < $now || (string) $session->game_date > $today ) {
				continue;
			}
			$attended = Attendance::character_ids_for_session( (int) $session->id );
			foreach ( $mine as $character ) {
				if ( ! in_array( (int) $character->id, $attended, true ) || After_Game_Report::find_for_session( (int) $session->id, (int) $character->id ) !== null ) {
					continue;
				}
				$reports[] = [
					'character_id'   => (int) $character->id,
					'character_name' => (string) $character->name,
					'session_id'     => (int) $session->id,
					'game_date'      => (string) $session->game_date,
					'due_at'         => $due_at,
				];
			}
		}

		return [ 'downtime' => $downtime, 'reports' => $reports ];
	}

	/**
	 * The unread count and the newest unread notices.
	 *
	 * @return array{unread:int,items:object[]}
	 */
	private static function notices( object $game, int $wp_user_id ): array {
		$unread = array_values( array_filter(
			Notice::for_person( (int) $game->id, $wp_user_id, 30 ),
			static fn( $notice ) => $notice->read_at === null
		) );
		return [
			'unread' => Notice::unread_count( (int) $game->id, $wp_user_id ),
			'items'  => array_slice( $unread, 0, self::LIST_LIMIT ),
		];
	}

	/**
	 * The next game night with the person's own castings on it.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function next_session( object $game, int $wp_user_id, string $today ): ?array {
		$session = Game_Session::next_on_or_after( (int) $game->id, $today );
		if ( $session === null ) {
			return null;
		}
		$castings = array_values( array_filter(
			Npc_Casting::upcoming_for_user( $wp_user_id, (int) $game->id, $today ),
			static fn( $casting ) => (int) $casting->session_id === (int) $session->id
		) );

		return [
			'id'                   => (int) $session->id,
			'game_date'            => (string) $session->game_date,
			'start_time'           => $session->start_time,
			'place'                => $session->place,
			'downtime_opens_at'    => $session->downtime_opens_at,
			'downtime_deadline_at' => $session->downtime_deadline_at,
			'reports_due_at'       => $session->reports_due_at,
			'castings'             => array_map( static fn( $casting ) => (string) $casting->character_name, $castings ),
		];
	}
}
