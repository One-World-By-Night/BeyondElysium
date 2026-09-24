<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for one character learning one secret.
 */
class Secret_Reveal {

	/** @var string[] How a character came to learn a secret. */
	const HOW_VALUES = [ 'game', 'downtime', 'rumor', 'other' ];

	/**
	 * Look up a single reveal by its primary key, or null when no row with that id exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'secret_reveals' ) . ' WHERE id = %d',
			$id
		);
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Every reveal of one secret, oldest first.
	 *
	 * @param int $secret_id
	 * @return object[]
	 */
	public static function for_secret( int $secret_id ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'secret_reveals' ) . ' WHERE secret_id = %d ORDER BY created_at ASC',
			$secret_id
		);
		return array_map( static fn( $row ): object => self::decode( $row ), $rows );
	}

	/**
	 * Every reveal for one release batch.
	 *
	 * @param int $release_batch_id
	 * @return object[]
	 */
	public static function for_release_batch( int $release_batch_id ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'secret_reveals' ) . ' WHERE release_batch_id = %d ORDER BY created_at DESC',
			$release_batch_id
		);
		return array_map( static fn( $row ): object => self::decode( $row ), $rows );
	}

	/**
	 * Every reveal naming one character, across every secret.
	 *
	 * @param int $character_id
	 * @return object[]
	 */
	public static function for_character( int $character_id ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'secret_reveals' ) . ' WHERE character_id = %d ORDER BY created_at ASC',
			$character_id
		);
		return array_map( static fn( $row ): object => self::decode( $row ), $rows );
	}

	/**
	 * Casts `held` to a real boolean.
	 *
	 * @param object $row
	 * @return object
	 */
	private static function decode( object $row ): object {
		if ( property_exists( $row, 'held' ) ) {
			$row->held = (bool) $row->held;
		}
		return $row;
	}

	/**
	 * Whether a character has already been revealed a secret.
	 *
	 * @param int $secret_id
	 * @param int $character_id
	 * @return bool
	 */
	public static function already_revealed( int $secret_id, int $character_id ): bool {
		return (bool) Manager::get_var(
			'SELECT id FROM ' . Manager::table( 'secret_reveals' ) . ' WHERE secret_id = %d AND character_id = %d',
			$secret_id,
			$character_id
		);
	}

	/**
	 * Creates a reveal.
	 *
	 * @param array $data
	 * @return int|false
	 */
	public static function create( array $data ) {
		if ( empty( $data['secret_id'] ) || empty( $data['character_id'] ) ) {
			return false;
		}
		$how = $data['how'] ?? 'game';
		if ( ! in_array( $how, self::HOW_VALUES, true ) ) {
			return false;
		}

		return Manager::insert( 'secret_reveals', [
			'secret_id'         => (int) $data['secret_id'],
			'character_id'      => (int) $data['character_id'],
			'how'               => $how,
			'note'              => $data['note'] ?? null,
			'held'              => ! empty( $data['held'] ) ? 1 : 0,
			'release_batch_id'  => ! empty( $data['release_batch_id'] ) ? (int) $data['release_batch_id'] : null,
			'revealed_by'       => $data['revealed_by'] ?? get_current_user_id(),
			'created_at'        => current_time( 'mysql' ),
		] );
	}

	/**
	 * Updates a reveal's release_batch_id and/or held flag.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$update = [];
		if ( array_key_exists( 'held', $data ) ) {
			$update['held'] = $data['held'] ? 1 : 0;
		}
		if ( array_key_exists( 'release_batch_id', $data ) ) {
			$update['release_batch_id'] = ! empty( $data['release_batch_id'] ) ? (int) $data['release_batch_id'] : null;
		}

		if ( empty( $update ) ) {
			return true;
		}
		return Manager::update( 'secret_reveals', $update, [ 'id' => $id ] ) !== false;
	}

	/**
	 * Deletes one reveal by id.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		return (bool) Manager::delete( 'secret_reveals', [ 'id' => $id ] );
	}

	/**
	 * Deletes every reveal of one secret.
	 *
	 * @param int $secret_id
	 */
	public static function delete_for_secret( int $secret_id ): void {
		Manager::delete( 'secret_reveals', [ 'secret_id' => $secret_id ] );
	}

	/**
	 * The character ids who have actually learned a secret right now.
	 *
	 * @param int   $secret_id
	 * @param int[] $out_batch_ids The game's currently-out release batch ids.
	 * @return int[]
	 */
	public static function visible_character_ids( int $secret_id, array $out_batch_ids ): array {
		$ids = [];
		foreach ( self::for_secret( $secret_id ) as $reveal ) {
			$character_id = (int) $reveal->character_id;
			if ( empty( $reveal->held ) ) {
				$ids[] = $character_id;
				continue;
			}
			$release_batch_id = ! empty( $reveal->release_batch_id ) ? (int) $reveal->release_batch_id : null;
			if ( $release_batch_id !== null && in_array( $release_batch_id, $out_batch_ids, true ) ) {
				$ids[] = $character_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Whether one reveal has actually taken effect yet.
	 *
	 * @param object $reveal
	 * @param int[]  $out_batch_ids
	 * @return bool
	 */
	public static function is_effective( object $reveal, array $out_batch_ids ): bool {
		if ( empty( $reveal->held ) ) {
			return true;
		}
		$release_batch_id = ! empty( $reveal->release_batch_id ) ? (int) $reveal->release_batch_id : null;
		return $release_batch_id !== null && in_array( $release_batch_id, $out_batch_ids, true );
	}
}
