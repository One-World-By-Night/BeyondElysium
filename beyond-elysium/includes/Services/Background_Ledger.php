<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;

defined( 'ABSPATH' ) || exit;

/**
 * The background-use ledger: the missing write path for the `action`/`result`
 * fields Action_Allocator has persisted on every subaction, unwritten, since
 * 0.5. A use is a `be_plot_entries` row on the character's own action-
 * allocation plot for that game date, marked `source: 'ledger'` so
 * Action_Allocator::persist() never regenerates or deletes it - there is no
 * separate table; the ledger is a spend log against the allocator's own
 * budget, per the Grapevine migration that folded the standalone influence-
 * use pool into the action-point system in the first place.
 *
 * @see BE_PROCESS/background-ledger-apr-design.md §0, §4, §5
 */
class Background_Ledger {

	/**
	 * Every background a character holds with count > 0, each annotated with
	 * its catalog source and, when the character's single most recent
	 * allocation granted it a subaction, that subaction's current unused
	 * budget. A character whose stack has no backgrounds block at all (e.g.
	 * `bete`) returns an empty array, not an error.
	 *
	 * @param int $character_id
	 * @return array[] {name, block_slug, level, source, budget_total, budget_name}
	 */
	public static function spendable_for( int $character_id ): array {
		$character = Character::find( $character_id );
		if ( ! $character ) {
			return [];
		}

		$backgrounds_slug = "{$character->stack_slug}-backgrounds";
		$held             = $character->sheet_data[ $backgrounds_slug ] ?? [];
		if ( empty( $held ) ) {
			return [];
		}

		$sources = Backgrounds_Catalog::sources_for( $backgrounds_slug, $character->owner_slug );

		// The stored allocator entry's own `unused` only reflects ledger spends as of the
		// last persist() call - re-applying apply_spends() here against that same plot's
		// own ledger entries keeps budget_total live-accurate for any use recorded since,
		// without requiring an ST to re-run the allocator first.
		$latest_plot_id = Action_Allocator::latest_plot_id( $character_id );
		$budget_by_name = [];
		if ( $latest_plot_id ) {
			$raw_subactions = array_values( Action_Allocator::subactions_for_plot( $latest_plot_id ) );
			$ledger_entries = self::entries_for_plot( $latest_plot_id );
			foreach ( self::apply_spends( $raw_subactions, $ledger_entries )['subactions'] as $subaction ) {
				$budget_by_name[ $subaction['name'] ] = $subaction;
			}
		}

		$result = [];
		foreach ( $held as $trait ) {
			$name = $trait['name'] ?? '';
			if ( $name === '' ) {
				continue;
			}
			$budget   = $budget_by_name[ $name ] ?? null;
			$result[] = [
				'name'         => $name,
				'block_slug'   => $backgrounds_slug,
				'level'        => (int) ( $trait['count'] ?? 0 ),
				'source'       => $sources[ $name ] ?? '',
				'budget_total' => $budget['unused'] ?? null,
				'budget_name'  => $budget !== null ? $name : null,
			];
		}
		return $result;
	}

	/**
	 * Debits a set of ledger entries against a set of allocator subactions.
	 * Pure and total - no database access, the same house style as
	 * Action_Allocator::resolve_common_subactions(). `base` is taken from
	 * each subaction's own `unused` (already carry-forward-adjusted), not
	 * `total`, so carry_unused keeps working unchanged. A ledger entry whose
	 * name matches no subaction debits nothing and is reported separately as
	 * unbudgeted_spends, rather than being silently dropped or forced onto
	 * the wrong budget (§4.1).
	 *
	 * @param array $subactions Output of Action_Allocator::allocate() or subactions_for_plot().
	 * @param array $entries    Decoded ledger entries: [{name, cost, ...}, ...].
	 * @return array{subactions: array[], unbudgeted_spends: array<string,int>}
	 */
	public static function apply_spends( array $subactions, array $entries ): array {
		$spent_by_name = [];
		foreach ( $entries as $entry ) {
			$name = $entry['name'] ?? '';
			if ( $name === '' ) {
				continue;
			}
			$spent_by_name[ $name ] = ( $spent_by_name[ $name ] ?? 0 ) + (int) ( $entry['cost'] ?? 1 );
		}

		$budgeted = [];
		$result   = [];
		foreach ( $subactions as $subaction ) {
			$name              = $subaction['name'];
			$base              = (int) $subaction['unused'];
			$spent             = (int) ( $spent_by_name[ $name ] ?? 0 );
			$budgeted[ $name ] = true;

			$result[] = array_merge( $subaction, [
				'unused'      => max( 0, $base - $spent ),
				'spent'       => $spent,
				'over_budget' => $spent > $base,
			] );
		}

		$unbudgeted = [];
		foreach ( $spent_by_name as $name => $spent ) {
			if ( ! isset( $budgeted[ $name ] ) ) {
				$unbudgeted[ $name ] = $spent;
			}
		}

		return [ 'subactions' => $result, 'unbudgeted_spends' => $unbudgeted ];
	}

