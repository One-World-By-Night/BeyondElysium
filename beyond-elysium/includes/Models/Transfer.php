<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * One leg of a character's journey between chronicles (GX-8/9): an outbound
 * row on the home side, an inbound row on the host side. Not `be_connections`
 * - a transfer is a fact about one character's relationship to two
 * chronicles, potentially on two different WordPress installations, not a
 * relationship between two records on this one install
 * (`gex-export-transfer-design.md` §2h/§7.2).
 *
 * At most one OPEN row may exist per `(character_uuid, direction)` -
 * enforced here in `create()`, not by a unique index, since a composite
 * unique index would fight `transition()`'s own in-place state changes.
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-8, GX-9, §7.2, §8.1
 */
class Transfer {

	/** States that end a row's own open/closed lifecycle - the rest are open. */
	private const TERMINAL_STATES = [ 'returned', 'released', 'declined', 'expired', 'sent_home', 'retained' ];

	/**
	 * Creates a new transfer row. Refuses when an open row already exists
	 * for this exact `(character_uuid, direction)` pair - a character can
	 * only be mid-journey once per direction at a time.
	 *
	 * @param array<string,mixed> $data
	 * @return int New row id.
	 * @throws \RuntimeException If an open row already exists for this uuid+direction.
	 */
	public static function create( array $data ): int {
		$uuid      = strtolower( (string) $data['character_uuid'] );
		$direction = (string) $data['direction'];

		if ( self::find_open( $uuid, $direction ) !== null ) {
			throw new \RuntimeException( "A {$direction} transfer is already open for this character." );
		}

		$id = Manager::insert( 'character_transfers', [
			'character_uuid'  => $uuid,
			'character_id'    => $data['character_id'] ?? null,
			'direction'       => $direction,
			'state'           => (string) $data['state'],
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
			'notes'           => $data['notes'] ?? null,
		] );

		if ( $id === false ) {
			throw new \RuntimeException( 'Failed to create transfer row.' );
		}
		return $id;
	}

	/**
	 * The single open (non-terminal) row for a character in one direction,
	 * or null when the character isn't currently mid-journey that way.
	 *
	 * @param string $character_uuid
	 * @param string $direction 'outbound' | 'inbound'.
	 * @return object|null
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
	 * Moves a transfer row to a new state, stamping the timestamp column
	 * that state transition implies (`acknowledged_at` on reaching
	 * `abroad`/`visiting`, `returned_at` on reaching `returned`). Does not
	 * itself validate that the transition is a legal one from the row's
	 * current state - the REST controller enforces which actions are legal
	 * from which state, matching this codebase's own thin-model convention
	 * (business rules in the engine/controller layer, not the model).
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
	 * Every open transfer touching a given chronicle, from either side -
	 * outbound rows where it's the home, inbound rows where it's the host -
	 * keyed by character uuid, for merging a travelling/visiting badge onto
	 * a character list in one extra query (§7.3) rather than an N+1.
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
			// Newest row per uuid+direction wins; a uuid could in principle have both an
			// open outbound AND an open inbound row (rare - e.g. this chronicle sent
			// character A away while also currently hosting visiting character A from
			// somewhere else, which would require A to somehow be in two places, so this
			// is really only reachable as leftover test/bad-data state) - direction-keyed
			// so a single character never silently overwrites two distinct real states.
			$key = $row->character_uuid . ':' . $row->direction;
			if ( isset( $states[ $key ] ) ) {
				continue;
			}
			$states[ $key ] = [
				'character_uuid' => $row->character_uuid,
				'direction'      => $row->direction,
				'state'          => $row->state,
				// An outbound row's host_chronicle is genuinely null until a host confirms -
				// left null rather than defaulting here, since this is a model, not a
				// presentation layer; callers decide how to word an unconfirmed host.
				'chronicle'      => $row->direction === 'outbound' ? $row->host_chronicle : $row->home_chronicle,
				'since'          => $row->initiated_at,
			];
		}

		// Re-keyed by uuid alone for the common case (a caller merging onto a character
		// list has one row per character and just wants "is this one travelling").
		$by_uuid = [];
		foreach ( $states as $entry ) {
			$by_uuid[ $entry['character_uuid'] ] = $entry;
		}
		return $by_uuid;
	}

	/**
	 * Every transfer row (open or terminal) for a character, newest first -
	 * a travel history, not required by any GX-8/9 work-order item but a
	 * natural, cheap read given the table already carries the full history.
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
