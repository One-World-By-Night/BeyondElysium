<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Display\Change_Description;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves one of the 19 `report-registry.php` entries down to a plain,
 * presentation-neutral document - the report-level sibling of
 * `Sheet_Document` (reports-cards-batch-design.md §3.2). No TCPDF reference
 * anywhere in this file; `Report_Writer` turns the returned array into pages
 * without needing to know how any of it was produced.
 *
 * Every `table`/`card` column whose registry source is `field` resolves
 * through `Query_Engine::resolve_value()` - the same field a GV301 report
 * token and the query builder both already read (GV-SOURCEMAP.md's own
 * finding: template tokens are query keys). `player` and `plot` are the two
 * genuinely new resolvers this item adds, since the query engine has never
 * dispatched to either (GV-SOURCEMAP.md's three-tier query table).
 *
 * @see BE_PROCESS/reports-cards-batch-design.md
 */
class Report_Document {

	/**
	 * @param array<string,mixed> $filters Query_Engine-shaped {conditions, logic}, or empty for "everyone in scope".
	 * @param array<string,mixed> $options can_manage (bool), plus report-specific overrides (e.g. stat_field/stat_type for statistics-report).
	 * @return array<string,mixed>|null Null when the report_key or game_slug does not resolve.
	 */
	public static function build( string $report_key, string $game_slug, array $filters = [], array $options = [] ): ?array {
		$registry = self::registry();
		$report   = $registry[ $report_key ] ?? null;
		if ( $report === null ) {
			return null;
		}

		$game = Game::find_by_slug( $game_slug );
		if ( $game === null ) {
			return null;
		}

		$can_manage = ! empty( $options['can_manage'] );

		switch ( $report['shape'] ) {
			case 'table':
				return self::build_table( $report, $game, $filters, $options, $can_manage );
			case 'card':
				return self::build_card( $report, $game, $filters, $can_manage );
			case 'statistics':
				return self::build_statistics( $report, $game, $filters, $options );
			case 'narrative':
				return self::build_narrative( $report, $game );
			case 'calendar':
				return self::build_calendar( $report, $game );
			case 'house_rules':
				return self::build_house_rules( $report, $game );
			default:
				return null;
		}
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function registry(): array {
		static $registry = null;
		if ( $registry === null ) {
			$registry = include BE_PLUGIN_DIR . 'includes/Database/report-registry.php';
		}
		return $registry;
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $filters
	 * @param array<string,mixed> $options
	 */
	private static function build_table( array $report, object $game, array $filters, array $options, bool $can_manage ): array {
		$entity = $report['entity'];

		if ( ( $report['rows_from'] ?? null ) === 'ledger' ) {
			$rows = $entity === 'player' ? self::ledger_rows_for_players( $game ) : self::ledger_rows_for_characters( $game );
		} elseif ( $entity === 'player' ) {
			$rows = self::player_rows( $game );
		} elseif ( $entity === 'plot' ) {
			$rows = self::plot_rows( $game, $report );
		} else {
			$result = Query_Engine::execute(
				$game->slug,
				(array) ( $filters['conditions'] ?? [] ),
				(string) ( $filters['logic'] ?? 'AND' ),
				[ 'per_page' => 1000, 'sort' => [ 'field' => $report['sort'] ?? 'name' ] ],
				$entity
			);
			$rows = $result['results'];
			if ( $entity === 'char' && ! $can_manage ) {
				$rows = array_values( array_filter( $rows, static function ( $row ) {
					return empty( $row->is_npc );
				} ) );
			}
		}

		$table_rows = [];
		foreach ( $rows as $row ) {
			$table_rows[] = self::resolve_columns( $report['columns'], $row, $game, $entity );
		}

		return [
			'title'   => $report['title'],
			'shape'   => 'table',
			'columns' => array_map( static fn( $c ) => $c[0], $report['columns'] ),
			'rows'    => $table_rows,
			'game'    => $game->name,
		];
	}

	/**
	 * Experience History: one synthetic row per approved `Change`, decorated
	 * with the owning character's name - `resolve_ledger()` reads both off
	 * the same object rather than `Report_Document` needing a second join
	 * pass per column.
	 *
	 * @return array<int,object>
	 */
	private static function ledger_rows_for_characters( object $game ): array {
		$rows = [];
		foreach ( Character::all_for_game( $game->slug ) as $character ) {
			foreach ( Change::for_character( (int) $character->id, [ 'status' => 'approved', 'order' => 'ASC' ] ) as $change ) {
				$change->character_name = $character->name;
				$rows[] = $change;
			}
		}
		return $rows;
	}

	/**
	 * Player Point History: the same ledger, grouped by the WordPress user who
	 * owns each character rather than by character - one row per approved
	 * change on any character that WordPress user is attached to.
	 *
	 * @return array<int,object>
	 */
	private static function ledger_rows_for_players( object $game ): array {
		$rows = [];
		foreach ( Character::all_for_game( $game->slug ) as $character ) {
			if ( empty( $character->wp_user_id ) ) {
				continue;
			}
			$user = get_userdata( (int) $character->wp_user_id );
			if ( ! $user ) {
				continue;
			}
			foreach ( Change::for_character( (int) ( $character->id ?? 0 ), [ 'status' => 'approved', 'order' => 'ASC' ] ) as $change ) {
				$change->character_name = $user->display_name;
				$rows[] = $change;
			}
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $filters
	 */
	private static function build_card( array $report, object $game, array $filters, bool $can_manage ): array {
		$result = Query_Engine::execute(
			$game->slug,
			(array) ( $filters['conditions'] ?? [] ),
			(string) ( $filters['logic'] ?? 'AND' ),
			[ 'per_page' => 1000, 'sort' => [ 'field' => 'name' ] ],
			$report['entity']
		);

		$cards = [];
		foreach ( $result['results'] as $row ) {
			$card = [];
			foreach ( $report['columns'] as [ $label, $key, $source ] ) {
				$card[] = [ $label, self::resolve_one( $key, $source, $row, $game, $report['entity'] ) ];
			}
			$cards[] = $card;
		}

		return [
			'title' => $report['title'],
			'shape' => 'card',
			'cards' => $cards,
			'game'  => $game->name,
		];
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $filters
	 * @param array<string,mixed> $options
	 */
	private static function build_statistics( array $report, object $game, array $filters, array $options ): array {
		$fields     = $report['statfields'] ?? ( isset( $options['stat_field'] ) ? [ $options['stat_field'] ] : [] );
		$stat_type  = $report['stattype'] ?? ( $options['stat_type'] ?? 'distribution' );
		$conditions = (array) ( $filters['conditions'] ?? [] );
		$logic      = (string) ( $filters['logic'] ?? 'AND' );

		$blocks = [];
		foreach ( $fields as $field ) {
			$stat     = Query_Engine::statistics( $game->slug, $conditions, $logic, $field, $stat_type );
			$blocks[] = [ 'label' => $field, 'buckets' => $stat['buckets'], 'total' => $stat['total'] ];
		}

		return [
			'title'  => $report['title'],
			'shape'  => 'statistics',
			'blocks' => $blocks,
			'game'   => $game->name,
		];
	}

	/**
	 * Plot Report: one narrative block per plot, its entries in date order -
	 * the one shape that is prose rather than a table.
	 */
	private static function build_narrative( array $report, object $game ): array {
		$plots  = Plot::for_game( (int) $game->id );
		$blocks = [];

		foreach ( $plots as $plot ) {
			$entries = Plot_Entry::for_plot( (int) $plot->id );
			$lines   = [];
			foreach ( $entries as $entry ) {
				$lines[] = sprintf( '%s (%s): %s', $entry->event_date ?? substr( (string) $entry->created_at, 0, 10 ), $entry->entry_type, wp_strip_all_tags( (string) $entry->content ) );
			}

			$blocks[] = [
				'title'  => $plot->title,
				'status' => $plot->status,
				'lines'  => [
					'Initiated by: ' . ( $plot->initiated_by ?? '—' ),
					(string) ( $plot->description ?? '' ),
				],
				'entries' => $lines,
			];
		}

		return [
			'title'  => $report['title'],
			'shape'  => 'narrative',
			'blocks' => $blocks,
			'game'   => $game->name,
		];
	}

	/**
	 * No real per-date schedule exists yet (reports-cards-batch-design.md §5) -
	 * always the honest empty state, never a fabricated calendar.
	 */
	private static function build_calendar( array $report, object $game ): array {
		return [
			'title' => $report['title'],
			'shape' => 'calendar',
			'rows'  => [],
			'note'  => $report['empty_note'] ?? '',
			'game'  => $game->name,
		];
	}

	/**
	 * Gathers every `description` (Decision 094 - the rich-text
	 * reference/description/source note) actually set anywhere in this
	 * chronicle's catalog, grouped by the schema block it lives on. Not
	 * entity-scoped like every report above - this reads the whole catalog
	 * (`Schema_Block::all_for_game()`, preferring a chronicle's own fork over
	 * the shared global block, same as every other consumer) rather than one
	 * row per character/plot/etc.
	 *
	 * Only `trait_list` items, `tiered_power` levels, and `tiered_power`
	 * families carry a `description` today - `resource_pool` pools and
	 * `identity_field` options only ever gained an approval schedule
	 * (Decision 095), never a note field, so they contribute nothing here by
	 * design, not by omission.
	 *
	 * @return array<string,mixed>
	 */
	private static function build_house_rules( array $report, object $game ): array {
		// all_for_game_by_types(), never all_for_game() - the latter's underlying
		// all() sorts (`ORDER BY name`), which measurably overflows MySQL's sort
		// buffer once the real catalog is this large (Schema_Block.php's own
		// docblock: the Fera/Werewolf gift blocks, mage-rotes since v0.99.17).
		// Only trait_list and tiered_power can carry a `description` at all, so
		// this is also a tighter, cheaper read than fetching every block.
		$blocks = Schema_Block::all_for_game_by_types( [ 'trait_list', 'tiered_power' ], (string) $game->slug );
		$groups = [];

		foreach ( $blocks as $block ) {
			$definition = $block->definition;
			$entries    = [];

			if ( $block->section_type === 'trait_list' ) {
				// Cast to array: $definition decodes as nested stdClass (a generic
				// `object` PHPStan can't know the shape of), and a plain array read
				// avoids the "access to an undefined property" false positive.
				foreach ( $definition->items ?? [] as $raw_item ) {
					$item = (array) $raw_item;
					if ( ! empty( $item['description'] ) ) {
						$entries[] = [ 'name' => $item['name'], 'sections' => (array) $item['description'] ];
					}
				}
			} elseif ( $block->section_type === 'tiered_power' ) {
				foreach ( $definition->powers ?? [] as $raw_power ) {
					$power = (array) $raw_power;
					if ( ! empty( $power['description'] ) ) {
						$entries[] = [ 'name' => $power['name'], 'sections' => (array) $power['description'] ];
					}
					foreach ( $power['levels'] ?? [] as $raw_level ) {
						$level = (array) $raw_level;
						if ( ! empty( $level['description'] ) ) {
							$label     = $power['name'] . ' — ' . ( $level['power_name'] ?? sprintf( 'Level %s', $level['level'] ?? '' ) );
							$entries[] = [ 'name' => $label, 'sections' => (array) $level['description'] ];
						}
					}
				}
			}

			if ( $entries !== [] ) {
				$groups[] = [ 'block_name' => $block->name, 'entries' => $entries ];
			}
		}

		return [
			'title'  => $report['title'],
			'shape'  => 'house_rules',
			'groups' => $groups,
			'game'   => $game->name,
		];
	}

	/**
	 * @return array<int,object> Synthetic rows: {wp_user_id, role, user}.
	 */
	private static function player_rows( object $game ): array {
		$members = Game_Member::for_game( (int) $game->id );
		$rows    = [];
		foreach ( $members as $member ) {
			$user = get_userdata( (int) $member->wp_user_id );
			if ( ! $user ) {
				continue;
			}
			$rows[] = (object) [
				'wp_user_id' => (int) $member->wp_user_id,
				'role'       => $member->role,
				'user'       => $user,
			];
		}
		return $rows;
	}

	/**
	 * A plot-entity row for the table shape is either a real `Plot_Entry`
	 * (`entry_type: 'action'`) or a rumor-tagged `Plot` itself
	 * (`entry_type: 'rumor'` - not a real Plot_Entry value, see the
	 * registry's own comment). `Plot Report` (narrative) never reaches here.
	 *
	 * @return array<int,object>
	 */
	private static function plot_rows( object $game, array $report ): array {
		$wanted = (array) ( $report['entry_type'] ?? [] );
		$rows   = [];

		if ( in_array( 'action', $wanted, true ) ) {
			foreach ( Plot::for_game( (int) $game->id ) as $plot ) {
				foreach ( Plot_Entry::for_plot( (int) $plot->id, [ 'entry_type' => 'action' ] ) as $entry ) {
					$response = self::matching_response( (int) $plot->id, $entry );
					$rows[]   = (object) [
						'kind'     => 'action',
						'plot'     => $plot,
						'entry'    => $entry,
						'response' => $response,
					];
				}
			}
		}

		if ( in_array( 'rumor', $wanted, true ) ) {
			foreach ( self::rumor_tagged_plots( $game ) as $plot ) {
				$rows[] = (object) [ 'kind' => 'rumor', 'plot' => $plot, 'entry' => null, 'response' => null ];
			}
		}

		return $rows;
	}

	/**
	 * The `response` entry on the same plot posted after the given `action`
	 * entry - the ST's own answer to that action, if one exists yet.
	 */
	private static function matching_response( int $plot_id, object $action_entry ): ?object {
		foreach ( Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'response' ] ) as $response ) {
			if ( strtotime( (string) $response->created_at ) >= strtotime( (string) $action_entry->created_at ) ) {
				return $response;
			}
		}
		return null;
	}

	/**
	 * @return array<int,object>
	 */
	private static function rumor_tagged_plots( object $game ): array {
		$tags = Connection::for_game( (int) $game->id, [ 'target_type' => 'tag' ] );
		$ids  = [];
		foreach ( $tags as $tag ) {
			if ( ( $tag->label ?? '' ) === Rumor_Generator::RUMOR_LABEL && ( $tag->source_type ?? '' ) === 'plot' ) {
				$ids[] = (int) $tag->source_id;
			}
		}

		$plots = [];
		foreach ( array_unique( $ids ) as $plot_id ) {
			$plot = Plot::find( $plot_id );
			if ( $plot !== null ) {
				$plots[] = $plot;
			}
		}
		return $plots;
	}

	/**
	 * @param array<int,array{0:string,1:string,2:string}> $columns
	 * @return string[]
	 */
	private static function resolve_columns( array $columns, object $row, object $game, string $entity ): array {
		$out = [];
		foreach ( $columns as [ $label, $key, $source ] ) {
			$out[] = self::resolve_one( $key, $source, $row, $game, $entity );
		}
		return $out;
	}

	private static function resolve_one( string $key, string $source, object $row, object $game, string $entity ): string {
		switch ( $source ) {
			case 'field':
				$resolved = Query_Engine::resolve_value( $row, $key, $entity );
				return self::format_field_value( $resolved );

			case 'special':
				return self::resolve_special( $key, $row, $game );

			case 'ledger':
				return self::resolve_ledger( $key, $row );

			case 'player':
				return self::resolve_player( $key, $row );

			case 'plot':
				return self::resolve_plot( $key, $row );

			case 'unmapped':
			default:
				return '—';
		}
	}

	/**
	 * @param array{type:string,value:mixed,atomic:bool} $resolved
	 */
	private static function format_field_value( array $resolved ): string {
		$value = $resolved['value'];
		if ( $value === null || $value === '' ) {
			return '—';
		}
		if ( is_array( $value ) ) {
			if ( $resolved['atomic'] ) {
				return implode( ', ', array_map( static fn( $t ) => (string) ( $t['name'] ?? $t ), $value ) );
			}
			return implode( ', ', array_map(
				static fn( $t ) => is_array( $t ) ? ( ( $t['name'] ?? '' ) . ( isset( $t['count'] ) ? ' x' . $t['count'] : '' ) ) : (string) $t,
				$value
			) );
		}
		return (string) $value;
	}

	private static function resolve_special( string $key, object $row, object $game ): string {
		switch ( $key ) {
			case 'title':
			case 'gametitle':
				return (string) $game->name;
			case 'printdate':
				return current_time( 'Y-m-d' );
			case 'charname':
				return (string) ( $row->name ?? '—' );
			case 'matchvalue':
				return (string) ( $row->match_value ?? '—' );
			case 'sortvalue':
				return (string) ( $row->sort_value ?? $row->name ?? '—' );
			default:
				return '—';
		}
	}

	/**
	 * Experience History / Player Point History: one row per approved
	 * change - reads through `resolve_columns()` once per `Change` row
	 * rather than per character, so the caller (`build_table()`) supplies
	 * `Change` rows directly for these two report keys (see `for_ledger_rows()`).
	 */
	private static function resolve_ledger( string $key, object $row ): string {
		$change_data = is_object( $row->change_data ?? null ) ? (array) $row->change_data : (array) ( $row->change_data ?? [] );

		switch ( $key ) {
			case 'name':
				return (string) ( $row->character_name ?? '—' );
			case 'date':
				return substr( (string) ( $row->submitted_at ?? '' ), 0, 10 );
			case 'changetext':
				return Change_Description::describe( (string) ( $row->change_type ?? '' ), $change_data );
			case 'reason':
				return (string) ( $change_data['reason'] ?? '—' );
			case 'earned':
			case 'ppearned':
				return (string) max( 0.0, (float) ( $row->xp_cost ?? 0 ) );
			case 'unspent':
			case 'ppunspent':
				return (string) max( 0.0, -1 * (float) ( $row->xp_cost ?? 0 ) );
			default:
				return '—';
		}
	}

	private static function resolve_player( string $key, object $row ): string {
		$user = $row->user;
		switch ( $key ) {
			case 'name':
				return (string) $user->display_name;
			case 'email':
				return (string) $user->user_email;
			case 'position':
				return (string) $row->role;
			default:
				return '—';
		}
	}

	private static function resolve_plot( string $key, object $row ): string {
		$plot = $row->plot;
		if ( $row->kind === 'rumor' ) {
			switch ( $key ) {
				case 'date':
					return (string) ( $plot->game_date ?? substr( (string) $plot->created_at, 0, 10 ) );
				case 'rumor':
					return (string) ( $plot->description ?? $plot->title ?? '—' );
				case 'type':
					return 'Rumor';
				default:
					return '—';
			}
		}

		$entry = $row->entry;
		switch ( $key ) {
			case 'date':
				return (string) ( $entry->event_date ?? substr( (string) $entry->created_at, 0, 10 ) );
			case 'name':
				$character = \BeyondElysium\Models\Character::find( (int) $entry->author_id );
				return $character !== null ? (string) $character->name : '—';
			case 'type':
				return 'Action';
			case 'action':
				return wp_strip_all_tags( (string) $entry->content );
			case 'result':
				return $row->response !== null ? wp_strip_all_tags( (string) $row->response->content ) : '—';
			default:
				return '—';
		}
	}
}
