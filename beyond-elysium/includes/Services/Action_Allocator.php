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
 * Allocates action-point subactions for a character on a game date, port
 * of `ActionClass.AddCommonActions` (GV301Source/Code/ActionClass.cls).
 *
 * A subaction is a named allocation of action points with a total, an
 * unused remainder, and growth carried forward from a prior week. Every
 * character is seeded with a "Personal" subaction; one more is added per
 * Influence (unconditionally) and per Background named in the game's
 * `background_actions` config, using the source tag attached to each item
 * in the `{stack}-backgrounds` block's definition to tell an
 * Influence-sourced entry apart from a Backgrounds-sourced one.
 *
 * @see BE_PROCESS/workflow-0.5.md Step 4
 * @see BE_PROCESS/GV-SOURCEMAP.md "Action allocation"
 */
class Action_Allocator {

	/**
	 * `ActionClass.BasicSubactionName` - every character gets this subaction, seeded
	 * fresh at `personal_actions` rather than derived from a trait.
	 */
	const PERSONAL_NAME = 'Personal';

	/**
	 * Connections linking an action-allocation plot to its character carry this label,
	 * distinguishing it from any other plot<->character connection (a cast credit, a
	 * plot hook, etc.) that is not an allocation record.
	 */
	const ACTOR_LABEL = 'apr_actor';

	/**
	 * Computes the subaction set for a character on a game date, without
	 * persisting it. Builds the Personal subaction and, when the game's
	 * APR config enables it, resolves one subaction per qualifying
	 * Influence or Background trait on the character's sheet, then debits
	 * against each subaction any Background_Ledger entries already recorded
	 * for this exact (character, date) pair - so a preview, a commit, and a
	 * later re-fetch of the same allocation all show the same spent/unused
	 * numbers (BE_PROCESS/background-ledger-apr-design.md §5.4). A date with
	 * no ledger entries yet is unaffected: every subaction's `spent` is 0
	 * and `unused` is unchanged.
	 *
	 * @param int    $character_id
	 * @param string $game_date `Y-m-d`.
	 * @return array[] Each: name, level, total, unused, growth, spent, over_budget.
	 */
	public static function allocate( int $character_id, string $game_date ): array {
		$character = Character::find( $character_id );
		if ( ! $character ) {
			return [];
		}

		$game = Game::find_by_slug( $character->owner_slug );
		$apr  = self::apr_config( $game );

		$prior = self::most_recent_allocation( $character_id, $game_date );

		$subactions   = [];
		$subactions[] = self::build_personal_subaction( $apr, $prior );

		if ( $apr['add_common'] ) {
			$backgrounds_slug = "{$character->stack_slug}-backgrounds";
			$chosen           = $character->sheet_data[ $backgrounds_slug ] ?? [];
			$source_by_name   = self::catalog_sources( $backgrounds_slug, $character->owner_slug );

			foreach ( self::resolve_common_subactions( $chosen, $source_by_name, $apr, $prior ) as $subaction ) {
				$subactions[] = $subaction;
			}
		}

		$ledger_entries = Background_Ledger::for_character_date( $character_id, $game_date );
		return Background_Ledger::apply_spends( $subactions, $ledger_entries )['subactions'];
	}

	/**
	 * Builds the "Personal" subaction, seeded for every character at the
	 * game's `personal_actions` total regardless of any trait. Its
	 * `growth` always carries forward from the prior allocation; its
	 * `unused` carries forward only when the game's `carry_unused`
	 * setting is on, otherwise it resets to the fresh total.
	 *
	 * Pure function with no database access.
	 *
	 * @param array $apr
	 * @param array $prior Prior allocation, keyed by subaction name.
	 * @return array
	 */
	public static function build_personal_subaction( array $apr, array $prior ): array {
		$total  = $apr['personal_actions'];
		$unused = $total;
		$growth = 0;

		if ( isset( $prior[ self::PERSONAL_NAME ] ) ) {
			if ( $apr['carry_unused'] ) {
				$unused = $prior[ self::PERSONAL_NAME ]['unused'];
			}
			$growth = $prior[ self::PERSONAL_NAME ]['growth'];
		}

		return [
			'name'   => self::PERSONAL_NAME,
			'level'  => 0,
			'total'  => $total,
			'unused' => $unused,
			'growth' => $growth,
		];
	}

