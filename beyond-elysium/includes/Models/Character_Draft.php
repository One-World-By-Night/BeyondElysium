<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * One person's unfinished new character in one chronicle, kept on their account so they can stop on one device and
 * finish on another. One draft per chronicle per person; it is removed when the character is created.
 */
class Character_Draft {

	/**
	 * The most bytes a stored draft holds.
	 */
	public const MAX_BYTES = 262144;

	/**
	 * The keys a draft keeps.
	 */
	public const KEYS = [ 'name', 'stack_slug', 'step', 'sheet_data', 'biography' ];

	/**
	 * The person's draft in a chronicle, decoded, with the time it was saved; null when there is none.
	 *
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @return array{draft:array<string,mixed>,updated_at:string}|null
	 */
	public static function find( int $game_id, int $wp_user_id ): ?array {
		$row = Manager::get_row(
			'SELECT draft, updated_at FROM ' . Manager::table( 'character_drafts' ) . ' WHERE game_id = %d AND wp_user_id = %d',
			$game_id,
			$wp_user_id
		);
		if ( ! $row ) {
			return null;
		}
		$decoded = json_decode( (string) $row->draft, true );
		return [
			'draft'      => is_array( $decoded ) ? $decoded : [],
			'updated_at' => (string) $row->updated_at,
		];
	}

	/**
	 * Keeps only the keys a draft holds.
	 *
	 * @param array<string,mixed> $draft
	 * @return array<string,mixed>
	 */
	public static function clean( array $draft ): array {
		return array_intersect_key( $draft, array_flip( self::KEYS ) );
	}

	/**
	 * Saves a draft, replacing the one there. The last save wins.
	 *
	 * @param int                 $game_id
	 * @param int                 $wp_user_id
	 * @param array<string,mixed> $draft
	 * @return bool
	 */
	public static function save( int $game_id, int $wp_user_id, array $draft ): bool {
		$json = wp_json_encode( self::clean( $draft ) );
		if ( ! is_string( $json ) ) {
			return false;
		}
		$now = current_time( 'mysql' );
		if ( self::find( $game_id, $wp_user_id ) !== null ) {
			return Manager::update(
				'character_drafts',
				[ 'draft' => $json, 'updated_at' => $now ],
				[ 'game_id' => $game_id, 'wp_user_id' => $wp_user_id ]
			) !== false;
		}
		return (bool) Manager::insert( 'character_drafts', [
			'game_id'    => $game_id,
			'wp_user_id' => $wp_user_id,
			'draft'      => $json,
			'updated_at' => $now,
		] );
	}

	/**
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @return bool
	 */
	public static function delete( int $game_id, int $wp_user_id ): bool {
		return Manager::delete( 'character_drafts', [ 'game_id' => $game_id, 'wp_user_id' => $wp_user_id ] ) !== false;
	}

	/**
	 * Every draft a person has, in every chronicle: removed when their account is.
	 *
	 * @param int $wp_user_id
	 * @return void
	 */
	public static function remove_user_everywhere( int $wp_user_id ): void {
		Manager::delete( 'character_drafts', [ 'wp_user_id' => $wp_user_id ] );
	}
}
