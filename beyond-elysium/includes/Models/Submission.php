<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * A player-sent Grapevine file, waiting for a chronicle's Storyteller to
 * review it (F-122). Not `Transfer` - the sender's file may carry no uuid at
 * all, and any it does carry is dropped (1.0.0-review F-003/F-059), and there
 * is no home site to call back to until a Storyteller accepts and a real
 * character exists (a `Transfer` row is created then, for a visiting arrival
 * - see `Submissions_Controller::accept()`).
 *
 * At most one row in state `waiting` may exist per `(game_id, submitted_by)`
 * pair - enforced here in `create()`, matching `Transfer::create()`'s own
 * precedent (a composite unique index would fight `transition()`'s in-place
 * state changes).
 *
 * @see BE_PROCESS/design/player-grapevine-file-design.md §5
 */
class Submission {

	/** States that end a row's own open/closed lifecycle - only 'waiting' is open. */
	private const TERMINAL_STATES = [ 'accepted', 'refused', 'withdrawn', 'expired' ];

	/** Columns holding the sender's file - cleared the moment a row leaves 'waiting'. */
	private const FILE_COLUMNS = [ 'parsed', 'verification_source' ];

	/** How long a waiting file lasts before the daily sweep expires it - matches Transfer::OFFER_TTL_DAYS. */
	const WAITING_TTL_DAYS = Transfer::OFFER_TTL_DAYS;

	/** Columns returned by every list/read method that omits the stored file. */
	private const SUMMARY_COLUMNS = 'id, game_id, submitted_by, arrival, home_chronicle, character_name, stack_slug,
		source_file, format, file_hash, state, character_id, answered_by, answer_note, created_at, answered_at';

	/**
	 * Creates a new waiting submission. Refuses when this sender already has
	 * an open (`waiting`) row for this chronicle.
	 *
	 * @param array<string,mixed> $data
	 * @return int New row id.
	 * @throws \RuntimeException If a waiting row already exists for this sender+chronicle, or the row could not be written.
	 */
	public static function create( array $data ): int {
		$game_id      = (int) $data['game_id'];
		$submitted_by = (int) $data['submitted_by'];

		$unit = Transaction::begin( 'be_submission_create' );
		if ( ! Game::lock_by_id( $game_id ) ) {
			Transaction::rollback( $unit );
			throw new \RuntimeException( 'Failed to create submission row.' );
		}

		if ( self::has_waiting( $game_id, $submitted_by ) ) {
			Transaction::rollback( $unit );
			throw new \RuntimeException( 'A submission is already waiting for this chronicle from this sender.' );
		}

		$id = Manager::insert( 'character_submissions', [
			'game_id'              => $game_id,
			'submitted_by'         => $submitted_by,
			'arrival'              => (string) $data['arrival'],
			'home_chronicle'       => $data['home_chronicle'] ?? null,
			'character_name'       => (string) $data['character_name'],
			'stack_slug'           => (string) $data['stack_slug'],
			'source_file'          => (string) $data['source_file'],
			'format'               => (string) $data['format'],
			'file_hash'            => (string) $data['file_hash'],
			'parsed'               => (string) $data['parsed'],
			'verification_source'  => $data['verification_source'] ?? null,
			'state'                => 'waiting',
			'created_at'           => current_time( 'mysql', true ),
		] );

		if ( $id === false ) {
			Transaction::rollback( $unit );
			throw new \RuntimeException( 'Failed to create submission row.' );
		}
		Transaction::commit( $unit );
		return $id;
	}

