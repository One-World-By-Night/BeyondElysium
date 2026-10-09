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
	 * States that end a row's own open/closed lifecycle: `ended` (the visit is over, the character came home or was
	 * sent home), `declined`/`refused` (home's own cancel / the host's own turn-down), `expired` (nobody answered in
	 * time, either side), `released`/`retained` (the sheet of record moved for good).
	 */
	private const TERMINAL_STATES = [ 'ended', 'declined', 'refused', 'expired', 'released', 'retained' ];

	/**
	 * How long a transfer's verification code, a pending transfer, and an unreviewed offer last: two monthly game cycles.
	 */
	const OFFER_TTL_DAYS = 60;

	/**
	 * How long after a visit ends the host may still share a plot entry back to its home.
	 */
	const SHARE_OFFER_GRACE_DAYS = 30;

	/**
	 * Creates a new transfer row. A character can have one open visit per host; a row naming no host yet (an export
	 * with no destination chosen) is limited to one at a time.
	 *
	 * @param array<string,mixed> $data
	 * @return int New row id.
	 * @throws \RuntimeException If an open row already exists for this uuid+direction(+host), or the row could not be written.
	 */
	public static function create( array $data ): int {
		$uuid       = strtolower( (string) $data['character_uuid'] );
		$direction  = (string) $data['direction'];
		$chronicle  = (string) ( $direction === 'outbound' ? $data['home_slug'] : ( $data['host_slug'] ?? '' ) );
		$host_site  = (string) ( $data['host_site'] ?? '' );
		$host_slug  = (string) ( $data['host_slug'] ?? '' );
		$has_host   = $host_site !== '' && $host_slug !== '';

		$unit = Transaction::begin( 'be_transfer_create' );
		if ( ! Game::lock( $chronicle ) ) {
			Transaction::rollback( $unit );
			throw new \RuntimeException( 'Failed to create transfer row.' );
		}

		$already_open = $has_host ? self::find_open_visit( $uuid, $host_site, $host_slug, $direction ) : self::find_open( $uuid, $direction );
		if ( $already_open !== null ) {
			Transaction::rollback( $unit );
			throw new \RuntimeException( "A {$direction} transfer is already open for this character." );
		}

		$state = (string) $data['state'];

		$id = Manager::insert( 'character_transfers', [
			'character_uuid'  => $uuid,
			'peer_uuid'       => ! empty( $data['peer_uuid'] ) ? strtolower( (string) $data['peer_uuid'] ) : null,
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
			'acknowledged_at' => $state === 'visiting' ? current_time( 'mysql', true ) : null,
			'keep_current'          => ! empty( $data['keep_current'] ) ? 1 : 0,
			'keep_current_accepted' => ! empty( $data['keep_current_accepted'] ) ? 1 : 0,
			'notes'                 => $data['notes'] ?? null,
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

		return self::decode( Manager::get_row(
			"SELECT * FROM {$table} WHERE character_uuid = %s AND direction = %s AND state NOT IN ({$in_clause}) ORDER BY id DESC LIMIT 1",
			strtolower( $character_uuid ),
			$direction,
			...self::TERMINAL_STATES
		) );
	}

	/**
	 * The single open (non-terminal) row for a character at one host: a character can have any number of open visits
	 * but never two to the same host. `$direction` defaults to matching either; pass it to scope the check to one
	 * side.
	 *
	 * @param string      $character_uuid
	 * @param string      $host_site
	 * @param string      $host_slug
	 * @param string|null $direction 'outbound' | 'inbound' | null for either.
	 * @return object|null
	 * @phpstan-impure
	 */
	public static function find_open_visit( string $character_uuid, string $host_site, string $host_slug, ?string $direction = null ): ?object {
		$table     = Manager::table( 'character_transfers' );
		$in_clause = implode( ',', array_fill( 0, count( self::TERMINAL_STATES ), '%s' ) );
		$direction_clause = $direction !== null ? 'AND direction = %s' : '';

		$params = [ strtolower( $character_uuid ), $host_site, $host_slug ];
		if ( $direction !== null ) {
			$params[] = $direction;
		}
		$params = array_merge( $params, self::TERMINAL_STATES );

		return self::decode( Manager::get_row(
			"SELECT * FROM {$table} WHERE character_uuid = %s AND host_site = %s AND host_slug = %s {$direction_clause} AND state NOT IN ({$in_clause}) ORDER BY id DESC LIMIT 1",
			...$params
		) );
	}

	/**
	 * The single open (non-terminal) inbound row at one host, named by its claimed home rather than its host - what
	 * a host's own `from-home` route needs to locate the visit a caller claiming to be its home is calling about.
	 *
	 * @param string $character_uuid
	 * @param string $home_site
	 * @param string $home_slug
	 * @param string $host_slug This chronicle's own slug.
	 * @return object|null
	 * @phpstan-impure
	 */
	public static function find_open_visit_from_home( string $character_uuid, string $home_site, string $home_slug, string $host_slug ): ?object {
		$table     = Manager::table( 'character_transfers' );
		$in_clause = implode( ',', array_fill( 0, count( self::TERMINAL_STATES ), '%s' ) );

		return self::decode( Manager::get_row(
			"SELECT * FROM {$table} WHERE character_uuid = %s AND direction = 'inbound' AND home_site = %s AND home_slug = %s AND host_slug = %s AND state NOT IN ({$in_clause}) ORDER BY id DESC LIMIT 1",
			strtolower( $character_uuid ),
			$home_site,
			$home_slug,
			$host_slug,
			...self::TERMINAL_STATES
		) );
	}

	/**
	 * Every open (non-terminal) row for a character, either direction - used to close out every visit at once
	 * (e.g. when the character itself is deleted), never to pick one.
	 *
	 * @param string $character_uuid
	 * @return array<int,object>
	 */
	public static function open_visits_for_character( string $character_uuid ): array {
		$table     = Manager::table( 'character_transfers' );
		$in_clause = implode( ',', array_fill( 0, count( self::TERMINAL_STATES ), '%s' ) );

		return array_map( [ self::class, 'decode_row' ], Manager::get_results(
			"SELECT * FROM {$table} WHERE character_uuid = %s AND state NOT IN ({$in_clause}) ORDER BY id DESC",
			strtolower( $character_uuid ),
			...self::TERMINAL_STATES
		) );
	}

	/**
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		$table = Manager::table( 'character_transfers' );
		return self::decode( Manager::get_row( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Look up a transfer and lock its row until the surrounding transaction ends.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find_for_update( int $id ): ?object {
		$table = Manager::table( 'character_transfers' );
		return self::decode( Manager::get_row( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $id ) );
	}

	/**
	 * Moves a transfer row to a new state, stamping the timestamp column that state transition implies
	 * (`acknowledged_at` on reaching `visiting`, `returned_at` on reaching `ended`).
	 *
	 * @param int                  $id
	 * @param string               $new_state
	 * @param array<string,mixed>  $extra Additional columns to set alongside state (e.g. host_slug/host_site/host_chronicle on first acknowledgement).
	 * @return bool
	 */
	public static function transition( int $id, string $new_state, array $extra = [] ): bool {
		$data = array_merge( $extra, [ 'state' => $new_state ] );

		if ( $new_state === 'visiting' ) {
			$data['acknowledged_at'] = current_time( 'mysql', true );
		}
		if ( $new_state === 'ended' ) {
			$data['returned_at'] = current_time( 'mysql', true );
		}

		return Manager::update( 'character_transfers', $data, [ 'id' => $id ] ) !== false;
	}

	/**
	 * Expires what nobody acted on within `OFFER_TTL_DAYS`: an unanswered `offered` row, either direction, whose
	 * outbound code (if any) is revoked with it.
	 *
	 * @return int Rows expired.
	 */
	public static function expire_stale(): int {
		global $wpdb;
		$table  = Manager::table( 'character_transfers' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::OFFER_TTL_DAYS * DAY_IN_SECONDS );
		$stale  = "state = 'offered' AND initiated_at < %s";

		$codes = $wpdb->get_col( $wpdb->prepare( "SELECT attestation_id FROM {$table} WHERE {$stale} AND direction = 'outbound' AND attestation_id IS NOT NULL", $cutoff ) );
		foreach ( $codes as $attestation_id ) {
			Attestation::revoke( (int) $attestation_id );
		}

		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = 'expired', payload = NULL WHERE {$stale}", $cutoff ) );
	}

	/**
	 * Every open visit touching a given chronicle, from either side - one per host per character, so a character can
	 * show as active at several chronicles at once ("Also active at Boston, BBF").
	 *
	 * @param string $game_slug
	 * @return array<string,array<int,array{direction:string,state:string,chronicle:string|null,host_site:?string,host_slug:?string,since:string,keep_current:bool,delivered_at:?string,unreachable_since:?string}>>
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

		$by_uuid = [];
		foreach ( $rows as $row ) {
			$by_uuid[ $row->character_uuid ][] = [
				'character_uuid'    => $row->character_uuid,
				'direction'         => $row->direction,
				'state'             => $row->state,
				// An outbound row's host_chronicle is genuinely null until a host confirms.
				'chronicle'         => $row->direction === 'outbound' ? $row->host_chronicle : $row->home_chronicle,
				'host_site'         => $row->host_site,
				'host_slug'         => $row->host_slug,
				'since'             => $row->initiated_at,
				'keep_current'      => (bool) (int) $row->keep_current && (bool) (int) $row->keep_current_accepted,
				'delivered_at'      => $row->delivered_at,
				'unreachable_since' => $row->unreachable_since,
			];
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
		return array_map( [ self::class, 'decode_row' ], Manager::get_results(
			"SELECT id, character_uuid, character_id, character_name, direction, state, home_slug, home_site, home_chronicle,
			        host_slug, host_site, host_chronicle, attestation_id, snapshot_id, payload_hash, initiated_by,
			        initiated_at, acknowledged_at, returned_at, keep_current, keep_current_accepted, sequence,
			        delivered_at, unreachable_since, update_log, notes
			 FROM {$table}
			 WHERE ( direction = 'outbound' AND home_slug = %s ) OR ( direction = 'inbound' AND host_slug = %s )
			 ORDER BY id DESC LIMIT %d",
			$game_slug,
			$game_slug,
			$limit
		) );
	}

	/**
	 * Every transfer row (open or terminal) for a character, newest first.
	 *
	 * @param string $character_uuid
	 * @return array<int,object>
	 */
	public static function history_for_character( string $character_uuid ): array {
		$table = Manager::table( 'character_transfers' );
		return array_map( [ self::class, 'decode_row' ], Manager::get_results(
			"SELECT * FROM {$table} WHERE character_uuid = %s ORDER BY id DESC",
			strtolower( $character_uuid )
		) );
	}

	/**
	 * Whether a row's own state is still open (not one of the terminal ones).
	 *
	 * @param object $row
	 * @return bool
	 */
	public static function is_open( object $row ): bool {
		return ! in_array( $row->state, self::TERMINAL_STATES, true );
	}

	/**
	 * Every open outbound visit of a character that both sides have agreed to keep current.
	 *
	 * @param int $character_id
	 * @return array<int,object>
	 */
	public static function kept_current_outbound_visits( int $character_id ): array {
		$table     = Manager::table( 'character_transfers' );
		$in_clause = implode( ',', array_fill( 0, count( self::TERMINAL_STATES ), '%s' ) );

		return array_map( [ self::class, 'decode_row' ], Manager::get_results(
			"SELECT * FROM {$table} WHERE character_id = %d AND direction = 'outbound' AND keep_current = 1 AND keep_current_accepted = 1 AND state NOT IN ({$in_clause})",
			$character_id,
			...self::TERMINAL_STATES
		) );
	}

	/**
	 * The open inbound visit for a character, when both sides have agreed to keep it current - what a host's own
	 * change/XP-award path checks before applying anything, so it can forward instead.
	 *
	 * @param int $character_id
	 * @return object|null
	 * @phpstan-impure
	 */
	public static function open_inbound_kept_current_visit( int $character_id ): ?object {
		$table     = Manager::table( 'character_transfers' );
		$in_clause = implode( ',', array_fill( 0, count( self::TERMINAL_STATES ), '%s' ) );

		return self::decode( Manager::get_row(
			"SELECT * FROM {$table} WHERE character_id = %d AND direction = 'inbound' AND keep_current = 1 AND keep_current_accepted = 1 AND state NOT IN ({$in_clause}) ORDER BY id DESC LIMIT 1",
			$character_id,
			...self::TERMINAL_STATES
		) );
	}

	/**
	 * The inbound visit a host's plot-entry share offer binds to: open, or ended within the grace window - any state,
	 * kept current or not. Null when the character is not a real visitor at all (a hand-carried copy has no row
	 * here).
	 *
	 * @param int $character_id
	 * @return object|null
	 * @phpstan-impure
	 */
	public static function find_shareable_visit_for_character( int $character_id ): ?object {
		$table  = Manager::table( 'character_transfers' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::SHARE_OFFER_GRACE_DAYS * DAY_IN_SECONDS );

		return self::decode( Manager::get_row(
			"SELECT * FROM {$table} WHERE character_id = %d AND direction = 'inbound'
			 AND state NOT IN ('declined','refused','expired','released','retained')
			 AND ( state != 'ended' OR returned_at >= %s )
			 ORDER BY id DESC LIMIT 1",
			$character_id,
			$cutoff
		) );
	}

	/**
	 * Every kept-current outbound visit whose latest change has not yet been delivered - what the sweep retries.
	 *
	 * @return array<int,object>
	 */
	public static function visits_needing_delivery(): array {
		$table     = Manager::table( 'character_transfers' );
		$in_clause = implode( ',', array_fill( 0, count( self::TERMINAL_STATES ), '%s' ) );

		return array_map( [ self::class, 'decode_row' ], Manager::get_results(
			"SELECT * FROM {$table} WHERE direction = 'outbound' AND keep_current = 1 AND keep_current_accepted = 1
			 AND delivered_sequence < sequence AND state NOT IN ({$in_clause})",
			...self::TERMINAL_STATES
		) );
	}

	/**
	 * Marks the current, undelivered change generation - a new one the next real change will raise past.
	 *
	 * @param int $id
	 */
	public static function bump_sequence( int $id ): void {
		global $wpdb;
		$table = Manager::table( 'character_transfers' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET sequence = sequence + 1 WHERE id = %d", $id ) );
	}

	/**
	 * Records the outcome of one delivery attempt: the code it was issued under always, and - only once the host has
	 * confirmed it - the sequence delivered, the time, and a cleared `unreachable_since`.
	 *
	 * @param int  $id
	 * @param int  $attestation_id The code minted for this attempt.
	 * @param bool $confirmed      Whether the host accepted it.
	 */
	public static function record_delivery( int $id, int $attestation_id, bool $confirmed ): void {
		if ( ! $confirmed ) {
			Manager::update( 'character_transfers', [ 'last_code_id' => $attestation_id ], [ 'id' => $id ] );
			return;
		}

		$row = self::find( $id );
		Manager::update( 'character_transfers', [
			'last_code_id'       => $attestation_id,
			'delivered_sequence' => $row !== null ? $row->sequence : 0,
			'delivered_at'       => current_time( 'mysql', true ),
			'unreachable_since'  => null,
		], [ 'id' => $id ] );
	}

	/**
	 * Flags every kept-current visit whose last confirmed delivery (or, failing one, its own acknowledgement or
	 * start) is a full day old - the "Can't reach" state.
	 *
	 * @param int $hours
	 */
	public static function mark_long_unreachable( int $hours ): void {
		global $wpdb;
		$table  = Manager::table( 'character_transfers' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET unreachable_since = COALESCE( delivered_at, acknowledged_at, initiated_at )
			 WHERE direction = 'outbound' AND keep_current = 1 AND keep_current_accepted = 1
			 AND delivered_sequence < sequence AND unreachable_since IS NULL
			 AND COALESCE( delivered_at, acknowledged_at, initiated_at ) < %s",
			$cutoff
		) );
	}

	/**
	 * The host's own mirror of `mark_long_unreachable()`: flags a kept-current inbound visit whose last confirmed
	 * receipt from home is a full day old.
	 *
	 * @param int $hours
	 */
	public static function mark_long_unreachable_inbound( int $hours ): void {
		global $wpdb;
		$table  = Manager::table( 'character_transfers' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET unreachable_since = COALESCE( delivered_at, acknowledged_at, initiated_at )
			 WHERE direction = 'inbound' AND keep_current = 1 AND keep_current_accepted = 1 AND state = 'visiting'
			 AND unreachable_since IS NULL
			 AND COALESCE( delivered_at, acknowledged_at, initiated_at ) < %s",
			$cutoff
		) );
	}

	/**
	 * Stamps an inbound row's own "last heard from home" marker and clears any unreachable flag - the host's
	 * mirror of what `record_delivery()`'s confirmed branch does for an outbound row.
	 *
	 * @param int $id
	 */
	public static function mark_received( int $id ): void {
		Manager::update( 'character_transfers', [
			'delivered_at'      => current_time( 'mysql', true ),
			'unreachable_since' => null,
		], [ 'id' => $id ] );
	}

	/**
	 * Updates the chronicle identity an outbound row names as its host, after that chronicle renamed itself.
	 *
	 * @param int    $id
	 * @param string $host_slug
	 * @param string $host_chronicle
	 * @return bool
	 */
	public static function update_host_identity( int $id, string $host_slug, string $host_chronicle ): bool {
		return Manager::update( 'character_transfers', [
			'host_slug'      => $host_slug,
			'host_chronicle' => $host_chronicle,
		], [ 'id' => $id ] ) !== false;
	}

	/**
	 * Records the other side's own uuid for this same character.
	 *
	 * @param int    $id
	 * @param string $peer_uuid
	 * @return bool
	 */
	public static function set_peer_uuid( int $id, string $peer_uuid ): bool {
		return Manager::update( 'character_transfers', [ 'peer_uuid' => strtolower( $peer_uuid ) ], [ 'id' => $id ] ) !== false;
	}

	/**
	 * Every open inbound visit this chronicle hosts, kept current or not - what a rename tells each one's home about.
	 *
	 * @param string $host_slug
	 * @return array<int,object>
	 */
	public static function open_inbound_visits_for_host( string $host_slug ): array {
		$table     = Manager::table( 'character_transfers' );
		$in_clause = implode( ',', array_fill( 0, count( self::TERMINAL_STATES ), '%s' ) );

		return array_map( [ self::class, 'decode_row' ], Manager::get_results(
			"SELECT * FROM {$table} WHERE direction = 'inbound' AND host_slug = %s AND state NOT IN ({$in_clause})",
			$host_slug,
			...self::TERMINAL_STATES
		) );
	}

	/**
	 * The host's own newest inbound row for a character, whatever its state - whether its most recent visit here
	 * is still open or has already ended.
	 *
	 * @param int $character_id
	 * @return object|null
	 */
	public static function most_recent_inbound_visit( int $character_id ): ?object {
		$table = Manager::table( 'character_transfers' );
		return self::decode( Manager::get_row(
			"SELECT * FROM {$table} WHERE character_id = %d AND direction = 'inbound' ORDER BY id DESC LIMIT 1",
			$character_id
		) );
	}

	/**
	 * Casts the row's `tinyint(1)` flags to real booleans.
	 *
	 * @param object|null $row
	 * @return object|null
	 */
	private static function decode( ?object $row ): ?object {
		return $row === null ? null : self::decode_row( $row );
	}

	/**
	 * @param object $row
	 * @return object
	 */
	private static function decode_row( object $row ): object {
		if ( property_exists( $row, 'keep_current' ) ) {
			$row->keep_current = (bool) $row->keep_current;
		}
		if ( property_exists( $row, 'keep_current_accepted' ) ) {
			$row->keep_current_accepted = (bool) $row->keep_current_accepted;
		}
		if ( property_exists( $row, 'update_log' ) ) {
			$row->update_log = $row->update_log !== null ? ( json_decode( (string) $row->update_log, true ) ?: [] ) : [];
		}
		return $row;
	}

	/**
	 * Records the sequence a host has just applied - the inbound row's own "last applied" marker.
	 *
	 * @param int $id
	 * @param int $sequence
	 */
	public static function set_delivered_sequence( int $id, int $sequence ): void {
		Manager::update( 'character_transfers', [ 'delivered_sequence' => $sequence ], [ 'id' => $id ] );
	}

	/**
	 * Adds one row to the front of a visit's update log, keeping only the newest 50.
	 *
	 * @param int                  $id
	 * @param array<string,mixed>  $entry
	 */
	public static function append_update_log( int $id, array $entry ): void {
		$row = self::find( $id );
		$log = $row !== null && is_array( $row->update_log ) ? $row->update_log : [];
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 50 );
		Manager::update( 'character_transfers', [ 'update_log' => wp_json_encode( $log ) ], [ 'id' => $id ] );
	}
}