	/**
	 * Resolves the set of Influence and Background subactions for a
	 * character. Both trait kinds live in the same merged
	 * `{stack}-backgrounds` sheet; an entry is included unconditionally
	 * when its catalog source is `Influences`, or only when its name is
	 * listed in `background_actions` otherwise. Skips any name already
	 * seen in this pass.
	 *
	 * Pure function with no database access.
	 *
	 * @param array $chosen         The character's `{stack}-backgrounds` sheet_data: `[{name, count}, ...]`.
	 * @param array $source_by_name Catalog name -> source ('Influences', 'Backgrounds', ...).
	 * @param array $apr
	 * @param array $prior
	 * @return array[]
	 */
	public static function resolve_common_subactions( array $chosen, array $source_by_name, array $apr, array $prior ): array {
		$subactions = [];
		$seen       = [];

		foreach ( $chosen as $trait ) {
			$name = $trait['name'] ?? '';
			if ( $name === '' || isset( $seen[ $name ] ) ) {
				// A name already added this pass is skipped, not duplicated.
				continue;
			}

			$is_influence = ( $source_by_name[ $name ] ?? '' ) === 'Influences';
			$is_background_action = in_array( $name, $apr['background_actions'], true );

			if ( ! $is_influence && ! $is_background_action ) {
				continue;
			}

			$seen[ $name ] = true;
			$subactions[]  = self::build_common_subaction( $name, (int) ( $trait['count'] ?? 0 ), $apr, $prior );
		}

		return $subactions;
	}

	/**
	 * Builds one Influence or Background subaction. The total defaults to
	 * twice the trait's count, overridden by an `actions_per_level` entry
	 * keyed on that same count when one exists. `growth` always carries
	 * forward from the prior allocation; `unused` carries forward,
	 * replacing rather than adding to the fresh total, only when
	 * `carry_unused` is on.
	 *
	 * Pure function with no database access.
	 *
	 * @param string $name
	 * @param int    $count
	 * @param array  $apr
	 * @param array  $prior
	 * @return array
	 */
	public static function build_common_subaction( string $name, int $count, array $apr, array $prior ): array {
		$total = 2 * $count;
		if ( array_key_exists( (string) $count, $apr['actions_per_level'] ) ) {
			$total = (int) $apr['actions_per_level'][ (string) $count ];
		}

		$unused = $total;
		$growth = 0;

		if ( isset( $prior[ $name ] ) ) {
			if ( $apr['carry_unused'] ) {
				// Carry-forward replaces the starting pool; it does not add to it.
				$unused = $prior[ $name ]['unused'];
			}
			// Growth carries forward regardless of carry_unused.
			$growth = $prior[ $name ]['growth'];
		}

		return [
			'name'   => $name,
			'level'  => $count,
			'total'  => $total,
			'unused' => $unused,
			'growth' => $growth,
		];
	}

	/**
	 * Builds a map of catalog item name to source label for the merged
	 * backgrounds block, resolved through this chronicle's own fork when
	 * one exists (BE_PROCESS/background-ledger-apr-design.md §3.1).
	 *
	 * @param string $backgrounds_slug
	 * @param string $game_slug
	 * @return array<string,string>
	 */
	private static function catalog_sources( string $backgrounds_slug, string $game_slug ): array {
		return Backgrounds_Catalog::sources_for( $backgrounds_slug, $game_slug );
	}

