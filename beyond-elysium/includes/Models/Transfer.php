<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * One leg of a character's journey between chronicles: an outbound row on the home side, an inbound row on the host
 * side.
 */
class Transfer {

	/**
	 * States that end a row's own open/closed lifecycle.
	 */
	private const TERMINAL_STATES = [ 'returned', 'released', 'declined', 'expired', 'sent_home', 'retained' ];

	/**
	 * How long a transfer's verification code, a pending transfer, and an unreviewed offer last: two monthly game cycles.
	 */
	const OFFER_TTL_DAYS = 60;

	/**
	 * Creates a new transfer row.
	 *
	 * @param array<string,mixed> $data
	 * @return int New row id.
	 * @throws \RuntimeException If an open row already exists for this uuid+direction, or the row could not be written.
	 */
	public static function create( array $data ): int {
		$uuid      = strtolower( (string) $data['character_uuid'] );
		$direction = (string) $data['direction'];
		$chronicle = (string) ( $direction === 'outbound' ? $data['home_slug'] : ( $data['host_slug'] ?? '' ) );

		$unit = Transaction::begin( 'be_transfer_create' );
		if ( ! Game::lock( $chronicle ) ) {
			Transaction::rollback( $unit );
			throw new \RuntimeException( 'Failed to create transfer row.' );
		}

		if ( self::find_open( $uuid, $direction ) !== null ) {
			Transaction::rollback( $unit );
			throw new \RuntimeException( "A {$direction} transfer is already open for this character." );
		}

		$state = (string) $data['state'];

		$id = Manager::insert( 'character_transfers', [
			'character_uuid'  => $uuid,
			'character_id'    => $data['character_id'] ?? null,
			'character_name'  => $data['character_name'] ?? null,
			'direction'       => $direction,
			'state'           => $state,
			'home_slug'       => (string) $data['home_slug'],
			'home_site'       => (string) $data['home_site'],
			'home_chronicle'  => (string) $data['home_chronicle'],
			'host_slug'       => $data['host_slug'] ?? null,
			'host_site'       => $data['host_site'] ?? null,
			'host_chronicle'  => $data['host_chronicle'] ?? null,
			'attestation_id'  => $data['attestation_id'] ?? null,
			'snapshot_id'     => $data['snapshot_id'] ?? null,
			'payload_hash'    => (string) $data['payload_hash'],
			'initiated_by'    => (int) $data['initiated_by'],
			'initiated_at'    => current_time( 'mysql', true ),
			'acknowledged_at' => in_array( $state, [ 'abroad', 'visiting' ], true ) ? current_time( 'mysql', true ) : null,
			'notes'           => $data['notes'] ?? null,
			'payload'         => $data['payload'] ?? null,
		] );

		if ( $id === false ) {
			Transaction::rollback( $unit );
			throw new \RuntimeException( 'Failed to create transfer row.' );
		}
		Transaction::commit( $unit );
		return $id;
	}

	/**
	 * The single open (non-terminal) row for a character in one direction, or null when the character isn't currently
	 * mid-journey that way.
	 *
	 * @param string $character_uuid
	 * @param string $direction 'outbound' | 'inbound'.
	 * @return object|null
	 * @phpstan-impure
	 */
	public static function find_open( string $character_uuid, string $direction ): ?object {
		$table     = Manager::table( 'character_transfers' );
		$in_clause = implode( ',', array_fill( 0, count( self::TERMINAL_STATES ), '%s' ) );

		return Manager::get_row(
			"SELECT * FROM {$table} WHERE character_uuid = %s AND direction = %s AND state NOT IN ({$in_clause}) ORDER BY id DESC LIMIT 1",
			strtolower( $character_uuid ),
			$direction,
			...self::TERMINAL_STATES
		);
	}

	/**
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		$table = Manager::table( 'character_transfers' );
		return Manager::get_row( "SELECT * FROM {$table} WHERE id = %d", $id );
	}

	/**
	 * Look up a transfer and lock its row until the surrounding transaction ends.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find_for_update( int $id ): ?object {
		$table = Manager::table( 'character_transfers' );
		return Manager::get_row( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $id );
	}

	/**
	 * Moves a transfer row to a new state, stamping the timestamp column that state transition implies (`acknowledged_at`
	 * on reaching `abroad`/`visiting`, `returned_at` on reaching `returned`).
	 *
	 * @param int                  $id
	 * @param string               $new_state
	 * @param array<string,mixed>  $extra Additional columns to set alongside state (e.g. host_slug/host_site/host_chronicle on first acknowledgement).
	 * @return bool
	 */
	public static function transition( int $id, string $new_state, array $extra = [] ): bool {
		$data = array_merge( $extra, [ 'state' => $new_state ] );

		if ( in_array( $new_state, [ 'abroad', 'visiting' ], true ) ) {
			$data['acknowledged_at'] = current_time( 'mysql', true );
		}
		if ( $new_state === 'returned' ) {
			$data['returned_at'] = current_time( 'mysql', true );
		}

		return Manager::update( 'character_transfers', $data, [ 'id' => $id ] ) !== false;
	}