	/**
	 * A submission by id, without its stored file columns.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		$table = Manager::table( 'character_submissions' );
		return Manager::get_row( 'SELECT ' . self::SUMMARY_COLUMNS . " FROM {$table} WHERE id = %d", $id );
	}

	/**
	 * A submission by id, including its stored file columns - only for a
	 * caller that is about to review or accept it.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find_with_file( int $id ): ?object {
		$table = Manager::table( 'character_submissions' );
		return Manager::get_row( "SELECT * FROM {$table} WHERE id = %d", $id );
	}

	/**
	 * Looks up a submission and locks its row until the surrounding
	 * transaction ends, so an accept/refuse decided on this read cannot race
	 * another action on the same row. Must run inside a Transaction.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find_for_update( int $id ): ?object {
		$table = Manager::table( 'character_submissions' );
		return Manager::get_row( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $id );
	}

	/**
	 * Moves a submission to a new state. Leaving 'waiting' always clears both
	 * file columns and stamps `answered_at` (unless the caller supplies one),
	 * matching the "nothing is kept once a request closes" rule (§2.6 of the
	 * design). Does not itself validate that the transition is legal from the
	 * row's current state - the REST controller enforces that, matching
	 * `Transfer::transition()`'s own thin-model convention.
	 *
	 * @param int                  $id
	 * @param string               $new_state
	 * @param array<string,mixed>  $extra Additional columns to set alongside state.
	 * @return bool
	 */
	public static function transition( int $id, string $new_state, array $extra = [] ): bool {
		$data = array_merge( $extra, [ 'state' => $new_state ] );

		if ( in_array( $new_state, self::TERMINAL_STATES, true ) ) {
			foreach ( self::FILE_COLUMNS as $column ) {
				$data[ $column ] = null;
			}
			if ( ! isset( $data['answered_at'] ) ) {
				$data['answered_at'] = current_time( 'mysql', true );
			}
		}

		return Manager::update( 'character_submissions', $data, [ 'id' => $id ] ) !== false;
	}

	/**
	 * Whether this sender already has a waiting submission for this
	 * chronicle - the other half of the "one waiting request per person per
	 * chronicle" rule `Characters_Controller::create_item()`'s own join-request
	 * check enforces for a hand-built character (§2.2 of the design).
	 *
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @return bool
	 * @phpstan-impure
	 */
	public static function has_waiting( int $game_id, int $wp_user_id ): bool {
		$table = Manager::table( 'character_submissions' );
		$count = Manager::get_var(
			"SELECT COUNT(*) FROM {$table} WHERE game_id = %d AND submitted_by = %d AND state = 'waiting'",
			$game_id,
			$wp_user_id
		);
		return (int) $count > 0;
	}

	/**
	 * Every waiting submission for a chronicle, newest first, without file columns.
	 *
	 * @param int $game_id
	 * @return array<int,object>
	 */
	public static function waiting_for_game( int $game_id ): array {
		$table = Manager::table( 'character_submissions' );
		return Manager::get_results(
			'SELECT ' . self::SUMMARY_COLUMNS . " FROM {$table} WHERE game_id = %d AND state = 'waiting' ORDER BY id DESC",
			$game_id
		);
	}

	/**
	 * How many submissions are currently waiting for a chronicle - the real
	 * check behind the 50-waiting-files cap (§12 of the design).
	 *
	 * @param int $game_id
	 * @return int
	 */
	public static function count_waiting( int $game_id ): int {
		$table = Manager::table( 'character_submissions' );
		return (int) Manager::get_var( "SELECT COUNT(*) FROM {$table} WHERE game_id = %d AND state = 'waiting'", $game_id );
	}

	/**
	 * A user's own last submissions across every chronicle, newest first,
	 * without file columns, joined with the chronicle's own name and slug.
	 *
	 * @param int $wp_user_id
	 * @param int $limit
	 * @return array<int,object>
	 */
	public static function for_user( int $wp_user_id, int $limit = 20 ): array {
		$table = Manager::table( 'character_submissions' );
		$games = Manager::table( 'games' );
		return Manager::get_results(
			"SELECT s.id, s.game_id, s.submitted_by, s.arrival, s.home_chronicle, s.character_name,
			        s.stack_slug, s.source_file, s.format, s.file_hash, s.state, s.character_id,
			        s.answered_by, s.answer_note, s.created_at, s.answered_at,
			        g.name AS game_name, g.slug AS game_slug
			 FROM {$table} s
			 INNER JOIN {$games} g ON g.id = s.game_id
			 WHERE s.submitted_by = %d
			 ORDER BY s.id DESC LIMIT %d",
			$wp_user_id,
			$limit
		);
	}

	/**
	 * Expires every submission still `waiting` past `WAITING_TTL_DAYS`,
	 * clearing its file columns with it - matching `Transfer::expire_stale()`'s
	 * own rule. Called by the daily `Core\Maintenance` sweep.
	 *
	 * @return int Rows expired.
	 */
	public static function expire_stale(): int {
		global $wpdb;
		$table  = Manager::table( 'character_submissions' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::WAITING_TTL_DAYS * DAY_IN_SECONDS );

		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET state = 'expired', parsed = NULL, verification_source = NULL, answered_at = %s
			 WHERE state = 'waiting' AND created_at < %s",
			current_time( 'mysql', true ),
			$cutoff
		) );
	}
}