	/**
	 * Reads a game's action-point-allocation configuration from
	 * `be_games.settings.apr`, filling in default values for any setting
	 * a chronicle has not configured.
	 *
	 * Pure function with no database access.
	 *
	 * @param object|null $game
	 * @return array{personal_actions:int, carry_unused:bool, add_common:bool, background_actions:string[], actions_per_level:array<string,int>}
	 */
	public static function apr_config( $game ): array {
		$apr = $game->settings->apr ?? null;

		return [
			'personal_actions'   => (int) ( $apr->personal_actions ?? 3 ),
			'carry_unused'       => (bool) ( $apr->carry_unused ?? true ),
			'add_common'         => (bool) ( $apr->add_common ?? true ),
			'background_actions' => (array) ( $apr->background_actions ?? [] ),
			'actions_per_level'  => (array) ( $apr->actions_per_level ?? [] ),
		];
	}

	/**
	 * Finds the most recent persisted allocation for a character strictly
	 * before the given game date, decoded into a map of subaction name to
	 * `{total, unused, growth}`. Returns an empty array when no prior
	 * allocation exists.
	 *
	 * @param int    $character_id
	 * @param string $game_date `Y-m-d`.
	 * @return array<string,array{total:int,unused:int,growth:int}>
	 */
	/**
	 * Returns the character id an allocation plot's `apr_actor` connection
	 * targets, or null when the plot has no such connection - either it is
	 * not an allocator plot at all, or its connection is missing. Used by
	 * the REST layer to decide whether a non-manager viewer may see this
	 * plot's action entries at all (BE_PROCESS/background-ledger-apr-design.md
	 * §3.4/§5.8: an allocation plot's contents disclose a character's exact
	 * background dot ratings and are not public).
	 *
	 * @param int $plot_id
	 * @return int|null
	 */
	public static function actor_character_id( int $plot_id ): ?int {
		foreach ( Connection::for_entity( 'plot', $plot_id ) as $connection ) {
			if ( $connection->label === self::ACTOR_LABEL && $connection->target_type === 'character' ) {
				return (int) $connection->target_id;
			}
		}
		return null;
	}

	/**
	 * Reports whether the given user owns the character an allocation plot's
	 * `apr_actor` connection targets. A plot with no such connection (not an
	 * allocator plot) is not viewable through this check - callers only use
	 * it to decide visibility for allocator plots specifically.
	 *
	 * @param int $plot_id
	 * @param int $wp_user_id
	 * @return bool
	 */
	public static function is_actor_owned_by( int $plot_id, int $wp_user_id ): bool {
		$character_id = self::actor_character_id( $plot_id );
		if ( $character_id === null ) {
			return false;
		}
		$character = Character::find( $character_id );
		return $character && (int) $character->wp_user_id === $wp_user_id;
	}