	/**
	 * Expires what nobody acted on within `OFFER_TTL_DAYS`: a home chronicle's `pending` transfer, whose code is revoked
	 * with it, and a host's `offered` row, whose stored document is dropped.
	 *
	 * @return int Rows expired.
	 */
	public static function expire_stale(): int {
		global $wpdb;
		$table  = Manager::table( 'character_transfers' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::OFFER_TTL_DAYS * DAY_IN_SECONDS );
		$stale  = "( ( direction = 'outbound' AND state = 'pending' ) OR ( direction = 'inbound' AND state = 'offered' ) ) AND initiated_at < %s";

		$codes = $wpdb->get_col( $wpdb->prepare( "SELECT attestation_id FROM {$table} WHERE {$stale} AND direction = 'outbound' AND attestation_id IS NOT NULL", $cutoff ) );
		foreach ( $codes as $attestation_id ) {
			Attestation::revoke( (int) $attestation_id );
		}

		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = 'expired', payload = NULL WHERE {$stale}", $cutoff ) );
	}

	/**
	 * Every open transfer touching a given chronicle, from either side.
	 *
	 * @param string $game_slug
	 * @return array<string,array{direction:string,state:string,chronicle:string|null,since:string}>
	 */
	public static function open_states_for_game( string $game_slug ): array {
		$table     = Manager::table( 'character_transfers' );
		$in_clause = implode( ',', array_fill( 0, count( self::TERMINAL_STATES ), '%s' ) );

		$rows = Manager::get_results(
			"SELECT * FROM {$table}
			 WHERE state NOT IN ({$in_clause})
			 AND ( (direction = 'outbound' AND home_slug = %s) OR (direction = 'inbound' AND host_slug = %s) )
			 ORDER BY id DESC",
			...array_merge( self::TERMINAL_STATES, [ $game_slug, $game_slug ] )
		);

		$states = [];
		foreach ( $rows as $row ) {
			// Newest row per uuid+direction wins.
			$key = $row->character_uuid . ':' . $row->direction;
			if ( isset( $states[ $key ] ) ) {
				continue;
			}
			$states[ $key ] = [
				'character_uuid' => $row->character_uuid,
				'direction'      => $row->direction,
				'state'          => $row->state,
				// An outbound row's host_chronicle is genuinely null until a host confirms.
				'chronicle'      => $row->direction === 'outbound' ? $row->host_chronicle : $row->home_chronicle,
				'since'          => $row->initiated_at,
			];
		}

		$by_uuid = [];
		foreach ( $states as $entry ) {
			$by_uuid[ $entry['character_uuid'] ] = $entry;
		}
		return $by_uuid;
	}

	/**
	 * Every transfer row touching a chronicle from this site's side.
	 *
	 * @param string $game_slug
	 * @param int    $limit
	 * @return array<int,object>
	 */
	public static function for_game( string $game_slug, int $limit = 200 ): array {
		$table = Manager::table( 'character_transfers' );
		return Manager::get_results(
			"SELECT id, character_uuid, character_id, character_name, direction, state, home_slug, home_site, home_chronicle,
			        host_slug, host_site, host_chronicle, attestation_id, snapshot_id, payload_hash, initiated_by,
			        initiated_at, acknowledged_at, returned_at, notes
			 FROM {$table}
			 WHERE ( direction = 'outbound' AND home_slug = %s ) OR ( direction = 'inbound' AND host_slug = %s )
			 ORDER BY id DESC LIMIT %d",
			$game_slug,
			$game_slug,
			$limit
		);
	}

	/**
	 * Every transfer row (open or terminal) for a character, newest first.
	 *
	 * @param string $character_uuid
	 * @return array<int,object>
	 */
	public static function history_for_character( string $character_uuid ): array {
		$table = Manager::table( 'character_transfers' );
		return Manager::get_results(
			"SELECT * FROM {$table} WHERE character_uuid = %s ORDER BY id DESC",
			strtolower( $character_uuid )
		);
	}
}
