<?php

namespace BeyondElysium\Services;

use BeyondElysium\Core\Notifications;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Notice;

defined( 'ABSPATH' ) || exit;

/**
 * Tells a player, once, that downtime for a game night is about to close and a character of theirs has sent nothing.
 */
class Downtime_Reminders {

	/**
	 * How far ahead of a deadline a reminder goes out, in seconds.
	 */
	private const LEAD = 2 * DAY_IN_SECONDS;

	/**
	 * Reminds every player who is due one in every chronicle.
	 *
	 * @param string|null $now `Y-m-d H:i:s`, the site's own clock; the current time when omitted.
	 * @return int How many characters were reminded.
	 */
	public static function run( ?string $now = null ): int {
		$now   = $now ?? current_time( 'mysql' );
		$today = substr( $now, 0, 10 );
		$sent  = 0;

		foreach ( Game::all() as $game ) {
			$sent += self::for_game( $game, $today, $now );
		}
		return $sent;
	}

	/**
	 * @return int How many characters of one chronicle were reminded.
	 */
	private static function for_game( object $game, string $today, string $now ): int {
		$sessions = array_filter(
			Game_Session::with_open_dates( (int) $game->id, $today, $now ),
			static fn( $session ) => (string) $session->game_date >= $today
				&& ( $session->downtime_opens_at !== null || $session->downtime_deadline_at !== null )
		);
		if ( $sessions === [] ) {
			return 0;
		}

		$characters = array_filter(
			Character::all_for_game( (string) $game->slug, [ 'status' => 'active', 'is_npc' => 0 ] ),
			static fn( $character ) => (int) $character->wp_user_id > 0
		);

		$sent = 0;
		foreach ( $sessions as $session ) {
			foreach ( $characters as $character ) {
				$waiting = Downtime_Window::awaiting_actions( $session, $character, $now );
				if ( $waiting === null || $waiting['deadline'] === null ) {
					continue;
				}
				if ( strtotime( $waiting['deadline'] ) - strtotime( $now ) > self::LEAD ) {
					continue;
				}
				if ( self::already_reminded( $game, $character, $session ) ) {
					continue;
				}

				Notices::downtime_due( $game, $character, $session, $waiting['deadline'], $waiting['plot_id'] );
				Notifications::downtime_reminder( $game, $character, $session, $waiting['deadline'], $waiting['plot_id'] );
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Whether the player was already reminded about this character for this game night.
	 */
	private static function already_reminded( object $game, object $character, object $session ): bool {
		foreach ( Notice::of_kind( (int) $game->id, (int) $character->wp_user_id, 'downtime_due' ) as $notice ) {
			if ( (int) ( $notice->payload['session_id'] ?? 0 ) === (int) $session->id && (int) ( $notice->payload['character_id'] ?? 0 ) === (int) $character->id ) {
				return true;
			}
		}
		return false;
	}
}