	private static function most_recent_allocation( int $character_id, string $game_date ): array {
		$plot_id = self::find_prior_plot_id( $character_id, $game_date );
		if ( ! $plot_id ) {
			return [];
		}

		$by_name = [];
		foreach ( Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] ) as $entry ) {
			$data = self::decode_allocator_entry( $entry->content );
			if ( $data !== null ) {
				$by_name[ $data['name'] ] = $data;
			}
		}
		return $by_name;
	}

	/**
	 * Finds the ID of the most recent action-allocation plot for a
	 * character dated strictly before the given game date. Queries the
	 * plots and connections tables for a plot linked to the character
	 * with the allocator's actor label, ordered by game date descending.
	 *
	 * @param int    $character_id
	 * @param string $game_date
	 * @return int|null
	 */
	private static function find_prior_plot_id( int $character_id, string $game_date ): ?int {
		global $wpdb;
		$plots_table       = Manager::table( 'plots' );
		$connections_table = Manager::table( 'connections' );

		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.id FROM {$plots_table} p
			 INNER JOIN {$connections_table} c ON c.source_type = 'plot' AND c.source_id = p.id
			 WHERE c.target_type = 'character' AND c.target_id = %d AND c.label = %s
			   AND p.game_date IS NOT NULL AND p.game_date < %s
			 ORDER BY p.game_date DESC
			 LIMIT 1",
			$character_id,
			self::ACTOR_LABEL,
			$game_date
		) );

		return $id ? (int) $id : null;
	}

	/**
	 * Persists an allocation: one plot per character/date pair, with one
	 * `action` entry per subaction. Idempotent - re-running for the same
	 * character and date updates the existing plot's entries rather than
	 * creating a second plot.
	 *
	 * @param int      $character_id
	 * @param string   $game_date
	 * @param int|null $parent_plot_id Nests this action under a chosen plot.
	 *                 Only applied when creating a new allocation plot - re-running for an
	 *                 already-allocated character/date never silently reparents it.
	 * @return int Plot ID.
	 */
	public static function persist( int $character_id, string $game_date, ?int $parent_plot_id = null ): int {
		$character = Character::find( $character_id );
		$game      = Game::find_by_slug( $character->owner_slug );

		$subactions = self::allocate( $character_id, $game_date );

		$existing_plot_id = self::find_own_plot_id( $character_id, $game_date );

		if ( $existing_plot_id ) {
			$plot_id = $existing_plot_id;
			// Replace only the allocator's own budget entries with the freshly computed set -
			// never a player's free-text action post, and never a ledger spend (§3.3/§5.1:
			// a re-allocation must not erase either one).
			foreach ( Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] ) as $entry ) {
				if ( self::decode_allocator_entry( $entry->content ) !== null ) {
					Plot_Entry::delete( (int) $entry->id );
				}
			}
		} else {
			// Title format: game date followed by the character's name.
			$plot_id = Plot::create( [
				'game_id'        => (int) $game->id,
				'parent_plot_id' => $parent_plot_id,
				'title'          => "{$game_date} {$character->name}",
				'initiated_by'   => 'player',
				'game_date'      => $game_date,
				'created_by'     => get_current_user_id(),
			] );
			Connection::create( [
				'game_id'     => (int) $game->id,
				'source_type' => 'plot',
				'source_id'   => $plot_id,
				'target_type' => 'character',
				'target_id'   => $character_id,
				'label'       => self::ACTOR_LABEL,
				'created_by'  => get_current_user_id(),
			] );
		}

		foreach ( $subactions as $subaction ) {
			Plot_Entry::create( [
				'plot_id'    => $plot_id,
				'author_id'  => get_current_user_id(),
				'entry_type' => 'action',
				'content'    => self::encode_allocator_entry( $subaction ),
			] );
		}

		return $plot_id;
	}

	/**
	 * Finds the ID of this character's own allocation plot for an exact
	 * game date, if one already exists. Used by `persist()` to decide
	 * whether to update an existing plot or create a new one, and by
	 * `Background_Ledger` to find the plot a use should debit against.
	 *
	 * @param int    $character_id
	 * @param string $game_date
	 * @return int|null
	 */
	public static function find_own_plot_id( int $character_id, string $game_date ): ?int {
		global $wpdb;
		$plots_table       = Manager::table( 'plots' );
		$connections_table = Manager::table( 'connections' );

		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.id FROM {$plots_table} p
			 INNER JOIN {$connections_table} c ON c.source_type = 'plot' AND c.source_id = p.id
			 WHERE c.target_type = 'character' AND c.target_id = %d AND c.label = %s
			   AND p.game_date = %s
			 LIMIT 1",
			$character_id,
			self::ACTOR_LABEL,
			$game_date
		) );

		return $id ? (int) $id : null;
	}

	/**
	 * Finds the ID of a character's single most recent allocation plot, with
	 * no date constraint - what `Background_Ledger::spendable_for()` reads to
	 * annotate which held backgrounds currently have a live budget, independent
	 * of which date the ledger panel happens to be viewing.
	 *
	 * @param int $character_id
	 * @return int|null
	 */
	public static function latest_plot_id( int $character_id ): ?int {
		global $wpdb;
		$plots_table       = Manager::table( 'plots' );
		$connections_table = Manager::table( 'connections' );

		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.id FROM {$plots_table} p
			 INNER JOIN {$connections_table} c ON c.source_type = 'plot' AND c.source_id = p.id
			 WHERE c.target_type = 'character' AND c.target_id = %d AND c.label = %s
			   AND p.game_date IS NOT NULL
			 ORDER BY p.game_date DESC
			 LIMIT 1",
			$character_id,
			self::ACTOR_LABEL
		) );

		return $id ? (int) $id : null;
	}

	/**
	 * Decodes a plot's allocator-managed subactions into a name -> subaction
	 * map, the same decoding `most_recent_allocation()` applies, exposed for
	 * `Background_Ledger` to read a specific plot's live budget.
	 *
	 * @param int $plot_id
	 * @return array<string,array{name:string,level:int,total:int,unused:int,growth:int,action:string,result:string}>
	 */
	public static function subactions_for_plot( int $plot_id ): array {
		$by_name = [];
		foreach ( Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] ) as $entry ) {
			$data = self::decode_allocator_entry( $entry->content );
			if ( $data !== null ) {
				$by_name[ $data['name'] ] = $data;
			}
		}
		return $by_name;
	}

	/**
	 * Determines whether an allocation plot is complete: every budgeted
	 * subaction has at least one Background_Ledger entry recorded against
	 * it, and every one of those entries has a non-empty `result` - the
	 * meaning `frmAction.frm`'s own Action/Result fields carried before the
	 * ledger's write path existed to fill them
	 * (BE_PROCESS/background-ledger-apr-design.md §5.5). False when the plot
	 * has no subactions at all.
	 *
	 * This replaces the original "action and result on the allocator entry
	 * itself" rule, which nothing had ever written to and which could
	 * therefore never return true for a real allocation (§2.3).
	 *
	 * @param int $plot_id
	 * @return bool
	 */
	public static function is_complete( int $plot_id ): bool {
		$subactions = self::subactions_for_plot( $plot_id );
		if ( empty( $subactions ) ) {
			return false;
		}

		$ledger_by_name = [];
		foreach ( Background_Ledger::entries_for_plot( $plot_id ) as $entry ) {
			$ledger_by_name[ $entry['name'] ?? '' ][] = $entry;
		}

		foreach ( array_keys( $subactions ) as $name ) {
			$uses = $ledger_by_name[ $name ] ?? [];
			if ( empty( $uses ) ) {
				return false;
			}
			foreach ( $uses as $use ) {
				if ( ( $use['result'] ?? '' ) === '' ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Encodes a subaction as JSON for storage in `plot_entries.content`.
	 * Adds a `source: 'allocator'` marker so an allocator-managed entry
	 * can be told apart from a player's own free-text action post sharing
	 * the same `entry_type`.
	 *
	 * @param array $subaction
	 * @return string
	 */
	private static function encode_allocator_entry( array $subaction ): string {
		return (string) wp_json_encode( array_merge( $subaction, [
			'action' => $subaction['action'] ?? '',
			'result' => $subaction['result'] ?? '',
			'source' => 'allocator',
		] ) );
	}

	/**
	 * Decodes an entry's `content` as allocator data. Returns null when
	 * the content is not valid JSON, or when it is valid JSON that is not
	 * an allocator-managed entry, such as a player's own free-text action
	 * post sharing the same `entry_type`.
	 *
	 * @param string $content
	 * @return array{name:string,level:int,total:int,unused:int,growth:int,action:string,result:string}|null
	 */
	private static function decode_allocator_entry( string $content ): ?array {
		$data = json_decode( $content, true );
		if ( ! is_array( $data ) || ( $data['source'] ?? '' ) !== 'allocator' ) {
			return null;
		}

		return [
			'name'   => (string) ( $data['name'] ?? '' ),
			'level'  => (int) ( $data['level'] ?? 0 ),
			'total'  => (int) ( $data['total'] ?? 0 ),
			'unused' => (int) ( $data['unused'] ?? 0 ),
			'growth' => (int) ( $data['growth'] ?? 0 ),
			'action' => (string) ( $data['action'] ?? '' ),
			'result' => (string) ( $data['result'] ?? '' ),
		];
	}
}