	/**
	 * Returns the decoded ledger entries for a character on one game date,
	 * or an empty array when no allocation plot exists yet for that pair.
	 *
	 * @param int    $character_id
	 * @param string $game_date
	 * @return array[]
	 */
	public static function for_character_date( int $character_id, string $game_date ): array {
		$plot_id = Action_Allocator::find_own_plot_id( $character_id, $game_date );
		if ( ! $plot_id ) {
			return [];
		}
		return self::entries_for_plot( $plot_id );
	}

	/**
	 * Returns every decoded ledger entry on a plot, regardless of which
	 * character or date it belongs to. Used by Action_Allocator::is_complete(),
	 * which only has the plot id in scope.
	 *
	 * @param int $plot_id
	 * @return array[]
	 */
	public static function entries_for_plot( int $plot_id ): array {
		$entries = [];
		foreach ( Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] ) as $entry ) {
			$data = self::decode_ledger_entry( (int) $entry->id, $entry->content );
			if ( $data !== null ) {
				$entries[] = $data;
			}
		}
		return $entries;
	}

	/**
	 * Records one background use. Validates that the named background is
	 * one the character actually holds - never trusting a client-sent level
	 * or block_slug, both re-derived here. Writes onto the character's
	 * allocation plot for that date, creating a bare one first if no
	 * allocation has ever been run for it yet, since a use is always
	 * recordable, budgeted or not (§4.1) - a player should not have to wait
	 * for an ST to run the allocator before they can log what they did.
	 *
	 * @param int    $character_id
	 * @param string $game_date
	 * @param array  $use {name, cost?, text?}
	 * @return array|\WP_Error The recorded entry (decoded, with its own id), or a WP_Error.
	 */
	public static function record( int $character_id, string $game_date, array $use ) {
		$character = Character::find( $character_id );
		if ( ! $character ) {
			return new \WP_Error( 'character_not_found', __( 'Character not found.', 'beyond-elysium' ), [ 'status' => 404 ] );
		}

		$name = (string) ( $use['name'] ?? '' );
		if ( $name === '' ) {
			return new \WP_Error( 'invalid_param', __( 'A background name is required.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		$backgrounds_slug = "{$character->stack_slug}-backgrounds";

		if ( $name === Action_Allocator::PERSONAL_NAME ) {
			// Personal is not a catalog background - every character is granted this
			// subaction unconditionally (§1.7), so a use against it needs no held-trait
			// check. Recording one is how is_complete() (§5.5) can ever be satisfied for
			// it, since it can never appear in a character's own backgrounds sheet.
			$level = 0;
		} else {
			$held_trait = null;
			foreach ( $character->sheet_data[ $backgrounds_slug ] ?? [] as $trait ) {
				if ( ( $trait['name'] ?? '' ) === $name ) {
					$held_trait = $trait;
					break;
				}
			}
			if ( $held_trait === null ) {
				return new \WP_Error( 'not_held', __( 'This character does not hold that background.', 'beyond-elysium' ), [ 'status' => 400 ] );
			}
			$level = (int) ( $held_trait['count'] ?? 0 );
		}

		$plot_id = Action_Allocator::find_own_plot_id( $character_id, $game_date );
		if ( ! $plot_id ) {
			$game    = Game::find_by_slug( $character->owner_slug );
			$plot_id = Plot::create( [
				'game_id'      => (int) $game->id,
				'title'        => "{$game_date} {$character->name}",
				'initiated_by' => 'player',
				'game_date'    => $game_date,
				'created_by'   => get_current_user_id(),
			] );
			Connection::create( [
				'game_id'     => (int) $game->id,
				'source_type' => 'plot',
				'source_id'   => $plot_id,
				'target_type' => 'character',
				'target_id'   => $character_id,
				'label'       => Action_Allocator::ACTOR_LABEL,
				'created_by'  => get_current_user_id(),
			] );
		}

		$content = [
			'source'       => 'ledger',
			'block_slug'   => $name === Action_Allocator::PERSONAL_NAME ? null : $backgrounds_slug,
			'name'         => $name,
			'level'        => $level,
			'cost'         => isset( $use['cost'] ) ? max( 1, (int) $use['cost'] ) : 1,
			'text'         => sanitize_textarea_field( (string) ( $use['text'] ?? '' ) ),
			'result'       => '',
			'character_id' => $character_id,
			'recorded_by'  => get_current_user_id(),
			'recorded_at'  => current_time( 'mysql' ),
		];

		$entry_id = Plot_Entry::create( [
			'plot_id'    => $plot_id,
			'author_id'  => get_current_user_id(),
			'entry_type' => 'action',
			'content'    => wp_json_encode( $content ),
			'event_date' => $game_date,
		] );

		$decoded             = self::decode_ledger_entry( (int) $entry_id, wp_json_encode( $content ) );
		$decoded['plot_id']  = $plot_id;
		return $decoded;
	}

	/**
	 * Edits a ledger entry's text, result, or cost. Refuses (returns false)
	 * when the entry does not exist or is not a ledger entry - an allocator
	 * budget row or a player's own free-text action post is never editable
	 * through this method.
	 *
	 * @param int   $entry_id
	 * @param array $fields Any of: text, result, cost.
	 * @return bool
	 */
	public static function update_entry( int $entry_id, array $fields ): bool {
		$entry = Plot_Entry::find( $entry_id );
		if ( ! $entry ) {
			return false;
		}
		$data = self::decode_ledger_entry( $entry_id, $entry->content );
		if ( $data === null ) {
			return false;
		}
		unset( $data['id'] );

		foreach ( [ 'text', 'result' ] as $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				$data[ $field ] = sanitize_textarea_field( (string) $fields[ $field ] );
			}
		}
		if ( array_key_exists( 'cost', $fields ) ) {
			$data['cost'] = max( 1, (int) $fields['cost'] );
		}

		return Plot_Entry::update( $entry_id, [ 'content' => wp_json_encode( $data ) ] );
	}

	/**
	 * Deletes one ledger entry (frmInfluenceUse.frm's "Clear this use").
	 * Refuses when the entry is not a ledger entry - an allocator budget row
	 * is never deletable through this method.
	 *
	 * @param int $entry_id
	 * @return bool
	 */
	public static function clear_entry( int $entry_id ): bool {
		$entry = Plot_Entry::find( $entry_id );
		if ( ! $entry || self::decode_ledger_entry( $entry_id, $entry->content ) === null ) {
			return false;
		}
		return Plot_Entry::delete( $entry_id );
	}

	/**
	 * Deletes every ledger entry for one character, optionally bounded to a
	 * game-date range - Grapevine's "Clear all for this Character"
	 * (frmInfluenceUse.frm:446-488), made explicitly scoped rather than
	 * accidentally date-bound the way the original was (§1.4). Never
	 * touches an allocator budget entry, on this or any other character's plot.
	 *
	 * @param int         $character_id
	 * @param string|null $from `Y-m-d`, inclusive.
	 * @param string|null $to   `Y-m-d`, inclusive.
	 * @return int Entries deleted.
	 */
	public static function clear_for_character( int $character_id, ?string $from = null, ?string $to = null ): int {
		global $wpdb;
		$plots_table       = Manager::table( 'plots' );
		$connections_table = Manager::table( 'connections' );

		$where  = [ "c.target_type = 'character'", 'c.target_id = %d', 'c.label = %s' ];
		$values = [ $character_id, Action_Allocator::ACTOR_LABEL ];
		if ( $from ) {
			$where[]  = 'p.game_date >= %s';
			$values[] = $from;
		}
		if ( $to ) {
			$where[]  = 'p.game_date <= %s';
			$values[] = $to;
		}

		$plot_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.id FROM {$plots_table} p
			 INNER JOIN {$connections_table} c ON c.source_type = 'plot' AND c.source_id = p.id
			 WHERE " . implode( ' AND ', $where ),
			$values
		) );

		return self::clear_ledger_entries_on_plots( array_map( 'intval', $plot_ids ) );
	}

	/**
	 * Deletes every ledger entry for one game date across the whole
	 * chronicle - Grapevine's "Clear all for this Date"
	 * (APREngineClass.cls:415-439 / frmActionList.frm:464-466). Never
	 * touches an allocator budget entry.
	 *
	 * @param int    $game_id
	 * @param string $game_date
	 * @return int Entries deleted.
	 */
	public static function clear_for_date( int $game_id, string $game_date ): int {
		global $wpdb;
		$plots_table = Manager::table( 'plots' );

		$plot_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$plots_table} WHERE game_id = %d AND game_date = %s",
			$game_id,
			$game_date
		) );

		return self::clear_ledger_entries_on_plots( array_map( 'intval', $plot_ids ) );
	}

	/**
	 * Deletes only the ledger-marked entries across a set of plots, leaving
	 * every allocator budget entry and every player's own free-text action
	 * post untouched. Shared by clear_for_character() and clear_for_date().
	 *
	 * @param int[] $plot_ids
	 * @return int Entries deleted.
	 */
	private static function clear_ledger_entries_on_plots( array $plot_ids ): int {
		$count = 0;
		foreach ( $plot_ids as $plot_id ) {
			foreach ( Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] ) as $entry ) {
				if ( self::decode_ledger_entry( (int) $entry->id, $entry->content ) !== null ) {
					Plot_Entry::delete( (int) $entry->id );
					$count++;
				}
			}
		}
		return $count;
	}

	/**
	 * Decodes an entry's `content` as ledger data. Returns null when the
	 * content is not valid JSON, or when it is valid JSON that is not a
	 * ledger-managed entry - an allocator budget row or a player's own
	 * free-text action post sharing the same entry_type. Attaches the
	 * entry's own row id, which the stored content itself does not carry.
	 *
	 * @param int    $entry_id
	 * @param string $content
	 * @return array|null
	 */
	private static function decode_ledger_entry( int $entry_id, string $content ): ?array {
		$data = json_decode( $content, true );
		if ( ! is_array( $data ) || ( $data['source'] ?? '' ) !== 'ledger' ) {
			return null;
		}
		$data['id'] = $entry_id;
		return $data;
	}
}
