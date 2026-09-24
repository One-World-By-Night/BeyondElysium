<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for scheduled release batches.
 */
class Release_Batch {

	/** @var string[] Valid stored `status` values, also the lifecycle order. */
	const STATUSES = [ 'draft', 'scheduled', 'released' ];

	/**
	 * Look up a single release batch by its primary key, or null when no row with that id exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'release_batches' ) . ' WHERE id = %d',
			$id
		);
	}

	/**
	 * Every release batch for a chronicle, newest created first, optionally narrowed to one status.
	 *
	 * @param int         $game_id
	 * @param string|null $status One of STATUSES, or null for every status.
	 * @return object[]
	 */
	public static function for_game( int $game_id, ?string $status = null ): array {
		$query = 'SELECT * FROM ' . Manager::table( 'release_batches' ) . ' WHERE game_id = %d';
		$args  = [ $game_id ];

		if ( $status !== null ) {
			$query .= ' AND status = %s';
			$args[] = $status;
		}
		$query .= ' ORDER BY created_at DESC';

		return Manager::get_results( $query, ...$args );
	}

	/**
	 * Creates a new release batch. game_id and name are required. status is derived.
	 *
	 * @param array $data
	 * @return int|false
	 */
	public static function create( array $data ) {
		if ( empty( $data['name'] ) ) {
			return false;
		}

		$release_at = ! empty( $data['release_at'] ) ? $data['release_at'] : null;

		return Manager::insert( 'release_batches', [
			'game_id'    => (int) $data['game_id'],
			'name'       => (string) $data['name'],
			'release_at' => $release_at,
			'status'     => $release_at !== null ? 'scheduled' : 'draft',
			'created_by' => $data['created_by'] ?? get_current_user_id(),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		] );
	}

	/**
	 * Updates a draft or scheduled batch's name, release_at, and/or status.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$batch = self::find( $id );
		if ( ! $batch || $batch->status === 'released' ) {
			return false;
		}

		$allowed = [ 'name', 'release_at', 'status' ];
		$update  = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}
		if ( empty( $update ) ) {
			return false;
		}

		$new_status = $update['status'] ?? $batch->status;
		if ( ! in_array( $new_status, [ 'draft', 'scheduled' ], true ) ) {
			return false;
		}

		$new_release_at = array_key_exists( 'release_at', $update ) ? $update['release_at'] : $batch->release_at;
		if ( $new_status === 'scheduled' && empty( $new_release_at ) ) {
			return false;
		}
		if ( $new_status === 'draft' && $batch->status === 'scheduled' && self::is_out_row( $batch, current_time( 'mysql' ) ) ) {
			return false;
		}

		$update['status']     = $new_status;
		$update['updated_at'] = current_time( 'mysql' );

		return (bool) Manager::update( 'release_batches', $update, [ 'id' => $id ] );
	}

	/**
	 * Look up a batch and lock its row until the surrounding transaction ends.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find_for_update( int $id ): ?object {
		return Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'release_batches' ) . ' WHERE id = %d FOR UPDATE',
			$id
		);
	}

	/**
	 * Marks a batch released.
	 *
	 * @param int    $id
	 * @param string $released_at `Y-m-d H:i:s`.
	 * @param string $notified_at `Y-m-d H:i:s`.
	 * @return bool
	 */
	public static function mark_released( int $id, string $released_at, string $notified_at ): bool {
		return (bool) Manager::update( 'release_batches', [
			'status'      => 'released',
			'released_at' => $released_at,
			'notified_at' => $notified_at,
			'updated_at'  => $notified_at,
		], [ 'id' => $id ] );
	}

	/**
	 * Deletes a draft or scheduled batch, returning its plots and entries to draft.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$batch = self::find( $id );
		if ( ! $batch || $batch->status === 'released' ) {
			return false;
		}

		$savepoint = Transaction::begin( 'be_release_batch_delete' );

		Manager::update( 'plots', [ 'release_batch_id' => null ], [ 'release_batch_id' => $id ] );
		Manager::update( 'plot_entries', [ 'release_batch_id' => null ], [ 'release_batch_id' => $id ] );
		Manager::update( 'secret_reveals', [ 'release_batch_id' => null ], [ 'release_batch_id' => $id ] );
		$result = Manager::delete( 'release_batches', [ 'id' => $id ] );

		if ( $result === false ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Transaction::commit( $savepoint );
		return true;
	}

	/**
	 * Every scheduled batch, across every chronicle, whose release_at has already passed.
	 *
	 * @return object[]
	 */
	public static function due(): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'release_batches' ) . " WHERE status = 'scheduled' AND release_at <= %s",
			current_time( 'mysql' )
		);
	}

	/**
	 * Whether a batch is "out".
	 *
	 * @param object $batch
	 * @param string $now `Y-m-d H:i:s`, the same format `current_time('mysql')` returns.
	 * @return bool
	 */
	public static function is_out_row( object $batch, string $now ): bool {
		if ( $batch->status === 'released' ) {
			return true;
		}
		return $batch->status === 'scheduled' && ! empty( $batch->release_at ) && $batch->release_at <= $now;
	}

	/**
	 * The ids of every batch in a game that is currently out, per is_out_row().
	 *
	 * @param int $game_id
	 * @return int[]
	 */
	public static function out_ids( int $game_id ): array {
		$now  = current_time( 'mysql' );
		$rows = Manager::get_results(
			'SELECT id, status, release_at FROM ' . Manager::table( 'release_batches' )
			. " WHERE game_id = %d AND status IN ('scheduled','released')",
			$game_id
		);

		$ids = [];
		foreach ( $rows as $row ) {
			if ( self::is_out_row( $row, $now ) ) {
				$ids[] = (int) $row->id;
			}
		}
		return $ids;
	}
}
