<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;

defined( 'ABSPATH' ) || exit;

/**
 * Port of `APREngineClass.AddStandardRumors` (GV301Source/Code/APREngineClass.cls).
 * Generates plots the same way Action_Allocator generates them: real chronicle
 * data in, `be_plots` rows out, no separate "rumor" table.
 *
 * `RumorClass`'s per-level text bodies are not ported. A generated rumor is a
 * plot shell (title + `target_query`); an ST fills in its actual content the
 * same way as any other plot, through `Entries_Controller` a level at a time
 * if they choose to.
 *
 * @see BE_PROCESS/workflow-0.5.md Step 5
 * @see BE_PROCESS/GV-SOURCEMAP.md "Rumor auto-generation"
 */
class Rumor_Generator {

	/** `PublicRumorTitle` constant from the VB6 source. */
	const PUBLIC_TITLE = 'Public Knowledge';

	/**
	 * Connections tagging a plot as a generated rumor carry this label, with
	 * `target_type: 'tag'`, since a rumor has no single owning entity the way
	 * an action allocation has its character.
	 */
	const RUMOR_LABEL = 'apr_rumor';

	/**
	 * Generates the standard rumor set for a game date, over active characters
	 * only, skipping any title already present at that date. Persists the
	 * generated plots only when `$commit` is true; otherwise returns the
	 * candidate list for preview.
	 *
	 * @param int    $game_id
	 * @param string $game_date `Y-m-d`.
	 * @param bool   $commit
	 * @return array[] Each: title, category, target_query, description.
	 */
	public static function generate( int $game_id, string $game_date, bool $commit = false ): array {
		$game = Game::find( $game_id );
		if ( ! $game ) {
			return [];
		}

		$toggles = self::rumor_config( $game );
		$existing = self::titles_at( $game_id, $game_date );

		$candidates = [];

		if ( $toggles['previous_rumors'] ) {
			foreach ( self::previous_date_candidates( $game_id, $game_date, $toggles['copy_previous'], $existing ) as $candidate ) {
				$candidates[] = $candidate;
				$existing[ $candidate['title'] ] = true;
			}
		}

		if ( $toggles['public_rumors'] && ! isset( $existing[ self::PUBLIC_TITLE ] ) ) {
			$candidates[] = [
				'title'         => self::PUBLIC_TITLE,
				'category'      => 'general',
				'target_query'  => null,
				'description'   => '',
			];
			$existing[ self::PUBLIC_TITLE ] = true;
		}

		// Active characters only, pre-resolved here so the candidate logic below stays pure.
		$characters = [];
		foreach ( Character::all_for_game( $game->slug, [ 'status' => 'active' ] ) as $character ) {
			$stack           = Creature_Stack::find_by_slug( $character->stack_slug );
			$characters[]    = [
				'name'        => $character->name,
				'stack_slug'  => $character->stack_slug,
				'stack_label' => $stack->name ?? $character->stack_slug,
				'influences'  => self::character_influences( $character ),
			];
		}

		foreach ( self::resolve_character_candidates( $characters, $toggles, $existing ) as $candidate ) {
			$candidates[] = $candidate;
		}

		if ( $commit ) {
			foreach ( $candidates as $candidate ) {
				self::persist_one( $game_id, $game_date, $candidate );
			}
		}

		return $candidates;
	}

	/**
	 * Generates personal, race, and influence rumor candidates per character.
	 * Pure - no database access. `$characters` entries are
	 * `{name, stack_slug, stack_label, influences}`, already filtered to active
	 * characters and pre-resolved by the caller.
	 *
	 * `group_rumors`/`subgroup_rumors` are recognized config toggles but produce
	 * no candidates here: characters carry no group/subgroup data to query against.
	 *
	 * @param array[] $characters
	 * @param array   $toggles
	 * @param array   $existing Titles already claimed; NOT mutated - a fresh
	 *                          local copy is used internally so this stays pure.
	 * @return array[]
	 */
	public static function resolve_character_candidates( array $characters, array $toggles, array $existing ): array {
		$candidates = [];

		foreach ( $characters as $character ) {

			if ( $toggles['personal_rumors'] ) {
				self::add_candidate( $candidates, $existing, $character['name'], 'personal', [
					'field' => 'name', 'operator' => 'equals', 'value' => $character['name'],
				] );
			}

			if ( $toggles['race_rumors'] ) {
				self::add_candidate( $candidates, $existing, $character['stack_label'], 'race', [
					'field' => 'stack_slug', 'operator' => 'equals', 'value' => $character['stack_slug'],
				] );
			}

			if ( $toggles['influence_rumors'] ) {
				foreach ( $character['influences'] as $influence_name ) {
					self::add_candidate( $candidates, $existing, "{$influence_name} Influence", 'influence', [
						'field' => 'influences', 'operator' => 'contains', 'value' => $influence_name,
					] );
				}
			}
		}

		return $candidates;
	}

