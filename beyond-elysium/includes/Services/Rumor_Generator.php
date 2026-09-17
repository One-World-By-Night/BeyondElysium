<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;

defined( 'ABSPATH' ) || exit;

/**
 * Port of `APREngineClass.AddStandardRumors` (GV301Source/Code/APREngineClass.cls).
 * Generates plots the same way Action_Allocator generates them: real chronicle
 * data in, `be_plots` rows out, no separate "rumor" table.
 *
 * A generated rumor is a plot shell (title + `target_query`), held from birth with an
 * audience derived from that target_query, and an ST fills in its actual description
 * afterward the same way as any other plot. `RumorClass`'s per-level text bodies (1.1.0
 * §3.4 item 4) are ported as far as an influence rumor's own `rumor_level_key`/
 * `rumor_level_match` - the level texts themselves are written afterward through
 * `Plots_Controller::update_rumor_levels()`, not generated here, except when a "copy
 * previous" clone carries an earlier date's already-written levels forward.
 *
 * @see BE_PROCESS/releases/workflow-0.5.md Step 5
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.4
 * @see BE_PROCESS/reference/GV-SOURCEMAP.md "Rumor auto-generation"
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
	 * A commit holds the chronicle's row from reading the date's rumors to
	 * writing its own, so two at once can't each find the date empty, and
	 * writes every rumor - plot and tag - or none of them (1.0.0-review F-111).
	 *
	 * @param int    $game_id
	 * @param string $game_date `Y-m-d`.
	 * @param bool   $commit
	 * @return array[]|\WP_Error Each: title, category, target_query, description. An error when a commit could not write them, with nothing kept.
	 */
	public static function generate( int $game_id, string $game_date, bool $commit = false ) {
		$game = Game::find( $game_id );
		if ( ! $game ) {
			return [];
		}

		$unit = $commit ? Transaction::begin( 'be_rumor_generate' ) : null;
		if ( $unit !== null && ! Game::lock( $game->slug ) ) {
			Transaction::rollback( $unit );
			return self::not_saved();
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
		$active     = Character::all_for_game( $game->slug, [ 'status' => 'active' ] );
		$groups     = $toggles['group_rumors'] ? self::group_values( $active, 'group' ) : [];
		$subgroups  = $toggles['subgroup_rumors'] ? self::group_values( $active, 'subgroup' ) : [];
		$characters = [];
		foreach ( $active as $character ) {
			$stack        = Creature_Stack::find_by_slug( $character->stack_slug );
			$characters[] = [
				'name'        => $character->name,
				'stack_slug'  => $character->stack_slug,
				'stack_label' => $stack->name ?? $character->stack_slug,
				'group'       => $groups[ (int) $character->id ] ?? null,
				'subgroup'    => $subgroups[ (int) $character->id ] ?? null,
				'influences'  => self::character_influences( $character ),
			];
		}

		foreach ( self::resolve_character_candidates( $characters, $toggles, $existing ) as $candidate ) {
			$candidates[] = $candidate;
		}

		if ( $unit !== null ) {
			foreach ( $candidates as $candidate ) {
				if ( ! self::persist_one( $game_id, $game_date, $candidate ) ) {
					Transaction::rollback( $unit );
					return self::not_saved();
				}
			}
			Transaction::commit( $unit );
		}

		return $candidates;
	}

	/**
	 * @return \WP_Error
	 */
	private static function not_saved(): \WP_Error {
		return new \WP_Error( 'generate_failed', __( 'The rumors could not be saved. Nothing was changed.', 'beyond-elysium' ), [ 'status' => 500 ] );
	}

	/**
	 * Generates personal, race, group, subgroup, and influence rumor candidates
	 * per character, in Grapevine's order. Pure - no database access.
	 * `$characters` entries are `{name, stack_slug, stack_label, group,
	 * subgroup, influences}`, already filtered to active characters and
	 * pre-resolved by the caller; `group`/`subgroup` are `{field, label, value}`
	 * or null for a creature type without one (`group_values()`).
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

			// A group or subgroup rumor is titled with the value itself ("Brujah", "Camarilla"),
			// as Grapevine titles it; a bare number says nothing on its own, so it carries its
			// field ("Rank 2").
			foreach ( [ 'group' => 'group_rumors', 'subgroup' => 'subgroup_rumors' ] as $kind => $toggle ) {
				$held = $character[ $kind ] ?? null;
				if ( ! $toggles[ $toggle ] || $held === null || $held['value'] === '' ) {
					continue;
				}
				$title = ctype_digit( $held['value'] ) ? "{$held['label']} {$held['value']}" : $held['value'];
				self::add_candidate( $candidates, $existing, $title, $kind, [
					'field' => $held['field'], 'operator' => 'equals', 'value' => $held['value'],
				] );
			}

			if ( $toggles['influence_rumors'] ) {
				foreach ( $character['influences'] as $influence_name ) {
					// Levels (1.1.0 §3.4 item 4): an influence rumor is the one candidate type
					// with a real per-character rating to gate on - the Influence itself.
					self::add_candidate( $candidates, $existing, "{$influence_name} Influence", 'influence', [
						'field' => 'influences', 'operator' => 'contains', 'value' => $influence_name,
					], [
						'rumor_level_key'   => 'influences',
						'rumor_level_match' => $influence_name,
					] );
				}
			}
		}

		return $candidates;
	}

	/** @var array<string,array{group:?string,subgroup:?string}>|null */
	private static ?array $group_map = null;

	/**
	 * Each character's group or subgroup - the field `rumor-group-map.php`
	 * names for its creature type, read through the query engine so a
	 * chronicle's own blocks and a field shared by several types (Auspice)
	 * resolve the same way a rumor's target will. A character whose type has
	 * no such field is left out.
	 *
	 * @param object[] $characters
	 * @param string   $which 'group' | 'subgroup'.
	 * @return array<int,array{field:string,label:string,value:string}> Keyed by character id.
	 */
	private static function group_values( array $characters, string $which ): array {
		if ( self::$group_map === null ) {
			self::$group_map = require __DIR__ . '/rumor-group-map.php';
		}

		$by_field = [];
		foreach ( $characters as $character ) {
			$field = self::$group_map[ (string) $character->stack_slug ][ $which ] ?? null;
			if ( $field !== null ) {
				$by_field[ $field ][] = $character;
			}
		}

		$values = [];
		foreach ( $by_field as $field => $rows ) {
			$label = Field_Registry::get( $field )['title'] ?? $field;
			foreach ( Query_Engine::values_for( $rows, $field ) as $index => $value ) {
				$values[ (int) $rows[ $index ]->id ] = [
					'field' => $field,
					'label' => (string) $label,
					'value' => is_scalar( $value ) ? trim( (string) $value ) : '',
				];
			}
		}
		return $values;
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
	 * @param array   $extra Merged onto the candidate - `rumor_level_key`/`rumor_level_match`
	 *                       for an influence rumor (1.1.0 §3.4 item 4), empty otherwise.
	 * @return void
	 */
	private static function add_candidate( array &$candidates, array &$existing, string $title, string $category, array $target_query, array $extra = [] ): void {
		if ( $title === '' || isset( $existing[ $title ] ) ) {
			return;
		}
		$candidates[]      = array_merge( [
			'title'        => $title,
			'category'     => $category,
			'target_query' => $target_query,
			'description'  => '',
		], $extra );
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
	 * over too - and, since a level text is part of a rumor's own written
	 * content the same way its description is (1.1.0 §3.4 item 4: "'Copy
	 * previous' copies levels too"), whether its `rumor_level_key`/`match` and
	 * any level texts already written on it carry forward as well.
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
			$candidate = [
				'title'        => $plot->title,
				'category'     => 'previous',
				'target_query' => $plot->target_query,
				'description'  => $copy_previous ? (string) $plot->description : '',
			];
			if ( $copy_previous ) {
				if ( ! empty( $plot->rumor_level_key ) && ! empty( $plot->rumor_level_match ) ) {
					$candidate['rumor_level_key']   = $plot->rumor_level_key;
					$candidate['rumor_level_match'] = $plot->rumor_level_match;
				}
				$levels = [];
				foreach ( Plot_Entry::for_plot( (int) $plot->id, [ 'entry_type' => 'rumor_level' ] ) as $entry ) {
					if ( $entry->level !== null ) {
						$levels[ (int) $entry->level ] = (string) $entry->content;
					}
				}
				if ( ! empty( $levels ) ) {
					$candidate['levels'] = $levels;
				}
			}
			$candidates[] = $candidate;
		}
		return $candidates;
	}

	/**
	 * Persists one candidate as a plot row, then tags it as a rumor via a
	 * `tag`-type connection so it can be distinguished from manually-created
	 * plots later. Held from birth, with an audience derived from its own
	 * target_query - Public Knowledge (no target_query) reaches everyone, any
	 * other candidate is restricted to whoever its target_query matches
	 * (1.1.0 §3.4 items 1-2), and, when the candidate carries them (an
	 * influence rumor, or a "copy previous" clone of one), its rumor-level
	 * key/match and any already-written level texts.
	 *
	 * @param int    $game_id
	 * @param string $game_date
	 * @param array  $candidate
	 * @return bool False when the plot, its levels, or its tag could not be written.
	 */
	private static function persist_one( int $game_id, string $game_date, array $candidate ): bool {
		$target_query = $candidate['target_query'];

		$plot_id = Plot::create( [
			'game_id'           => $game_id,
			'title'             => $candidate['title'],
			'description'       => $candidate['description'] ?: null,
			'initiated_by'      => 'st',
			'game_date'         => $game_date,
			'target_query'      => $target_query,
			'audience'          => $target_query ? Audience::RESTRICTED : Audience::EVERYONE,
			'audience_rules'    => $target_query ? [ 'logic' => 'AND', 'conditions' => [ Query_Engine::target_query_to_condition( $target_query ) ] ] : null,
			'rumor_level_key'   => $candidate['rumor_level_key'] ?? null,
			'rumor_level_match' => $candidate['rumor_level_match'] ?? null,
			'created_by'        => get_current_user_id(),
		] );
		if ( $plot_id === false ) {
			return false;
		}

		if ( ! Plot::update( $plot_id, [ 'held' => true ] ) ) {
			return false;
		}

		foreach ( $candidate['levels'] ?? [] as $level => $content ) {
			$entry_id = Plot_Entry::create( [
				'plot_id'    => $plot_id,
				'author_id'  => get_current_user_id(),
				'entry_type' => 'rumor_level',
				'content'    => $content,
				'level'      => (int) $level,
			] );
			if ( ! $entry_id ) {
				return false;
			}
		}

		return self::tag_as_rumor( $plot_id, $game_id );
	}

	/**
	 * Tags an already-created plot as a rumor via the `apr_rumor` connection.
	 * The one piece of "what makes a plot a rumor" logic, shared between
	 * auto-generation (`persist_one()` above) and manual rumor creation
	 * (`Plots_Controller::create_item()`) so both paths stay identical.
	 *
	 * @param int $plot_id
	 * @param int $game_id
	 * @return bool False when the tag could not be written.
	 */
	public static function tag_as_rumor( int $plot_id, int $game_id ): bool {
		return false !== Connection::create( [
			'game_id'     => $game_id,
			'source_type' => 'plot',
			'source_id'   => $plot_id,
			'target_type' => 'tag',
			'label'       => self::RUMOR_LABEL,
			'created_by'  => get_current_user_id(),
		] );
	}

	/**
	 * Whether a plot carries the `apr_rumor` tag - the read-side counterpart to
	 * `tag_as_rumor()`, used by `Plots_Controller::update_item()` to know whether a
	 * `target_query` change should re-derive the rumor's audience (1.1.0 §3.4 item 1).
	 *
	 * @param int $plot_id
	 * @return bool
	 */
	public static function is_rumor( int $plot_id ): bool {
		foreach ( Connection::for_source( 'plot', $plot_id ) as $connection ) {
			if ( $connection->target_type === 'tag' && $connection->label === self::RUMOR_LABEL ) {
				return true;
			}
		}
		return false;
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
