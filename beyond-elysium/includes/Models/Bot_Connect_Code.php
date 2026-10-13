<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * The one-time code an HST generates to connect a chronicle's Discord server. One row per game, replaced by each new
 * code.
 */
class Bot_Connect_Code {

	/**
	 * How long a code lasts.
	 */
	public const TTL_SECONDS = 15 * MINUTE_IN_SECONDS;

	/**
	 * Failures against a game's live code before it is burned.
	 */
	public const MAX_FAILURES = 10;

	/**
	 * Characters a code is drawn from: no 0/O, 1/I/L.
	 */
	public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	/**
	 * Makes a fresh 12-character code for a game, replacing any code already there.
	 *
	 * @param int $game_id
	 * @param int $created_by
	 * @return string|null The plain code, shown once. Null on failure.
	 */
	public static function generate( int $game_id, int $created_by ): ?string {
		$code = self::random_code();
		$hash = hash( 'sha256', $code );

		$existing = Manager::get_row(
			'SELECT id FROM ' . Manager::table( 'bot_connect_codes' ) . ' WHERE game_id = %d',
			$game_id
		);

		$data = [
			'code_hash'  => $hash,
			'created_by' => $created_by,
			'created_at' => current_time( 'mysql' ),
			'expires_at' => wp_date( 'Y-m-d H:i:s', time() + self::TTL_SECONDS ),
			'used_at'    => null,
			'failures'   => 0,
		];

		if ( $existing ) {
			$updated = Manager::update( 'bot_connect_codes', $data, [ 'id' => (int) $existing->id ] );
			return $updated !== false ? $code : null;
		}

		$id = Manager::insert( 'bot_connect_codes', $data + [ 'game_id' => $game_id ] );
		return $id !== false ? $code : null;
	}

	/**
	 * Normalizes a typed code the same way on generation's own display and on a connect attempt: upper case, with
	 * spaces and dashes dropped.
	 *
	 * @param string $code
	 * @return string
	 */
	public static function normalize( string $code ): string {
		return strtoupper( preg_replace( '/[\s\-]+/', '', $code ) ?? '' );
	}

	/**
	 * Resolves a submitted code to the game it belongs to and who generated it, consuming it on success.
	 *
	 * @param string $code
	 * @return array{game_id:int,created_by:int}|null Null when the code is wrong, used, expired, or burned.
	 */
	public static function consume( string $code ): ?array {
		$hash = hash( 'sha256', self::normalize( $code ) );

		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'bot_connect_codes' ) . ' WHERE code_hash = %s',
			$hash
		);

		if ( ! $row || $row->used_at !== null || (int) $row->failures >= self::MAX_FAILURES
			|| strtotime( (string) $row->expires_at ) < time()
		) {
			if ( $row ) {
				self::record_failure_by_game_id( (int) $row->game_id );
			}
			return null;
		}

		Manager::update( 'bot_connect_codes', [ 'used_at' => current_time( 'mysql' ) ], [ 'id' => (int) $row->id ] );
		return [ 'game_id' => (int) $row->game_id, 'created_by' => (int) $row->created_by ];
	}

	/**
	 * Records a failed attempt against whichever code is currently live for a game, regardless of what was typed.
	 *
	 * @param int $game_id
	 * @return void
	 */
	public static function record_failure( int $game_id ): void {
		self::record_failure_by_game_id( $game_id );
	}

	/**
	 * @param int $game_id
	 * @return void
	 */
	private static function record_failure_by_game_id( int $game_id ): void {
		global $wpdb;
		$table = Manager::table( 'bot_connect_codes' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET failures = failures + 1 WHERE game_id = %d", $game_id ) );
	}

	/**
	 * Deletes every code expired more than a day ago.
	 *
	 * @return void
	 */
	public static function prune(): void {
		global $wpdb;
		$table = Manager::table( 'bot_connect_codes' );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE expires_at < %s",
			wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		) );
	}

	/**
	 * @return string
	 */
	private static function random_code(): string {
		$alphabet = self::ALPHABET;
		$length   = strlen( $alphabet );
		$code     = '';
		for ( $i = 0; $i < 12; $i++ ) {
			$code .= $alphabet[ random_int( 0, $length - 1 ) ];
		}
		return $code;
	}
}