	/**
	 * Adds a candidate to the list if its title is not already claimed for this
	 * date. Two characters that would produce the same title yield one rumor,
	 * not two duplicates.
	 *
	 * @param array[] $candidates
	 * @param array   $existing
	 * @param string  $title
	 * @param string  $category
	 * @param array   $target_query
	 * @return void
	 */
	private static function add_candidate( array &$candidates, array &$existing, string $title, string $category, array $target_query ): void {
		if ( $title === '' || isset( $existing[ $title ] ) ) {
			return;
		}
		$candidates[]      = [
			'title'        => $title,
			'category'     => $category,
			'target_query' => $target_query,
			'description'  => '',
		];
		$existing[ $title ] = true;
	}

	/**
	 * Collects the names of every Influence-sourced entry on a character's
	 * merged backgrounds sheet, using the same `source`-tag lookup
	 * `Action_Allocator::catalog_sources()` uses. Returns an empty array when
	 * the character's stack has no backgrounds block.
	 *
	 * @param object $character
	 * @return string[]
	 */
	private static function character_influences( $character ): array {
		$slug     = "{$character->stack_slug}-backgrounds";
		$sources  = Backgrounds_Catalog::sources_for( $slug, $character->owner_slug );
		if ( empty( $sources ) ) {
			return [];
		}

		$influence_names = [];
		foreach ( $sources as $name => $source ) {
			if ( $source === 'Influences' ) {
				$influence_names[ $name ] = true;
			}
		}

		$names = [];
		foreach ( $character->sheet_data[ $slug ] ?? [] as $trait ) {
			if ( isset( $influence_names[ $trait['name'] ?? '' ] ) ) {
				$names[] = $trait['name'];
			}
		}
		return $names;
	}

	/**
	 * Clones the previous rumor-generation date's titles and target queries
	 * forward, skipping anything already present at `$game_date`.
	 * `$copy_previous` decides whether the cloned plot's `description` carries
	 * over too.
	 *
	 * "Previous date" is the most recent earlier date this game has any
	 * rumor-tagged plot for.
	 *
	 * @param int    $game_id
	 * @param string $game_date
	 * @param bool   $copy_previous
	 * @param array  $existing
	 * @return array[]
	 */
	private static function previous_date_candidates( int $game_id, string $game_date, bool $copy_previous, array $existing ): array {
		$previous_date = self::most_recent_rumor_date_before( $game_id, $game_date );
		if ( ! $previous_date ) {
			return [];
		}

		$candidates = [];
		foreach ( self::rumor_plots_at( $game_id, $previous_date ) as $plot ) {
			if ( isset( $existing[ $plot->title ] ) ) {
				continue;
			}
			$candidates[] = [
				'title'        => $plot->title,
				'category'     => 'previous',
				'target_query' => $plot->target_query,
				'description'  => $copy_previous ? (string) $plot->description : '',
			];
		}
		return $candidates;
	}

	/**
	 * Persists one candidate as a plot row, then tags it as a rumor via a
	 * `tag`-type connection so it can be distinguished from manually-created
	 * plots later.
	 *
	 * @param int    $game_id
	 * @param string $game_date
	 * @param array  $candidate
	 * @return int Plot ID.
	 */
	private static function persist_one( int $game_id, string $game_date, array $candidate ): int {
		$plot_id = Plot::create( [
			'game_id'      => $game_id,
			'title'        => $candidate['title'],
			'description'  => $candidate['description'] ?: null,
			'initiated_by' => 'st',
			'game_date'    => $game_date,
			'target_query' => $candidate['target_query'],
			'created_by'   => get_current_user_id(),
		] );

		self::tag_as_rumor( $plot_id, $game_id );

		return $plot_id;
	}

