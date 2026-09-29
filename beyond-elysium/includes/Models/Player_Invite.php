<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for player invites: an email a chronicle's Storyteller invited to play, open until the
 * account with that email signs in (accepted) or a Storyteller cancels it.
 */
class Player_Invite {

	/**
	 * An email as invites store and compare it: trimmed and lower case.
	 */
	public static function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * The chronicle's open invite for an email, creating one when there is none.
	 *
	 * @return int The invite's id, or 0 when it could not be written.
	 */
	public static function open( int $game_id, string $email, int $invited_by ): int {
		$existing = self::open_for_game_email( $game_id, $email );
		if ( $existing ) {
			return (int) $existing->id;
		}
		$id = Manager::insert( 'player_invites', [
			'game_id'    => $game_id,
			'email'      => self::normalize( $email ),
			'invited_by' => $invited_by,
			'invited_at' => current_time( 'mysql' ),
		] );
		return (int) $id;
	}

	/**
	 * One invite by id.
	 *
	 * @return object|null
	 */
	public static function find( int $id ) {
		return Manager::get_row( 'SELECT * FROM ' . Manager::table( 'player_invites' ) . ' WHERE id = %d', $id );
	}

	/**
	 * The chronicle's open invite for an email, if any.
	 *
	 * @return object|null
	 */
	public static function open_for_game_email( int $game_id, string $email ) {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'player_invites' ) . ' WHERE game_id = %d AND email = %s AND accepted_at IS NULL AND cancelled_at IS NULL ORDER BY id ASC LIMIT 1',
			$game_id,
			self::normalize( $email )
		);
	}

	/**
	 * Every open invite in one chronicle, oldest first.
	 *
	 * @return object[]
	 */
	public static function open_for_game( int $game_id ): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'player_invites' ) . ' WHERE game_id = %d AND accepted_at IS NULL AND cancelled_at IS NULL ORDER BY invited_at ASC, id ASC',
			$game_id
		);
	}

	/**
	 * Every open invite for an email on this site, in any chronicle.
	 *
	 * @return object[]
	 */
	public static function open_for_email( string $email ): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'player_invites' ) . ' WHERE email = %s AND accepted_at IS NULL AND cancelled_at IS NULL ORDER BY id ASC',
			self::normalize( $email )
		);
	}

	/**
	 * Marks an open invite accepted by an account.
	 */
	public static function accept( int $id, int $wp_user_id ): bool {
		return Manager::update(
			'player_invites',
			[ 'accepted_at' => current_time( 'mysql' ), 'accepted_by' => $wp_user_id ],
			[ 'id' => $id ]
		) !== false;
	}

	/**
	 * Marks an open invite cancelled.
	 */
	public static function cancel( int $id ): bool {
		return Manager::update( 'player_invites', [ 'cancelled_at' => current_time( 'mysql' ) ], [ 'id' => $id ] ) !== false;
	}
}
