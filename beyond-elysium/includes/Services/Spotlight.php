<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\After_Game_Report;
use BeyondElysium\Models\Attendance;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;

defined( 'ABSPATH' ) || exit;

/**
 * The spotlight check - every active, non-NPC character's own attention profile.
 */
class Spotlight {

	/**
	 * Days with no staff post before a character is flagged, absent a chronicle setting.
	 */
	const DEFAULT_SPOTLIGHT_DAYS = 42;

	/**
	 * @param int    $game_id
	 * @param string $game_slug
	 * @param array  $settings The game's own `settings` array (for `sessions.spotlight_days`).
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_game( int $game_id, string $game_slug, array $settings ): array {
		$sessions_settings = (array) ( $settings['sessions'] ?? [] );
		$spotlight_days    = (int) ( $sessions_settings['spotlight_days'] ?? self::DEFAULT_SPOTLIGHT_DAYS );
		$staff_ids      = Game_Member::staff_ids_for_game( $game_id );
		$characters     = Character::all_for_game( $game_slug, [ 'status' => 'active', 'is_npc' => 0 ] );

		$rows = [];
		foreach ( $characters as $character ) {
			$character_id       = (int) $character->id;
			$last_staff_post_at = self::last_staff_post_at( $character_id, $staff_ids );

			$rows[] = [
				'character_id'       => $character_id,
				'name'               => $character->name,
				'last_attended'      => Attendance::last_attended_date( $character_id ),
				'active_plots'       => self::active_plot_count( $character_id ),
				'last_staff_post_at' => $last_staff_post_at,
				'last_report_at'     => After_Game_Report::last_report_date( $character_id ),
				'flagged'            => self::is_flagged( $last_staff_post_at, $spotlight_days ),
			];
		}

		usort( $rows, static function ( $a, $b ) {
			if ( $a['flagged'] !== $b['flagged'] ) {
				return $a['flagged'] ? -1 : 1;
			}
			// Least recent attention first - null (never) sorts before any real date.
			return strcmp( (string) ( $a['last_staff_post_at'] ?? '' ), (string) ( $b['last_staff_post_at'] ?? '' ) );
		} );

		return $rows;
	}

	/**
	 * How many characters on the flagged list would show right now.
	 *
	 * @param int    $game_id
	 * @param string $game_slug
	 * @param array  $settings
	 * @return int
	 */
	public static function flagged_count( int $game_id, string $game_slug, array $settings ): int {
		return count( array_filter( self::for_game( $game_id, $game_slug, $settings ), static fn( $row ) => $row['flagged'] ) );
	}

	/**
	 * True when a character has never had a staff post, or none within the spotlight window.
	 *
	 * @param string|null $last_staff_post_at
	 * @param int         $spotlight_days
	 * @return bool
	 */
	private static function is_flagged( ?string $last_staff_post_at, int $spotlight_days ): bool {
		if ( $last_staff_post_at === null ) {
			return true;
		}
		$cutoff = strtotime( current_time( 'mysql' ) ) - ( $spotlight_days * DAY_IN_SECONDS );
		return strtotime( $last_staff_post_at ) < $cutoff;
	}

	/**
	 * Active, non-action plots connected to a character.
	 *
	 * @param int $character_id
	 * @return int
	 */
	private static function active_plot_count( int $character_id ): int {
		$count = 0;
		foreach ( self::connected_plots( $character_id ) as $plot ) {
			if ( $plot->status === 'active' && empty( $plot->game_date ) && empty( $plot->is_own_permanent_plot ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * The newest non-note entry by a manager (hst/ast/narrator) on any plot connected to the character.
	 *
	 * @param int   $character_id
	 * @param int[] $staff_ids
	 * @return string|null
	 */
	private static function last_staff_post_at( int $character_id, array $staff_ids ): ?string {
		$latest = null;
		foreach ( self::connected_plots( $character_id ) as $plot ) {
			foreach ( Plot_Entry::for_plot( (int) $plot->id ) as $entry ) {
				if ( $entry->entry_type === 'note' ) {
					continue;
				}
				if ( ! in_array( (int) $entry->author_id, $staff_ids, true ) ) {
					continue;
				}
				if ( $latest === null || $entry->created_at > $latest ) {
					$latest = $entry->created_at;
				}
			}
		}
		return $latest;
	}

	/**
	 * Every plot connected to a character, regardless of connection label or direction.
	 *
	 * @param int $character_id
	 * @return object[]
	 */
	private static function connected_plots( int $character_id ): array {
		$plots = [];
		foreach ( Connection::for_entity( 'character', $character_id ) as $connection ) {
			$plot_id = null;
			if ( $connection->source_type === 'plot' ) {
				$plot_id = (int) $connection->source_id;
			} elseif ( $connection->target_type === 'plot' ) {
				$plot_id = (int) $connection->target_id;
			}
			if ( $plot_id === null ) {
				continue;
			}
			if ( ! isset( $plots[ $plot_id ] ) ) {
				$plot = Plot::find( $plot_id );
				if ( ! $plot ) {
					continue;
				}
				$plot->is_own_permanent_plot = false;
				$plots[ $plot_id ]           = $plot;
			}
			if ( $connection->label === 'apr_actor' && empty( $plots[ $plot_id ]->game_date ) ) {
				$plots[ $plot_id ]->is_own_permanent_plot = true;
			}
		}
		return array_values( $plots );
	}
}
