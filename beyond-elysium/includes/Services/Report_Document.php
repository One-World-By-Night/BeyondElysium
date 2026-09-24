<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Item_Attestation;
use BeyondElysium\Models\Location_Link;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Display\Change_Description;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves one of the 19 `report-registry.php` entries down to a plain, presentation-neutral document.
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
				return self::build_calendar( $report, $game, $can_manage );
			case 'house_rules':
				return self::build_house_rules( $report, $game );
			default:
				return null;
		}
	}

	/**
	 * The capability a caller needs to run a report in a chronicle, or null when every member may.
	 *
	 * @param array<string,mixed> $report A registry row.
	 */
	public static function required_capability( array $report ): ?string {
		return array_key_exists( 'capability', $report ) ? $report['capability'] : 'be_manage_characters';
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
	 * Experience History: one synthetic row per approved `Change`, decorated with the owning character's name and the
	 * totals after that change.
	 *
	 * @return array<int,object>
	 */
	private static function ledger_rows_for_characters( object $game ): array {
		$rows = [];
		foreach ( Character::all_for_game( $game->slug ) as $character ) {
			$changes = Change::for_character( (int) $character->id, [ 'status' => 'approved', 'order' => 'ASC' ] );
			array_push( $rows, ...self::with_totals_after( $changes, (string) $character->name, (int) $character->xp_earned, (int) $character->xp_unspent ) );
		}
		return $rows;
	}

	/**
	 * Player Point History: the same ledger, grouped by the WordPress user who owns each character.
	 *
	 * @return array<int,object>
	 */
	private static function ledger_rows_for_players( object $game ): array {
		$players = [];
		foreach ( Character::all_for_game( $game->slug ) as $character ) {
			if ( empty( $character->wp_user_id ) ) {
				continue;
			}
			$user = get_userdata( (int) $character->wp_user_id );
			if ( ! $user ) {
				continue;
			}
			$user_id = (int) $user->ID;
			if ( ! isset( $players[ $user_id ] ) ) {
				$players[ $user_id ] = [ 'name' => (string) $user->display_name, 'earned' => 0, 'unspent' => 0, 'changes' => [] ];
			}
			$players[ $user_id ]['earned']  += (int) ( $character->xp_earned ?? 0 );
			$players[ $user_id ]['unspent'] += (int) ( $character->xp_unspent ?? 0 );
			$players[ $user_id ]['changes']  = array_merge(
				$players[ $user_id ]['changes'],
				Change::for_character( (int) ( $character->id ?? 0 ), [ 'status' => 'approved', 'order' => 'ASC' ] )
			);
		}

		$rows = [];
		foreach ( $players as $player ) {
			usort( $player['changes'], static fn( $a, $b ) => [ (string) $a->submitted_at, (int) $a->id ] <=> [ (string) $b->submitted_at, (int) $b->id ] );
			array_push( $rows, ...self::with_totals_after( $player['changes'], $player['name'], $player['earned'], $player['unspent'] ) );
		}
		return $rows;
	}

	/**
	 * Stamps each change, in order, with the earned and unspent totals after it.
	 *
	 * @param array<int,object> $changes Approved changes, oldest first.
	 * @return array<int,object>
	 */
	private static function with_totals_after( array $changes, string $name, int $earned, int $unspent ): array {
		foreach ( array_reverse( $changes ) as $change ) {
			$change->character_name = $name;
			$change->earned_after   = $earned;
			$change->unspent_after  = $unspent;

			$change_data = is_object( $change->change_data ?? null ) ? (array) $change->change_data : (array) ( $change->change_data ?? [] );
			$delta       = Change_Description::xp_delta( (string) $change->change_type, $change_data, (float) ( $change->xp_cost ?? 0 ) );
			$unspent    -= $delta;
			if ( in_array( $change->change_type, [ 'xp_earn', 'xp_adjust' ], true ) ) {
				$earned -= $delta;
			}
		}
		return $changes;
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $filters
	 */
	private static function build_card( array $report, object $game, array $filters, bool $can_manage ): array {
		// Rote Cards, built from the character's held rotes in sheet_data.
		if ( ! empty( $report['holder_block'] ) && ! empty( $filters['character_id'] ) ) {
			return self::build_holder_block_card( $report, $game, (int) $filters['character_id'] );
		}

		// Rows are redacted before conditions run.
		$options = $can_manage ? [] : [
			'prepare_row' => static function ( $row ) use ( $game ): void {
				St_Visibility::filter_world_object( $row, $game, false );
			},
		];
		$result  = Query_Engine::execute(
			$game->slug,
			(array) ( $filters['conditions'] ?? [] ),
			(string) ( $filters['logic'] ?? 'AND' ),
			[ 'per_page' => 1000, 'sort' => [ 'field' => 'name' ] ],
			$report['entity'],
			$options
		);

		$rows = $result['results'];

		if ( ! empty( $filters['character_id'] ) ) {
			// Print My Items, or a location a character knows of: the connection to the character is the authorization.
			$rows = self::filter_rows_connected_to_character( $rows, (int) $filters['character_id'] );
		} elseif ( ! $can_manage ) {
			// The general catalog view: a non-Storyteller sees only what their own characters' audience reaches.
			$entity_type = [ 'item' => 'item', 'loc' => 'location' ][ $report['entity'] ] ?? null;
			if ( $entity_type !== null ) {
				$rows = Audience::filter( $rows, $entity_type, get_current_user_id(), $game->slug, false );
			}
		}

		$cards = [];
		foreach ( $rows as $row ) {
			$card = [];
			foreach ( $report['columns'] as [ $label, $key, $source ] ) {
				$card[] = [ $label, self::resolve_one( $key, $source, $row, $game, $report['entity'] ) ];
			}
			// Verifiable item cards - only item-cards, the one card report an item can appear.
			if ( $report['entity'] === 'item' ) {
				$card[] = [ 'Verify', self::verify_line_for_item( $row, $game ) ];
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
	 * Builds Rote Cards for one character's own held rotes.
	 *
	 * @param array<string,mixed> $report
	 * @param object              $game
	 * @param int                 $character_id
	 * @return array<string,mixed>
	 */
	private static function build_holder_block_card( array $report, object $game, int $character_id ): array {
		$holder_block = (string) $report['holder_block'];
		$character    = Character::find( $character_id );
		$held         = $character !== null && is_array( $character->sheet_data[ $holder_block ] ?? null )
			? $character->sheet_data[ $holder_block ]
			: [];

		$rote_objects_by_key = [];
		foreach ( World_Object::for_game( (int) $game->id, [ 'object_type' => 'rote', 'per_page' => 1000 ] ) as $object ) {
			$rote_objects_by_key[ Name_Key::for( (string) $object->name ) ] = $object;
		}

		$catalog_items_by_key = [];
		$block = Schema_Block::find_by_slugs_for_game( [ $holder_block ], $game->slug )[ $holder_block ] ?? null;
		if ( $block && is_array( $block->definition->items ?? null ) ) {
			foreach ( $block->definition->items as $item ) {
				$catalog_items_by_key[ Name_Key::for( (string) ( $item->name ?? '' ) ) ] = $item;
			}
		}

		$cards = [];
		foreach ( $held as $entry ) {
			$name = (string) ( $entry['name'] ?? '' );
			if ( $name === '' ) {
				continue;
			}
			$key = Name_Key::for( $name );

			if ( isset( $rote_objects_by_key[ $key ] ) ) {
				$row  = $rote_objects_by_key[ $key ];
				$card = [];
				foreach ( $report['columns'] as [ $label, $col_key, $source ] ) {
					$card[] = [ $label, self::resolve_one( $col_key, $source, $row, $game, $report['entity'] ) ];
				}
				$cards[] = $card;
				continue;
			}

			$catalog_item = $catalog_items_by_key[ $key ] ?? null;
			$cards[]      = [
				[ 'Name', $name ],
				[ 'Note', (string) ( $catalog_item->note ?? '' ) ],
				[ 'Source', (string) ( $catalog_item->source ?? '' ) ],
			];
		}

		return [
			'title' => $report['title'],
			'shape' => 'card',
			'cards' => $cards,
			'game'  => $game->name,
		];
	}

	/**
	 * Issues (or reuses) a verification code for one printed item card and returns the line printed on the card itself.
	 *
	 * @param object $row  A decoded world_object row (already redacted for this viewer).
	 * @param object $game
	 * @return string
	 */
	private static function verify_line_for_item( object $row, object $game ): string {
		$holder = null;
		foreach ( Connection::for_entity( 'world_object', (int) $row->id ) as $connection ) {
			$character_id = $connection->source_type === 'character' ? $connection->source_id
				: ( $connection->target_type === 'character' ? $connection->target_id : null );
			if ( $character_id !== null ) {
				$holder = Character::find( (int) $character_id );
				break;
			}
		}

		$attestation = Item_Attestation::issue_or_reuse( $row, $holder, $game->slug, (string) $game->name );
		return 'Verify: ' . home_url( '/be-verify/?code=' . rawurlencode( $attestation->short_code ) );
	}

	/**
	 * Narrows an already-fetched card-report row set to only those connected to the given character.
	 *
	 * @param array<int,object> $rows
	 * @return array<int,object>
	 */
	private static function filter_rows_connected_to_character( array $rows, int $character_id ): array {
		$connected_ids = [];
		foreach ( Connection::for_source( 'character', $character_id ) as $connection ) {
			if ( ( $connection->target_type ?? '' ) === 'world_object' ) {
				$connected_ids[ (int) $connection->target_id ] = true;
			}
		}

		return array_values( array_filter( $rows, static function ( $row ) use ( $connected_ids ) {
			return isset( $connected_ids[ (int) ( $row->id ?? 0 ) ] );
		} ) );
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
	 * Plot Report: one narrative block per plot, its entries in date order.
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
	 * The chronicle's own game sessions.
	 */
	private static function build_calendar( array $report, object $game, bool $can_manage ): array {
		$sessions = Game_Session::for_game( (int) $game->id );
		$rows     = array_map( static function ( $session ) use ( $game, $can_manage ) {
			St_Visibility::filter_session( $session, $game, $can_manage );
			return [
				'date'  => $session->game_date,
				'time'  => $session->start_time,
				'place' => $session->place,
				'notes' => $session->notes,
			];
		}, $sessions );

		return [
			'title' => $report['title'],
			'shape' => 'calendar',
			'rows'  => $rows,
			'note'  => empty( $rows ) ? ( $report['empty_note'] ?? '' ) : '',
			'game'  => $game->name,
		];
	}

	/**
	 * Gathers every `description` (the rich-text reference/description/source note) actually set anywhere in this
	 * chronicle's catalog, grouped by the schema block it lives on.
	 *
	 * @return array<string,mixed>
	 */
	private static function build_house_rules( array $report, object $game ): array {
		$blocks = Schema_Block::all_for_game_by_types( [ 'trait_list', 'tiered_power' ], (string) $game->slug );
		$groups = [];

		foreach ( $blocks as $block ) {
			$definition = $block->definition;
			$entries    = [];

			if ( $block->section_type === 'trait_list' ) {
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
	 * A plot-entity row for the table shape: either one action line (see `action_rows()`) or a rumor-tagged `Plot`
	 * itself.
	 *
	 * @return array<int,object>
	 */
	private static function plot_rows( object $game, array $report ): array {
		$wanted = (array) ( $report['entry_type'] ?? [] );
		$rows   = [];

		if ( in_array( 'action', $wanted, true ) ) {
			foreach ( Plot::for_game( (int) $game->id ) as $plot ) {
				array_push( $rows, ...self::action_rows( $plot, $game ) );
			}
		}

		if ( in_array( 'rumor', $wanted, true ) ) {
			foreach ( self::rumor_tagged_plots( $game ) as $plot ) {
				$rows[] = (object) [ 'kind' => 'rumor', 'plot' => $plot ];
			}
		}

		return $rows;
	}

	/**
	 * One plot's action lines, each already resolved to the values its columns print.
	 *
	 * @return array<int,object>
	 */
	private static function action_rows( object $plot, object $game ): array {
		$plot_id   = (int) $plot->id;
		$plot_date = (string) ( $plot->game_date ?? substr( (string) $plot->created_at, 0, 10 ) );
		$actor_id  = Action_Allocator::actor_character_id( $plot_id );
		$actor     = $actor_id !== null ? Character::find( $actor_id ) : null;
		$rows      = [];

		if ( $actor_id !== null ) {
			$actor_name   = $actor !== null ? (string) $actor->name : '—';
			$uses         = Background_Ledger::entries_for_plot( $plot_id );
			$uses_by_name = [];
			foreach ( $uses as $use ) {
				$uses_by_name[ (string) ( $use['name'] ?? '' ) ][] = $use;
			}

			$budgets = Background_Ledger::apply_spends( array_values( Action_Allocator::subactions_for_plot( $plot_id ) ), $uses )['subactions'];
			foreach ( $budgets as $budget ) {
				foreach ( $uses_by_name[ $budget['name'] ] ?? [ null ] as $use ) {
					$rows[] = self::action_line( $plot_date, $actor_name, $budget['name'], $use, $budget );
				}
				unset( $uses_by_name[ $budget['name'] ] );
			}
			// A use whose budget line is gone (re-allocated away) still happened.
			foreach ( $uses_by_name as $name => $unbudgeted ) {
				foreach ( $unbudgeted as $use ) {
					$rows[] = self::action_line( $plot_date, $actor_name, (string) $name, $use, null );
				}
			}
		}

		return array_merge( $rows, self::post_rows( $plot, $game, $actor ) );
	}

	/**
	 * One allocation-plot line: a Background use with its budget line, a budget line nobody has used yet, or a use whose
	 * budget line is gone.
	 *
	 * @param array<string,mixed>|null $use    A decoded Background_Ledger entry, or null for a budget line nobody has used.
	 * @param array<string,mixed>|null $budget The budget line after spends, or null for a use with none.
	 */
	private static function action_line( string $date, string $character, string $type, ?array $use, ?array $budget ): object {
		return (object) [
			'kind'   => 'action',
			'date'   => $date,
			'name'   => $character,
			'type'   => $type,
			'action' => $use !== null ? (string) ( $use['text'] ?? '' ) : '',
			'result' => $use !== null ? (string) ( $use['result'] ?? '' ) : '',
			'total'  => $budget !== null ? (string) (int) $budget['total'] : '',
			'growth' => $budget !== null ? (string) (int) $budget['growth'] : '',
			'unused' => $budget !== null ? (string) (int) $budget['unused'] : '',
		];
	}

	/**
	 * A plot's free-text action posts, in thread order.
	 *
	 * @return array<int,object>
	 */
	private static function post_rows( object $plot, object $game, ?object $actor ): array {
		$rows    = [];
		$waiting = [];
		$names   = [];
		$posters = $actor === null ? self::plot_characters_by_user( $plot, $game ) : [];

		foreach ( Plot_Entry::for_plot( (int) $plot->id ) as $entry ) {
			if ( $entry->entry_type === 'action' && ! self::is_apr_entry( (string) $entry->content ) ) {
				$author = (int) $entry->author_id;
				if ( ! isset( $names[ $author ] ) ) {
					$names[ $author ] = $actor !== null ? (string) $actor->name : self::poster_character_name( $author, $posters, $game );
				}
				$line = (object) [
					'kind'   => 'action',
					'date'   => (string) ( $entry->event_date ?? substr( (string) $entry->created_at, 0, 10 ) ),
					'name'   => $names[ $author ],
					'type'   => 'Action',
					'action' => wp_strip_all_tags( (string) $entry->content ),
					'result' => '',
					'total'  => '',
					'growth' => '',
					'unused' => '',
				];
				$rows[]    = $line;
				$waiting[] = [ 'who' => $actor !== null ? 'actor' : $author, 'line' => $line ];
				continue;
			}

			if ( $entry->entry_type !== 'response' || $waiting === [] ) {
				continue;
			}

			$one_voice = count( array_unique( array_column( $waiting, 'who' ) ) ) === 1;
			foreach ( $waiting as $post ) {
				$post['line']->result = $one_voice
					? wp_strip_all_tags( (string) $entry->content )
					/* translators: %s: plot title */
					: sprintf( __( 'Reply came after several actions, see "%s"', 'beyond-elysium' ), (string) $plot->title );
			}
			$waiting = [];
		}

		return $rows;
	}

	/**
	 * The chronicle's player characters connected to a plot, in either direction, grouped by the WordPress user who owns
	 * each.
	 *
	 * @return array<int,string[]>
	 */
	private static function plot_characters_by_user( object $plot, object $game ): array {
		$by_user = [];
		foreach ( Connection::for_entity( 'plot', (int) $plot->id ) as $connection ) {
			if ( $connection->source_type === 'plot' && $connection->target_type === 'character' ) {
				$character_id = (int) $connection->target_id;
			} elseif ( $connection->target_type === 'plot' && $connection->source_type === 'character' ) {
				$character_id = (int) $connection->source_id;
			} else {
				continue;
			}
			$character = Character::find( $character_id );
			if ( $character !== null && (int) $character->wp_user_id > 0 && $character->owner_slug === $game->slug ) {
				$by_user[ (int) $character->wp_user_id ][ $character_id ] = (string) $character->name;
			}
		}
		return array_map( 'array_values', $by_user );
	}

	/**
	 * The character a post's author acted as: their characters connected to the plot, else their one active character in
	 * this chronicle, else a dash.
	 *
	 * @param array<int,string[]> $posters
	 */
	private static function poster_character_name( int $author_id, array $posters, object $game ): string {
		if ( ! empty( $posters[ $author_id ] ) ) {
			return implode( ', ', $posters[ $author_id ] );
		}
		if ( $author_id <= 0 ) {
			return '—';
		}
		$active = Character::all_for_game( (string) $game->slug, [ 'wp_user_id' => $author_id, 'status' => 'active', 'per_page' => 2 ] );
		return count( $active ) === 1 ? (string) $active[0]->name : '—';
	}

	/**
	 * Whether an action entry is an allocator budget line or a Background use: stored JSON the Action & Rumor system
	 * reads.
	 */
	private static function is_apr_entry( string $content ): bool {
		$data = json_decode( $content, true );
		return is_array( $data ) && in_array( $data['source'] ?? '', [ 'allocator', 'ledger' ], true );
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
		// A location's Owner and Where columns prefer a real link or parent name over the typed text.
		if ( $entity === 'loc' && $source === 'field' && in_array( $key, [ 'owner', 'where' ], true ) ) {
			$display = Location_Link::resolve_owner_and_where( $row );
			return $display[ $key ] !== '' ? $display[ $key ] : '—';
		}

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

			case 'boons':
				return self::resolve_boons( $row );

			case 'equipment':
				return self::resolve_equipment( $row );

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
				// What the query engine records for each match.
				return (string) ( ( $row->match_reason ?? '' ) !== '' ? $row->match_reason : '—' );
			case 'sortvalue':
				return (string) ( $row->sort_value ?? $row->name ?? '—' );
			// Item Cards' uses-left and Expired columns, read from the row's own properties.
			case 'usesleft':
				$uses_max = ( $row->properties['uses_max'] ?? null );
				if ( $uses_max === null || $uses_max === '' ) {
					return '—';
				}
				return World_Object::is_used_up( $row ) ? __( 'Used up', 'beyond-elysium' ) : (string) ( $row->properties['uses_left'] ?? 0 );
			case 'expireson':
				$expires_on = ( $row->properties['expires_on'] ?? null );
				if ( $expires_on === null || $expires_on === '' ) {
					return '—';
				}
				return World_Object::is_expired( $row ) ? __( 'Expired', 'beyond-elysium' ) : (string) $expires_on;
			default:
				return '—';
		}
	}

	/**
	 * Experience History / Player Point History: one row per approved change.
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
				// An award's reason lives in its change data; any other change's in its own reason or the submitter's note.
				foreach ( [ $change_data['reason'] ?? null, $row->reason ?? null, $row->notes ?? null ] as $reason ) {
					if ( trim( (string) $reason ) !== '' ) {
						return (string) $reason;
					}
				}
				return '—';
			case 'earned':
			case 'ppearned':
				return (string) (int) ( $row->earned_after ?? 0 );
			case 'unspent':
			case 'ppunspent':
				return (string) (int) ( $row->unspent_after ?? 0 );
			default:
				return '—';
		}
	}

	/**
	 * A character's outstanding boons, each naming the other party, as Grapevine's Vampire Status Report lists them.
	 */
	private static function resolve_boons( object $row ): string {
		$lines = [];
		foreach ( Connection::for_target( 'character', (int) $row->id ) as $connection ) {
			if ( $connection->source_type !== 'world_object' || ! in_array( $connection->label, [ 'owed_by', 'owed_to' ], true ) ) {
				continue;
			}
			$boon = World_Object::find( (int) $connection->source_id );
			if ( ! $boon || $boon->object_type !== 'boon' || ( $boon->properties['status'] ?? '' ) === 'repaid' ) {
				continue;
			}

			$other_label = $connection->label === 'owed_by' ? 'owed_to' : 'owed_by';
			$other       = '—';
			foreach ( Connection::for_source( 'world_object', (int) $boon->id ) as $party ) {
				if ( $party->label === $other_label && $party->target_type === 'character' ) {
					$other = (string) ( Character::find( (int) $party->target_id )->name ?? '—' );
				}
			}
			$level   = (string) ( $boon->properties['boon_level'] ?? '' );
			$lines[] = $connection->label === 'owed_by'
				/* translators: 1: character owed the boon, 2: boon level */
				? sprintf( __( 'Owes %1$s (%2$s)', 'beyond-elysium' ), $other, $level )
				/* translators: 1: character who owes the boon, 2: boon level */
				: sprintf( __( 'Owed by %1$s (%2$s)', 'beyond-elysium' ), $other, $level );
		}
		sort( $lines );
		return $lines !== [] ? implode( '; ', $lines ) : '—';
	}

	/**
	 * The items a character holds.
	 */
	private static function resolve_equipment( object $row ): string {
		$names = [];
		foreach ( Connection::for_source( 'character', (int) $row->id ) as $connection ) {
			if ( $connection->target_type !== 'world_object' ) {
				continue;
			}
			$object = World_Object::find( (int) $connection->target_id );
			if ( $object && $object->object_type === 'item' ) {
				$names[] = (string) $object->name;
			}
		}
		sort( $names );
		return $names !== [] ? implode( ', ', $names ) : '—';
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
		if ( $row->kind === 'rumor' ) {
			$plot = $row->plot;
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

		// An action line arrives already resolved (action_rows()); a column it does not carry prints a dash.
		$value = in_array( $key, [ 'date', 'name', 'type', 'action', 'result', 'total', 'growth', 'unused' ], true )
			? (string) $row->{$key}
			: '';
		return $value !== '' ? $value : '—';
	}
}
