<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Plot;

defined( 'ABSPATH' ) || exit;

/**
 * The plots and rumors a person is connected to or reached by in one chronicle, newest update first.
 */
class My_Plots {

	/**
	 * Every plot connected to one of the person's characters, plus every plot whose target query reaches one of them,
	 * that the person may see.
	 *
	 * @param object $game
	 * @param int    $wp_user_id
	 * @param bool   $can_manage Whether the person manages plots in this chronicle.
	 * @return object[]
	 */
	public static function for_user( object $game, int $wp_user_id, bool $can_manage ): array {
		$by_id = [];
		foreach ( Plot::for_user( (int) $game->id, $wp_user_id ) as $plot ) {
			$by_id[ $plot->id ] = $plot;
		}

		// Resolves target_query candidates against the user's own characters only.
		$character_ids = array_map(
			static fn( $c ) => (int) $c->id,
			Character::find_for_user( $wp_user_id, (string) $game->slug )
		);
		if ( ! empty( $character_ids ) ) {
			foreach ( Plot::for_target_query_candidates( (int) $game->id ) as $plot ) {
				if ( isset( $by_id[ $plot->id ] ) ) {
					continue;
				}
				$recipients = Query_Engine::resolve_target_query( (string) $game->slug, $plot->target_query );
				if ( array_intersect( $character_ids, $recipients ) ) {
					$by_id[ $plot->id ] = $plot;
				}
			}
		}

		$plots = array_values( $by_id );
		// A plot reachable through a connection or a target_query match is not automatically visible.
		$plots = Audience::filter( $plots, 'plot', $wp_user_id, (string) $game->slug, $can_manage );
		usort( $plots, static fn( $a, $b ) => strcmp( $b->updated_at, $a->updated_at ) );

		return $plots;
	}
}