	/**
	 * Tags an already-created plot as a rumor via the `apr_rumor` connection.
	 * The one piece of "what makes a plot a rumor" logic, shared between
	 * auto-generation (`persist_one()` above) and manual rumor creation
	 * (`Plots_Controller::create_item()`) so both paths stay identical.
	 *
	 * @param int $plot_id
	 * @param int $game_id
	 * @return void
	 */
	public static function tag_as_rumor( int $plot_id, int $game_id ): void {
		Connection::create( [
			'game_id'     => $game_id,
			'source_type' => 'plot',
			'source_id'   => $plot_id,
			'target_type' => 'tag',
			'label'       => self::RUMOR_LABEL,
			'created_by'  => get_current_user_id(),
		] );
	}

	/**
	 * Collects every rumor-tagged plot's title already present for this game
	 * and date. Used as a lookup set so generation can skip titles that would
	 * otherwise be duplicated.
	 *
	 * @param int    $game_id
	 * @param string $game_date
	 * @return array<string,true>
	 */
	private static function titles_at( int $game_id, string $game_date ): array {
		$titles = [];
		foreach ( self::rumor_plots_at( $game_id, $game_date ) as $plot ) {
			$titles[ $plot->title ] = true;
		}
		return $titles;
	}

	/**
	 * Fetches rumor-tagged plots for a game on an exact date, joining plots to
	 * connections on the `apr_rumor` tag label. Decodes each plot's
	 * `target_query` from JSON before returning it.
	 *
	 * @param int    $game_id
	 * @param string $game_date
	 * @return object[]
	 */
	private static function rumor_plots_at( int $game_id, string $game_date ): array {
		global $wpdb;
		$plots_table       = Manager::table( 'plots' );
		$connections_table = Manager::table( 'connections' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.* FROM {$plots_table} p
			 INNER JOIN {$connections_table} c ON c.source_type = 'plot' AND c.source_id = p.id
			 WHERE c.target_type = 'tag' AND c.label = %s
			   AND p.game_id = %d AND p.game_date = %s",
			self::RUMOR_LABEL,
			$game_id,
			$game_date
		) ) ?: [];

		foreach ( $rows as $row ) {
			if ( $row->target_query !== null ) {
				$row->target_query = json_decode( $row->target_query, true );
			}
		}
		return $rows;
	}

	/**
	 * Finds the most recent date strictly before `$game_date` that has any
	 * rumor-tagged plot for this game. Used to locate the prior generation
	 * date to clone forward from.
	 *
	 * @param int    $game_id
	 * @param string $game_date
	 * @return string|null `Y-m-d`, or null if none exists.
	 */
	private static function most_recent_rumor_date_before( int $game_id, string $game_date ): ?string {
		global $wpdb;
		$plots_table       = Manager::table( 'plots' );
		$connections_table = Manager::table( 'connections' );

		$date = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.game_date FROM {$plots_table} p
			 INNER JOIN {$connections_table} c ON c.source_type = 'plot' AND c.source_id = p.id
			 WHERE c.target_type = 'tag' AND c.label = %s
			   AND p.game_id = %d AND p.game_date IS NOT NULL AND p.game_date < %s
			 ORDER BY p.game_date DESC
			 LIMIT 1",
			self::RUMOR_LABEL,
			$game_id,
			$game_date
		) );

		return $date ?: null;
	}

	/**
	 * Reads rumor-generation toggles from `be_games.settings.apr`, falling back
	 * to a fixed set of defaults for any toggle a chronicle has not configured.
	 * Returns one boolean per toggle type (public, personal, race, group,
	 * subgroup, influence, previous, copy_previous).
	 *
	 * @param object $game
	 * @return array
	 */
	public static function rumor_config( $game ): array {
		$apr = $game->settings->apr ?? null;

		return [
			'public_rumors'    => (bool) ( $apr->public_rumors ?? true ),
			'personal_rumors'  => (bool) ( $apr->personal_rumors ?? false ),
			'race_rumors'      => (bool) ( $apr->race_rumors ?? false ),
			'group_rumors'     => (bool) ( $apr->group_rumors ?? false ),
			'subgroup_rumors'  => (bool) ( $apr->subgroup_rumors ?? false ),
			'influence_rumors' => (bool) ( $apr->influence_rumors ?? true ),
			'previous_rumors'  => (bool) ( $apr->previous_rumors ?? true ),
			'copy_previous'    => (bool) ( $apr->copy_previous ?? false ),
		];
	}
}
