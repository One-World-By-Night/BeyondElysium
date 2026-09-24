<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Faction_Member;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Position;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Port of `QueryEngineClass.ProcessClause` (GV301Source/Code/QueryEngineClass.cls),
 * evaluating ST query conditions against character data.
 *
 * SQL narrows candidate characters by whatever indexed columns are ANDed into
 * the query; every condition itself is then evaluated in PHP against decoded
 * `sheet_data` rather than expressed as MySQL JSON functions, since the eleven
 * trait-list operators do not reduce to a single clean SQL expression.
 *
 * @see BE_PROCESS/releases/workflow-0.6.md
 * @see BE_PROCESS/reference/GV-SOURCEMAP.md "Query Engine"
 */
class Query_Engine {

	/** Applicable operators per `QueryKeyType`, from `QueryEngineClass.ProcessClause`. */
	const APPLICABLE_OPERATORS = [
		'field' => [ 'contains', 'equals' ],
		'num'   => [ 'equals', 'at_least', 'greater', 'less', 'no_more' ],
		'date'  => [ 'equals', 'at_least', 'greater', 'less', 'no_more' ],
		'bool'  => [ 'is_true', 'is_false' ],
		'list'  => [
			'contains', 'contains_note',
			'contains_exactly', 'contains_at_least', 'contains_more', 'contains_less', 'contains_no_more',
			'totals', 'totals_at_least', 'totals_more', 'totals_no_more', 'totals_less',
		],
	];

	/** The five "count comparison" trait operators - the atomic-duplicate-walk applies only to these. */
	const NAMED_COUNT_OPERATORS = [ 'contains_no_more', 'contains_less', 'contains_exactly', 'contains_at_least', 'contains_more' ];

	/**
	 * Checks whether an operator applies to a given field type. Used by the
	 * query builder UI to avoid ever offering an operator the server would
	 * reject, and by validate_conditions() to check submitted queries.
	 *
	 * @param string $type
	 * @param string $operator
	 * @return bool
	 */
	public static function is_applicable( string $type, string $operator ): bool {
		return in_array( $operator, self::APPLICABLE_OPERATORS[ $type ] ?? [], true );
	}

	/**
	 * Evaluate one condition against one already-resolved value. Pure - no database
	 * access - port of `ProcessClause`'s per-row, per-clause body.
	 *
	 * `$value` shape depends on `$type`:
	 *   - field/num/date: scalar or null
	 *   - bool: bool or null
	 *   - list: array of `{name, count, note?}` entries, or null if the character has
	 *     no such block at all (an empty array is a real empty list, not null)
	 *
	 * @param string     $type      One of: field, num, date, bool, list.
	 * @param mixed      $value     The character's value for this field.
	 * @param array      $condition `{operator, find?, value?, not?}`.
	 * @param bool       $atomic    Whether the trait list is atomic (Decision 016/GV-SOURCEMAP "Atomic").
	 * @return array{match: bool, match_value: string}
	 */
	public static function evaluate_clause( string $type, $value, array $condition, bool $atomic = false ): array {
		$operator = $condition['operator'];
		$not      = ! empty( $condition['not'] );

		// A null value is inapplicable, not false; the match value becomes the literal string "N/A".
		if ( $value === null ) {
			return self::finish( false, false, $not, 'N/A' );
		}

		switch ( $type ) {
			case 'field':
				return self::evaluate_field( $value, $operator, $condition, $not );
			case 'num':
				return self::evaluate_num( $value, $operator, $condition, $not );
			case 'date':
				return self::evaluate_date( $value, $operator, $condition, $not );
			case 'bool':
				return self::evaluate_bool( $value, $operator, $not );
			case 'list':
				return self::evaluate_list( $value, $operator, $condition, $not, $atomic );
			default:
				return self::finish( false, false, $not, (string) $value );
		}
	}

	/**
	 * Evaluates a `field`-type condition (a plain string value) against the
	 * `contains` or `equals` operator. Any other operator is inapplicable for
	 * this type and always resolves to a non-match.
	 */
	private static function evaluate_field( string $value, string $operator, array $condition, bool $not ): array {
		$find = (string) ( $condition['find'] ?? '' );
		switch ( $operator ) {
			case 'contains':
				return self::finish( true, mb_stripos( $value, $find ) !== false, $not, $value );
			case 'equals':
				return self::finish( true, strcasecmp( $value, $find ) === 0, $not, $value );
			default:
				// An inapplicable operator is a non-match regardless of `not`.
				return self::finish( false, false, $not, $value );
		}
	}

	/**
	 * Evaluates a `num`-type condition (a numeric value) against one of the
	 * five numeric comparison operators: equals, at_least, greater, less, or
	 * no_more. Any other operator always resolves to a non-match.
	 */
	private static function evaluate_num( $value, string $operator, array $condition, bool $not ): array {
		$number = (float) ( $condition['value'] ?? 0 );
		$v      = (float) $value;
		switch ( $operator ) {
			case 'equals':
				return self::finish( true, $v === $number, $not, (string) $value );
			case 'at_least':
				return self::finish( true, $v >= $number, $not, (string) $value );
			case 'greater':
				return self::finish( true, $v > $number, $not, (string) $value );
			case 'less':
				return self::finish( true, $v < $number, $not, (string) $value );
			case 'no_more':
				return self::finish( true, $v <= $number, $not, (string) $value );
			default:
				return self::finish( false, false, $not, (string) $value );
		}
	}

	/**
	 * Evaluates a `date`-type condition against one of the five comparison
	 * operators: equals, at_least, greater, less, or no_more. An unparsable
	 * comparison date makes the whole clause inapplicable rather than an error.
	 */
	private static function evaluate_date( string $value, string $operator, array $condition, bool $not ): array {
		$find = (string) ( $condition['find'] ?? '' );
		if ( strtotime( $find ) === false ) {
			// An unparsable comparison date makes the whole clause inapplicable, not an error.
			return self::finish( false, false, $not, $value );
		}
		$v         = strtotime( $value );
		$compareTo = strtotime( $find );
		switch ( $operator ) {
			case 'equals':
				return self::finish( true, $v === $compareTo, $not, $value );
			case 'at_least':
				return self::finish( true, $v >= $compareTo, $not, $value );
			case 'greater':
				return self::finish( true, $v > $compareTo, $not, $value );
			case 'less':
				return self::finish( true, $v < $compareTo, $not, $value );
			case 'no_more':
				return self::finish( true, $v <= $compareTo, $not, $value );
			default:
				return self::finish( false, false, $not, $value );
		}
	}

	/**
	 * Evaluates a `bool`-type condition against the `is_true` or `is_false`
	 * operator. Any other operator is inapplicable for this type and always
	 * resolves to a non-match.
	 */
	private static function evaluate_bool( $value, string $operator, bool $not ): array {
		$v = (bool) $value;
		switch ( $operator ) {
			case 'is_true':
				return self::finish( true, $v === true, $not, $v ? '1' : '0' );
			case 'is_false':
				return self::finish( true, $v === false, $not, $v ? '1' : '0' );
			default:
				return self::finish( false, false, $not, $v ? '1' : '0' );
		}
	}

	/**
	 * Evaluates a `list`-type condition against one of the trait-list
	 * operators: the five `totals*` operators compare the list's own length,
	 * `contains`/`contains_note` search by name or note text, and the five
	 * `contains_*` count-comparison operators dispatch to evaluate_named_count().
	 *
	 * @param array  $list  `{name, count, note?}` entries.
	 * @param string $operator
	 * @param array  $condition
	 * @param bool   $not
	 * @param bool   $atomic
	 * @return array{match: bool, match_value: string}
	 */
	private static function evaluate_list( array $list, string $operator, array $condition, bool $not, bool $atomic ): array {
		$find  = (string) ( $condition['find'] ?? '' );
		$count = count( $list );

		switch ( $operator ) {
			case 'totals':
				return self::finish( true, $count === (int) ( $condition['value'] ?? 0 ), $not, (string) $count );
			case 'totals_at_least':
				return self::finish( true, $count >= (int) ( $condition['value'] ?? 0 ), $not, (string) $count );
			case 'totals_more':
				return self::finish( true, $count > (int) ( $condition['value'] ?? 0 ), $not, (string) $count );
			case 'totals_no_more':
				return self::finish( true, $count <= (int) ( $condition['value'] ?? 0 ), $not, (string) $count );
			case 'totals_less':
				return self::finish( true, $count < (int) ( $condition['value'] ?? 0 ), $not, (string) $count );

			case 'contains_note':
				foreach ( $list as $entry ) {
					if ( isset( $entry['note'] ) && mb_stripos( (string) $entry['note'], $find ) !== false ) {
						return self::finish( true, true, $not, self::display_trait( $entry ) );
					}
				}
				return self::finish( true, false, $not, '(none)' );

			case 'contains':
				foreach ( $list as $entry ) {
					if ( strcasecmp( (string) $entry['name'], $find ) === 0 ) {
						return self::finish( true, true, $not, self::display_trait( $entry ) );
					}
				}
				return self::finish( true, false, $not, '(none)' );

			case 'contains_no_more':
			case 'contains_less':
			case 'contains_exactly':
			case 'contains_at_least':
			case 'contains_more':
				return self::evaluate_named_count( $list, $operator, $find, (int) ( $condition['value'] ?? 0 ), $not, $atomic );

			default:
				return self::finish( false, false, $not, '(none)' );
		}
	}

	/**
	 * Evaluates the five "count comparison" trait operators. A non-atomic list
	 * stops at its first matching-named entry regardless of whether the count
	 * comparison itself passes; an atomic list keeps walking every occurrence
	 * of the name until one satisfies the comparison or the list is exhausted.
	 *
	 * The trait must be present at all - absent from the list, this returns
	 * match=false, which is what makes a negated `contains_no_more`/`contains_less`
	 * clause match an absent trait.
	 *
	 * @param array  $list
	 * @param string $operator
	 * @param string $find
	 * @param int    $number
	 * @param bool   $not
	 * @param bool   $atomic
	 * @return array{match: bool, match_value: string}
	 */
	private static function evaluate_named_count( array $list, string $operator, string $find, int $number, bool $not, bool $atomic ): array {
		$match_value = '(none)';
		$found_any   = false;

		foreach ( $list as $entry ) {
			if ( strcasecmp( (string) $entry['name'], $find ) !== 0 ) {
				continue;
			}
			$found_any   = true;
			$match_value = self::display_trait( $entry );
			$entry_count = (int) ( $entry['count'] ?? 0 );

			$match = false;
			switch ( $operator ) {
				case 'contains_no_more':
					$match = $entry_count <= $number;
					break;
				case 'contains_less':
					$match = $entry_count < $number;
					break;
				case 'contains_exactly':
					$match = $entry_count === $number;
					break;
				case 'contains_at_least':
					$match = $entry_count >= $number;
					break;
				case 'contains_more':
					$match = $entry_count > $number;
					break;
			}

			if ( $match ) {
				return self::finish( true, true, $not, $match_value );
			}
			if ( ! $atomic ) {
				// Non-atomic: stop at the first matching-named entry regardless of outcome.
				break;
			}
			// Atomic: keep walking every remaining occurrence of this name.
		}

		if ( ! $found_any ) {
			$match_value = '(none)';
		}

		// Applicable is always true here since $operator is always one of the five handled above.
		return self::finish( true, false, $not, $match_value );
	}

	/**
	 * Formats a trait entry as a readable match-reason label: `"Name xCount"`
	 * when a count greater than 1 is present, or plain `Name` otherwise. A
	 * simpler formatter than the sheet renderer's own `displayTrait.ts`, since
	 * the query engine only needs a readable label, not per-display-type formatting.
	 *
	 * @param array $entry
	 * @return string
	 */
	private static function display_trait( array $entry ): string {
		$name = (string) ( $entry['name'] ?? '' );
		if ( isset( $entry['count'] ) && (int) $entry['count'] > 1 ) {
			return "{$name} x{$entry['count']}";
		}
		return $name;
	}

	/**
	 * `Match = Applicable And (Match Xor QC.CompNot)` - the single rule every operator
	 * funnels through. The `And` sitting outside the `Xor` is why a negated
	 * inapplicable clause is still a non-match, not a rescue.
	 *
	 * @param bool   $applicable
	 * @param bool   $raw_match
	 * @param bool   $not
	 * @param string $match_value
	 * @return array{match: bool, match_value: string}
	 */
	private static function finish( bool $applicable, bool $raw_match, bool $not, string $match_value ): array {
		return [
			'match'       => $applicable && ( $raw_match !== $not ),
			'match_value' => $match_value,
		];
	}

	// Value resolution: resolves a character's actual value via field-map.php + Field_Registry.

	/** @var array<string,string> Block slug -> section_type, memoized per request. */
	private static array $section_type_cache = [];

	/**
	 * Resolve one field's value and type for one row, per that inventory's own
	 * `source` kind - `field-map.php` for `char` (the default), or
	 * `query-inventories.php`'s own map for anything else. Assumes the field
	 * was already validated (`validate_conditions()`) - an unmapped or
	 * uncomputable-derived field reaching here is a programming error, not a
	 * query-time condition to handle gracefully.
	 *
	 * The `json` and `stack_relative_list` arms only ever run for the `char`
	 * inventory: no world-object field-map entry declares either kind, so a
	 * world-object row never reaches `$row->stack_slug`/`$row->owner_slug`,
	 * neither of which it has. Enforced by the map data, not by a branch here
	 * (query-beyond-characters-design.md §7.2).
	 *
	 * @param object $row       Decoded character or world-object row (its JSON column already an array).
	 * @param string $field
	 * @param string $inventory One of Field_Registry::QUERYABLE_INVENTORIES.
	 * @return array{type: string, value: mixed, atomic: bool}
	 */
	public static function resolve_value( object $row, string $field, string $inventory = 'char' ): array {
		$map  = Field_Registry::map_for( $field, $inventory );
		$type = Field_Registry::type_for( $field, $inventory );

		$value  = null;
		$atomic = false;
		if ( $map === null ) {
			return [ 'type' => $type, 'value' => $value, 'atomic' => $atomic ];
		}

		switch ( $map['source'] ?? '' ) {
			case 'column':
				$value = $row->{$map['column']} ?? null;
				break;

			case 'json':
				// A field or pool named without a block lives on whichever block the character's
				// own creature stack defines it on (field-map.php) - Willpower, Rank, Auspice. Read
				// unconditionally until 1.0.0-review F-051, so each of those matched no one.
				$block = $map['block'] ?? self::block_holding( $row, $map );
				// 1.3.3 C7: field-map.php/block_holding() both name a slug that may be retired on
				// a cut-over install - the data itself now lives under the replacement key.
				$live_block = $block !== null ? Catalog_Cutover::live_slug( (string) $row->stack_slug, $block ) : null;
				$data       = $live_block !== null ? ( $row->sheet_data[ $live_block ] ?? null ) : null;
				// $live_block !== null is checked explicitly alongside $data, rather than relied
				// on as an implication of it, so this stays provably null-safe through the extra
				// live_slug() step above (PHPStan's ternary-implies-non-null narrowing does not
				// chain reliably through it).
				if ( $data !== null && $live_block !== null ) {
					if ( isset( $map['field'] ) ) {
						$value = $data[ $map['field'] ] ?? null;
					} elseif ( isset( $map['pool'] ) ) {
						$value = $data[ $map['pool'] ][ $map['part'] ] ?? null;
					} else {
						$value  = self::normalize_list( $data, $live_block, (string) $row->owner_slug );
						$atomic = self::block_is_atomic( $live_block, $row->owner_slug );
					}
				}
				break;

			case 'derived':
				if ( $field === 'random' ) {
					// GV's qkRandom: `CInt(Rnd() * 100)`.
					$value = mt_rand( 0, 99 );
				} elseif ( $field === 'group' ) {
					// 1.1.0 F1: the "Group" field (field-map.php's own comment on why it isn't
					// "faction") - every active faction this character belongs to, comma-joined.
					// A disbanded faction never counts as a current membership for matching.
					$names = array_map(
						static fn( $m ) => (string) $m->faction_name,
						array_filter(
							Faction_Member::for_character( (int) $row->id ),
							static fn( $m ) => ( $m->faction_status ?? 'active' ) === 'active'
						)
					);
					$value = implode( ', ', array_values( $names ) );
				} elseif ( $field === 'position' ) {
					// 1.1.0 F2: every title this character currently holds, comma-joined -
					// regardless of that position's own audience/holder_public, which govern who
					// may SEE the value elsewhere, not whether the Storyteller-only Query Tool can
					// match against it.
					$titles = array_map(
						static fn( $p ) => (string) $p->title,
						Position::for_character( (int) $row->id )
					);
					$value = implode( ', ', $titles );
				}
				break;

			case 'stack_relative_list':
				// e.g. 'influences': resolved per-character since the block slug depends on the character's own stack.
				$block = str_replace( '{stack}', $row->stack_slug, $map['block_pattern'] );
				$data  = $row->sheet_data[ $block ] ?? null;
				if ( $data !== null ) {
					$sources = self::catalog_sources( $block, $row->owner_slug );
					$value   = array_values( array_filter( $data, static function ( $item ) use ( $sources, $map ) {
						return ( $sources[ $item['name'] ?? '' ] ?? '' ) === $map['filter_source'];
					} ) );
					$atomic  = self::block_is_atomic( $block, $row->owner_slug );
				}
				break;

			case 'properties':
				$value  = $row->properties[ $map['property'] ] ?? null;
				$atomic = ! empty( $map['atomic'] );
				break;
		}

		return [ 'type' => $type, 'value' => $value, 'atomic' => $atomic ];
	}

	/**
	 * Normalizes a block's held items to `{name, count, note?}` regardless of
	 * whether it is a `trait_list` (`count`) or `tiered_power` (`level`) block.
	 * Reads the block's definition to tell which shape applies, never guessing
	 * from the key name.
	 *
	 * @param array  $items
	 * @param string $block
	 * @param string $game_slug The character's chronicle, whose fork of the block wins.
	 * @return array
	 */
	private static function normalize_list( array $items, string $block, string $game_slug = '' ): array {
		if ( self::section_type( $block, $game_slug ) !== 'tiered_power' ) {
			return $items;
		}
		return array_map( static function ( $item ) {
			return [ 'name' => $item['name'] ?? '', 'count' => (int) ( $item['level'] ?? 0 ) ];
		}, $items );
	}

	/**
	 * The block on a character's own creature stack that defines a field or
	 * pool `field-map.php` names without a block, through the chronicle's own
	 * forks - null when the stack has no such field or pool (a vampire has no
	 * Auspice).
	 *
	 * @param object              $row
	 * @param array<string,mixed> $map A `json` field-map entry with `field` or `pool` and no `block`.
	 * @return string|null
	 */
	private static function block_holding( object $row, array $map ): ?string {
		$list       = isset( $map['field'] ) ? 'fields' : 'pools';
		$name       = (string) ( $map['field'] ?? $map['pool'] ?? '' );
		$stack_slug = (string) ( $row->stack_slug ?? '' );
		$game_slug  = (string) ( $row->owner_slug ?? '' );

		return self::remembered( "holding|{$stack_slug}|{$game_slug}|{$list}|{$name}", static function () use ( $stack_slug, $game_slug, $list, $name ) {
			$resolved = Creature_Stack::resolve( $stack_slug, $game_slug );
			foreach ( (array) ( $resolved['blocks'] ?? [] ) as $block ) {
				foreach ( (array) ( $block->definition->{ $list } ?? [] ) as $entry ) {
					if ( ( $entry->name ?? null ) === $name ) {
						return (string) $block->slug;
					}
				}
			}
			return null;
		} );
	}

	/**
	 * Resolves one field for many rows in a single query run, so the block
	 * and catalog lookups behind each value are made once per stack rather
	 * than once per row - for a caller outside `execute()`, such as rumor
	 * generation reading every active character's Clan.
	 *
	 * @param object[] $rows
	 * @param string   $field
	 * @return array<int,mixed> Each row's value, in row order.
	 */
	public static function values_for( array $rows, string $field ): array {
		$owns_run = self::begin_run();
		try {
			return array_map( static fn( $row ) => self::resolve_value( $row, $field )['value'], $rows );
		} finally {
			self::end_run( $owns_run );
		}
	}

	/**
	 * Looks up a block's `section_type` (e.g. `trait_list`, `tiered_power`)
	 * through the chronicle's own fork when it has one (1.0.0-review F-013,
	 * D43), defaulting to `trait_list` when the block has no explicit type
	 * set. Caches the result per block and chronicle for the lifetime of the
	 * request.
	 *
	 * @param string $block
	 * @param string $game_slug
	 * @return string
	 */
	private static function section_type( string $block, string $game_slug = '' ): string {
		$key = $block . '|' . $game_slug;
		if ( ! array_key_exists( $key, self::$section_type_cache ) ) {
			$definition                      = Schema_Block::find_for_game( $block, $game_slug );
			self::$section_type_cache[ $key ] = $definition->section_type ?? 'trait_list';
		}
		return self::$section_type_cache[ $key ];
	}

	/**
	 * Looks up whether a block's trait list is atomic, i.e. whether duplicate
	 * entries of the same name are compared individually rather than collapsed,
	 * resolved through this chronicle's own fork when one exists
	 * (BE_PROCESS/design/background-ledger-apr-design.md §3.1) - this is a general
	 * block lookup, used for any trait_list/tiered_power block a field-map
	 * entry names, not only the backgrounds family Backgrounds_Catalog covers.
	 * Returns false when the block has no definition or no `atomic` flag set.
	 *
	 * @param string $block
	 * @param string $game_slug
	 * @return bool
	 */
	private static function block_is_atomic( string $block, string $game_slug ): bool {
		return (bool) self::remembered( "atomic|{$block}|{$game_slug}", static function () use ( $block, $game_slug ) {
			$definition = Schema_Block::find_for_game( $block, $game_slug );
			return ! empty( $definition->definition->atomic ?? false );
		} );
	}

	/**
	 * Builds a name -> source map (`'Influences'`, `'Backgrounds'`,
	 * `'Backgrounds, <Type>'`) for every item in one merged backgrounds
	 * block, resolved through this chronicle's own fork when one exists
	 * (BE_PROCESS/design/background-ledger-apr-design.md §3.1). Delegates to the
	 * shared lookup `Action_Allocator`/`Rumor_Generator` also use.
	 *
	 * @param string $block
	 * @param string $game_slug
	 * @return array<string,string>
	 */
	private static function catalog_sources( string $block, string $game_slug ): array {
		return (array) self::remembered( "sources|{$block}|{$game_slug}", static fn() => Backgrounds_Catalog::sources_for( $block, $game_slug ) );
	}

	// Validation: rejects an unknown field, inapplicable operator, or missing required value.

	/**
	 * Validates a list of query conditions, checking that each field is
	 * known, applies to the given inventory, has a Beyond Elysium
	 * equivalent, is queryable, and that its operator applies to the
	 * field's type with whatever `find`/`value` it requires. Returns the
	 * first invalid condition found, naming the clause and reason - GV's
	 * `qtError` silently skipped an unqueryable clause and widened the
	 * result set instead; a null-valued clause matching nothing looks
	 * identical to "no such rows exist", which is exactly the wrong-answer
	 * shape this design deliberately does not reproduce.
	 *
	 * @param array[] $conditions
	 * @param string  $inventory One of Field_Registry::QUERYABLE_INVENTORIES.
	 * @return array{index: int, message: string}|null Null when every condition is valid.
	 */
	public static function validate_conditions( array $conditions, string $inventory = 'char' ): ?array {
		if ( ! in_array( $inventory, Field_Registry::QUERYABLE_INVENTORIES, true ) ) {
			return [ 'index' => 0, 'message' => "Unknown inventory \"{$inventory}\"." ];
		}

		foreach ( $conditions as $index => $condition ) {
			$field = $condition['field'] ?? '';
			if ( $field === '' ) {
				return [ 'index' => $index, 'message' => 'Missing field.' ];
			}

			$registry = Field_Registry::get( $field );
			if ( $registry === null ) {
				return [ 'index' => $index, 'message' => "Unknown field \"{$field}\"." ];
			}
			if ( ! array_key_exists( $field, Field_Registry::for_inventory( $inventory ) ) ) {
				return [ 'index' => $index, 'message' => "Field \"{$field}\" does not apply to the \"{$inventory}\" inventory." ];
			}

			$map = Field_Registry::map_for( $field, $inventory );
			if ( $map === null || $map['source'] === 'unmapped' ) {
				return [ 'index' => $index, 'message' => "Field \"{$field}\" has no Beyond Elysium equivalent and cannot be queried." ];
			}
			// A 'derived' field is normally blocked here because it has nothing
			// resolve_value() can actually compute for it - 'random' is the one
			// pre-existing exception. 1.1.0 F1/F2's 'group'/'position' are a second,
			// real one: resolve_value()'s own 'derived' case fully computes both,
			// and blocking them here would make a Storyteller's "Restricted to Faction
			// contains <coterie>" (§7 trace 5) - a real, load-bearing audience rule,
			// not a Query Tool curiosity - permanently unusable.
			if ( $map['source'] === 'derived' && ! in_array( $field, [ 'random', 'group', 'position' ], true ) ) {
				return [ 'index' => $index, 'message' => "Field \"{$field}\" is not a stored value and cannot be queried." ];
			}

			$operator = $condition['operator'] ?? '';
			$type     = Field_Registry::type_for( $field, $inventory );
			if ( $operator === '' || ! self::is_applicable( $type, $operator ) ) {
				return [ 'index' => $index, 'message' => "Operator \"{$operator}\" does not apply to field \"{$field}\" (type {$type})." ];
			}

			// Which of find/value each operator needs varies by type; checked below.
			$needs_find  = false;
			$needs_value = false;

			switch ( $type ) {
				case 'field':
					$needs_find = in_array( $operator, [ 'contains', 'equals' ], true );
					break;
				case 'date':
					$needs_find = in_array( $operator, [ 'equals', 'at_least', 'greater', 'less', 'no_more' ], true );
					break;
				case 'num':
					$needs_value = in_array( $operator, [ 'equals', 'at_least', 'greater', 'less', 'no_more' ], true );
					break;
				case 'list':
					if ( in_array( $operator, [ 'contains', 'contains_note' ], true ) ) {
						$needs_find = true;
					} elseif ( in_array( $operator, self::NAMED_COUNT_OPERATORS, true ) ) {
						$needs_find  = true;
						$needs_value = true;
					} else {
						// totals / totals_at_least / totals_more / totals_no_more / totals_less
						$needs_value = true;
					}
					break;
			}

			if ( $needs_find && ( ! isset( $condition['find'] ) || $condition['find'] === '' ) ) {
				return [ 'index' => $index, 'message' => "Operator \"{$operator}\" requires \"find\"." ];
			}
			if ( $needs_value && ! isset( $condition['value'] ) ) {
				return [ 'index' => $index, 'message' => "Operator \"{$operator}\" requires \"value\"." ];
			}
		}

		return null;
	}

	// Query execution.

	/**
	 * Runs a query against every row of an inventory in a game, then sorts
	 * and paginates the matches. Conditions are evaluated in PHP against a
	 * decoded JSON column rather than expressed as SQL.
	 *
	 * @param string $game_slug
	 * @param array  $conditions
	 * @param string $logic     'AND' or 'OR'.
	 * @param array  $paging    `{sort: {field, direction}, page, per_page}`.
	 * @param string $inventory One of Field_Registry::QUERYABLE_INVENTORIES.
	 * @param array  $options   See find_matches(): `exclude_npcs`, `prepare_row`.
	 * @return array{results: object[], total: int}
	 */
	public static function execute( string $game_slug, array $conditions, string $logic, array $paging = [], string $inventory = 'char', array $options = [] ): array {
		$owns_run = self::begin_run();
		try {
			$matches = self::find_matches( $game_slug, $conditions, $logic, $inventory, $options );
			$total   = count( $matches );

			$sort = $paging['sort'] ?? null;
			if ( $sort && ! empty( $sort['field'] ) ) {
				$field     = $sort['field'];
				$direction = ( $sort['direction'] ?? 'asc' ) === 'desc' ? -1 : 1;
				// Each row's sort value is resolved once, not on both sides of every comparison
				// (1.0.0-review F-027).
				$keyed = array_map( static fn( $row ) => [ self::resolve_value( $row, $field, $inventory )['value'], $row ], $matches );
				usort( $keyed, static fn( $a, $b ) => $direction * ( $a[0] <=> $b[0] ) );
				$matches = array_column( $keyed, 1 );
			}

			$per_page = min( 100, max( 1, (int) ( $paging['per_page'] ?? 20 ) ) );
			$page     = max( 1, (int) ( $paging['page'] ?? 1 ) );
			$paged    = array_slice( $matches, ( $page - 1 ) * $per_page, $per_page );

			return [ 'results' => $paged, 'total' => $total ];
		} finally {
			self::end_run( $owns_run );
		}
	}

	/**
	 * Block lookups remembered for the length of one query run - null outside
	 * one, so nothing outlives the run that filled it (a static cache that did
	 * could hand a later request, or a later test, a stale definition).
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $run_memo = null;

	/** Starts a query run's memo, unless one is already running; returns whether this call owns it. */
	private static function begin_run(): bool {
		if ( self::$run_memo !== null ) {
			return false;
		}
		self::$run_memo = [];
		return true;
	}

	/** Ends the run's memo, when the caller is the one that started it. */
	private static function end_run( bool $owns_run ): void {
		if ( $owns_run ) {
			self::$run_memo = null;
		}
	}

	/**
	 * Returns `$compute()`'s value, remembered under `$key` while a query run is
	 * in progress.
	 *
	 * @param string   $key
	 * @param callable $compute
	 * @return mixed
	 */
	private static function remembered( string $key, callable $compute ) {
		if ( self::$run_memo === null ) {
			return $compute();
		}
		if ( ! array_key_exists( $key, self::$run_memo ) ) {
			self::$run_memo[ $key ] = $compute();
		}
		return self::$run_memo[ $key ];
	}

	/**
	 * Builds the unpaginated match set for a query, shared by `execute()`
	 * (which sorts and pages it) and `statistics()` (which aggregates over
	 * the whole set). Every statistic runs the query first, then aggregates
	 * over the result. Fetches rows via whichever of the two real storage
	 * shapes the inventory declares - the clause-matching loop below is
	 * otherwise identical regardless of which one supplied the rows.
	 *
	 * `$options` narrows what a viewer can reach before a single clause is
	 * evaluated (1.0.0-review F-024): `exclude_npcs` drops NPC rows, and
	 * `prepare_row` (a callable taking the row) redacts each row in place -
	 * so a hidden value can neither be returned nor matched against, and a
	 * condition on it cannot work as a yes/no oracle.
	 *
	 * @param string $game_slug
	 * @param array  $conditions
	 * @param string $logic
	 * @param string $inventory One of Field_Registry::QUERYABLE_INVENTORIES.
	 * @param array  $options   `{exclude_npcs?: bool, prepare_row?: callable}`.
	 * @return object[]
	 */
	private static function find_matches( string $game_slug, array $conditions, string $logic, string $inventory = 'char', array $options = [] ): array {
		$descriptor = Field_Registry::inventory( $inventory );
		if ( $descriptor === null ) {
			return [];
		}
		$rows = $descriptor['storage'] === 'world_objects'
			? self::rows_for_world_objects( $game_slug, (string) ( $descriptor['object_type'] ?? '' ) )
			: self::rows_for_characters( $game_slug );

		$matches = [];
		foreach ( $rows as $row ) {
			if ( ! empty( $options['exclude_npcs'] ) && (int) ( $row->is_npc ?? 0 ) === 1 ) {
				continue;
			}
			if ( isset( $options['prepare_row'] ) && is_callable( $options['prepare_row'] ) ) {
				( $options['prepare_row'] )( $row );
			}

			$clause_results = [];
			foreach ( $conditions as $condition ) {
				$resolved         = self::resolve_value( $row, $condition['field'], $inventory );
				$clause_results[] = self::evaluate_clause( $resolved['type'], $resolved['value'], $condition, $resolved['atomic'] );
			}

			if ( empty( $clause_results ) ) {
				$matches[] = $row;
				continue;
			}

			if ( strtoupper( $logic ) === 'OR' ) {
				$matched_clauses = array_filter( $clause_results, static fn( $r ) => $r['match'] );
				$is_match        = ! empty( $matched_clauses );
				$reasons         = array_map( static fn( $r ) => $r['match_value'], $matched_clauses );
			} else {
				$is_match = true;
				foreach ( $clause_results as $r ) {
					if ( ! $r['match'] ) {
						$is_match = false;
						break;
					}
				}
				$reasons = array_map( static fn( $r ) => $r['match_value'], $clause_results );
			}

			if ( $is_match ) {
				$row->match_reason = implode( ', ', $reasons );
				$matches[]         = $row;
			}
		}

		return $matches;
	}

	/**
	 * Fetches every character row for a chronicle, decoded and ready for
	 * clause evaluation. The `char` inventory's own row source, lifted
	 * verbatim from `find_matches()`'s original character-only body.
	 *
	 * @param string $game_slug
	 * @return object[]
	 */
	private static function rows_for_characters( string $game_slug ): array {
		global $wpdb;
		$table = Manager::table( 'characters' );
		// A statement timeout guards against one bad query holding a connection open.
		$sql = $wpdb->prepare(
			"SELECT /*+ MAX_EXECUTION_TIME(5000) */ * FROM {$table} WHERE owner_type = 'chronicle' AND owner_slug = %s",
			$game_slug
		);
		$rows = $wpdb->get_results( $sql ) ?: [];
		return array_map( [ self::class, 'decode_character' ], $rows );
	}

	/**
	 * Fetches every world-object row of one object_type for a chronicle,
	 * decoded and ready for clause evaluation. A game_slug that does not
	 * resolve to a real game returns an empty result set rather than a
	 * fatal - the same defensive shape `rows_for_characters()`'s own query
	 * degrades to on a chronicle with no rows at all.
	 *
	 * @param string $game_slug
	 * @param string $object_type
	 * @return object[]
	 */
	private static function rows_for_world_objects( string $game_slug, string $object_type ): array {
		$game = Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return [];
		}

		global $wpdb;
		$table = Manager::table( 'world_objects' );
		$sql   = $wpdb->prepare(
			"SELECT /*+ MAX_EXECUTION_TIME(5000) */ * FROM {$table} WHERE game_id = %d AND object_type = %s",
			(int) $game->id,
			$object_type
		);
		$rows = $wpdb->get_results( $sql ) ?: [];
		return array_map( [ self::class, 'decode_world_object' ], $rows );
	}

	/**
	 * Decodes a world-object row's `properties` JSON column into an array in
	 * place, the same pattern `decode_character()` applies to `sheet_data`.
	 *
	 * @param object $row
	 * @return object
	 */
	private static function decode_world_object( object $row ): object {
		if ( is_string( $row->properties ) ) {
			$row->properties = json_decode( $row->properties, true ) ?? [];
		}
		return $row;
	}

	// Statistics: port of QueryEngineClass.GetStatistics.

	const STATISTIC_TYPES = [ 'distribution', 'distinct_distribution', 'specific_distribution', 'maxima', 'sums' ];

	/**
	 * Runs one of the five statistic types over a query's full, unpaginated
	 * match set. `$trait` is required when `$stat_type` is
	 * `specific_distribution`.
	 *
	 * @param string      $game_slug
	 * @param array       $conditions
	 * @param string      $logic
	 * @param string      $key       Field-registry key to examine.
	 * @param string      $stat_type One of self::STATISTIC_TYPES.
	 * @param bool        $ok_zero   Whether `0`/`"(none)"` buckets count - the two distribution types only (Step 5h).
	 * @param string|null $trait     Named trait, required for `specific_distribution`.
	 * @param string      $inventory One of Field_Registry::QUERYABLE_INVENTORIES.
	 * @param array       $options   See find_matches(): `exclude_npcs`, `prepare_row`.
	 * @return array{buckets: array<string,float>, match_sets: array<string,string[]>, total: float, maximum: float}
	 */
	public static function statistics( string $game_slug, array $conditions, string $logic, string $key, string $stat_type, bool $ok_zero = true, ?string $trait = null, string $inventory = 'char', array $options = [] ): array {
		$owns_run = self::begin_run();
		try {
			$rows     = self::find_matches( $game_slug, $conditions, $logic, $inventory, $options );
			$registry = Field_Registry::get( $key );
			$type     = Field_Registry::type_for( $key, $inventory );

			$resolved = [];
			foreach ( $rows as $row ) {
				// $row->name exists on both storage shapes - a world object has one too.
				$resolved[] = [
					'name'  => $row->name,
					'value' => self::resolve_value( $row, $key, $inventory )['value'],
				];
			}

			return self::aggregate( $resolved, $type, $registry['title'] ?? $key, $stat_type, $ok_zero, $trait, count( $rows ) );
		} finally {
			self::end_run( $owns_run );
		}
	}

	/**
	 * The statistic aggregation core. Pure - no database access - so it can be
	 * unit-tested directly against a hand-built fixture, the same pattern as
	 * `Action_Allocator::resolve_common_subactions()`.
	 *
	 * @param array       $resolved         `{name, value}` pairs - one per character examined, value already resolved via `resolve_value()`.
	 * @param string      $field_type       One of: field, num, date, bool, list.
	 * @param string      $field_title      The field's display title (for scalar Maxima/Sums bucket labels and non-field Distribution relabeling).
	 * @param string      $stat_type        One of self::STATISTIC_TYPES.
	 * @param bool        $ok_zero
	 * @param string|null $trait
	 * @param int         $characters_examined Count of all characters the query matched, for `distinct_distribution`'s `Total`.
	 * @return array{buckets: array<string,float>, match_sets: array<string,string[]>, total: float, maximum: float}
	 */
	public static function aggregate( array $resolved, string $field_type, string $field_title, string $stat_type, bool $ok_zero, ?string $trait, int $characters_examined ): array {
		$is_list    = $field_type === 'list';
		$buckets    = [];
		$match_sets = [];
		$total      = 0.0;

		foreach ( $resolved as $item ) {
			$value = $item['value'];
			// Characters with a null value for this field are skipped entirely.
			if ( $value === null ) {
				continue;
			}

			if ( $is_list ) {
				self::accumulate_list_statistic( $stat_type, $value, $item['name'], $trait, $ok_zero, $buckets, $match_sets, $total );
			} else {
				self::accumulate_scalar_statistic( $stat_type, $value, $item['name'], $field_title, $ok_zero, $buckets, $match_sets, $total );
			}
		}

		if ( $stat_type === 'distinct_distribution' ) {
			// For distinct_distribution, total is the count of characters examined, not the bucket sum.
			$total = (float) $characters_examined;
		}

		[ $buckets, $match_sets ] = self::relabel_buckets( $stat_type, $field_type, $field_title, $trait ?? '', $buckets, $match_sets );

		if ( $stat_type === 'maxima' ) {
			// Recomputed after the loop, discarding whatever total accumulated during it.
			$total = array_sum( $buckets );
		}

		$maximum = empty( $buckets ) ? 0.0 : max( $buckets );

		return [
			'buckets'    => $buckets,
			'match_sets' => $match_sets,
			'total'      => $total,
			'maximum'    => $maximum,
		];
	}

	/**
	 * Accumulates one character's list-type field value into the running
	 * buckets/match_sets/total for whichever statistic type is being
	 * computed. Dispatches to per-name counting for distinct_distribution,
	 * maxima, and sums, or to a single count-based bucket for distribution
	 * and specific_distribution.
	 *
	 * @param string      $stat_type
	 * @param array       $list        Normalized `{name, count}` entries.
	 * @param string      $char_name
	 * @param string|null $trait
	 * @param bool        $ok_zero
	 * @param array       $buckets     By reference.
	 * @param array       $match_sets  By reference.
	 * @param float       $total       By reference.
	 * @return void
	 */
	private static function accumulate_list_statistic( string $stat_type, array $list, string $char_name, ?string $trait, bool $ok_zero, array &$buckets, array &$match_sets, float &$total ): void {
		switch ( $stat_type ) {
			case 'distribution':
				self::bucket_distribution( (string) count( $list ), $char_name, $ok_zero, $buckets, $match_sets, $total );
				return;

			case 'specific_distribution':
				$found = 0;
				foreach ( $list as $entry ) {
					if ( strcasecmp( (string) $entry['name'], (string) $trait ) === 0 ) {
						$found = (int) ( $entry['count'] ?? 0 );
						break;
					}
				}
				self::bucket_distribution( (string) $found, $char_name, $ok_zero, $buckets, $match_sets, $total );
				return;

			case 'distinct_distribution':
			case 'maxima':
			case 'sums':
				foreach ( $list as $entry ) {
					$name = (string) $entry['name'];
					$num  = $stat_type === 'distinct_distribution' ? 1.0 : (float) ( $entry['count'] ?? 0 );
					$total += $num;
					$buckets[ $name ]      = $stat_type === 'maxima'
						? max( $buckets[ $name ] ?? 0.0, $num )
						: ( $buckets[ $name ] ?? 0.0 ) + $num;
					$match_sets[ $name ][] = $char_name;
				}
				return;
		}
	}

	/**
	 * Accumulates one character's scalar field value into the running
	 * buckets/match_sets/total. Distribution buckets by the value itself;
	 * maxima and sums use one bucket keyed by the field's own title, since a
	 * scalar field has only one possible datum per character.
	 *
	 * @param string $stat_type
	 * @param mixed  $value
	 * @param string $char_name
	 * @param string $field_title
	 * @param bool   $ok_zero
	 * @param array  $buckets     By reference.
	 * @param array  $match_sets  By reference.
	 * @param float  $total       By reference.
	 * @return void
	 */
	private static function accumulate_scalar_statistic( string $stat_type, $value, string $char_name, string $field_title, bool $ok_zero, array &$buckets, array &$match_sets, float &$total ): void {
		if ( $stat_type === 'distribution' ) {
			$bucket = (string) $value;
			if ( $bucket === '' ) {
				$bucket = '(none)';
			}
			self::bucket_distribution( $bucket, $char_name, $ok_zero, $buckets, $match_sets, $total );
			return;
		}

		if ( $stat_type === 'maxima' || $stat_type === 'sums' ) {
			// A scalar field has exactly one bucket, keyed by its own title.
			$num    = (float) $value;
			$total += $num;
			$buckets[ $field_title ]      = $stat_type === 'maxima'
				? max( $buckets[ $field_title ] ?? 0.0, $num )
				: ( $buckets[ $field_title ] ?? 0.0 ) + $num;
			$match_sets[ $field_title ][] = $char_name;
			return;
		}

		// distinct_distribution and specific_distribution are not meaningful for a scalar field.
	}

	/**
	 * Adds one character to a distribution bucket, incrementing the bucket's
	 * count and the running total, and recording the character's name in that
	 * bucket's match set. Skips zero/"(none)" buckets entirely when
	 * `$ok_zero` is false.
	 *
	 * @param string $bucket
	 * @param string $char_name
	 * @param bool   $ok_zero
	 * @param array  $buckets     By reference.
	 * @param array  $match_sets  By reference.
	 * @param float  $total       By reference.
	 * @return void
	 */
	private static function bucket_distribution( string $bucket, string $char_name, bool $ok_zero, array &$buckets, array &$match_sets, float &$total ): void {
		if ( ! $ok_zero && ( $bucket === '0' || $bucket === '(none)' ) ) {
			return;
		}
		$total++;
		$buckets[ $bucket ]      = ( $buckets[ $bucket ] ?? 0.0 ) + 1;
		$match_sets[ $bucket ][] = $char_name;
	}

	/**
	 * Relabels buckets after aggregation: `specific_distribution` buckets
	 * become `"<Trait> x<N>"`; a non-`field` `distribution` gets its field
	 * title appended (`"3 Willpower"`). `field`-type distributions are left
	 * unrelabeled.
	 *
	 * @param string $stat_type
	 * @param string $field_type
	 * @param string $field_title
	 * @param string $trait
	 * @param array  $buckets
	 * @param array  $match_sets
	 * @return array{0: array<string,float>, 1: array<string,string[]>}
	 */
	private static function relabel_buckets( string $stat_type, string $field_type, string $field_title, string $trait, array $buckets, array $match_sets ): array {
		if ( $stat_type === 'specific_distribution' ) {
			$relabeled = [];
			$relabeled_matches = [];
			foreach ( $buckets as $k => $v ) {
				$relabeled[ "{$trait} x{$k}" ]         = $v;
				$relabeled_matches[ "{$trait} x{$k}" ] = $match_sets[ $k ];
			}
			return [ $relabeled, $relabeled_matches ];
		}

		if ( $stat_type === 'distribution' && $field_type !== 'field' ) {
			$relabeled = [];
			$relabeled_matches = [];
			foreach ( $buckets as $k => $v ) {
				$label                    = "{$k} {$field_title}";
				$relabeled[ $label ]         = $v;
				$relabeled_matches[ $label ] = $match_sets[ $k ];
			}
			return [ $relabeled, $relabeled_matches ];
		}

		return [ $buckets, $match_sets ];
	}

	/**
	 * Decodes a raw character row's `sheet_data` column from a JSON string
	 * into an array, if it has not already been decoded. Mutates and returns
	 * the same row object.
	 *
	 * @param object $row
	 * @return object
	 */
	private static function decode_character( object $row ): object {
		if ( is_string( $row->sheet_data ) ) {
			$row->sheet_data = json_decode( $row->sheet_data, true ) ?? [];
		}
		return $row;
	}

	// Resolves a plot's target_query to the character IDs it reaches.

	/**
	 * Resolves a plot's `target_query` to the character IDs it reaches.
	 * `null` means "reaches everyone" - every character in the game, not just
	 * active ones, since visibility is a question the caller controls separately.
	 *
	 * Uses the same engine as `execute()`: a `target_query` is just a
	 * one-condition query.
	 *
	 * Always resolves against the `char` inventory, explicitly rather than
	 * by relying on `find_matches()`'s own default - a plot's target_query
	 * targets characters by definition (a plot cannot target an item), so
	 * this is pinned rather than threaded (query-beyond-characters-
	 * design.md §7.5).
	 *
	 * Deliberately uncached (D54, 1.1.0 §3.4). This used to memoize per
	 * `(game_slug, target_query)` with no entity id in the key, so two plots
	 * sharing an identical target_query would collide with each other's result -
	 * a static property, so the collision outlives one PHPUnit test method as
	 * easily as it would outlive one production request, exactly the bug class
	 * `resolve_audience_rules()`'s own docblock names (`v0.21.28`/D40). Fixed by
	 * removing the cache entirely, matching that sibling method's own shape - a
	 * caller resolving the same query across many rows in one request is
	 * expected to memoize locally, on its own stack, if it needs to.
	 *
	 * @param string     $game_slug
	 * @param array|null $target_query `{field, operator, value}` or null.
	 * @return int[] Character IDs.
	 */
	public static function resolve_target_query( string $game_slug, ?array $target_query ): array {
		if ( $target_query === null ) {
			$matches = self::find_matches( $game_slug, [], 'AND', 'char' );
		} else {
			$matches = self::find_matches( $game_slug, [ self::target_query_to_condition( $target_query ) ], 'AND', 'char' );
		}

		return array_map( static fn( $c ) => (int) $c->id, $matches );
	}

	/**
	 * Widens a `{field, operator, value}` target_query into the condition shape
	 * `evaluate_clause()`'s own type-specific evaluators actually read - `find` for a
	 * `field`/`date`/`list` type, `value` for a `num` type or a `list` count operator
	 * (`totals*`). A target_query only ever carries `value`, so every caller turning one
	 * into a query condition - `resolve_target_query()` itself, and a rumor's own
	 * `audience_rules` derived from its target_query (1.1.0 §3.4 item 1) - needs both keys
	 * populated, not just the one a target_query happens to name.
	 *
	 * @param array $target_query `{field, operator, value}`.
	 * @return array{field: mixed, operator: mixed, find: mixed, value: mixed}
	 */
	public static function target_query_to_condition( array $target_query ): array {
		return [
			'field'    => $target_query['field'],
			'operator' => $target_query['operator'],
			'find'     => $target_query['value'] ?? '',
			'value'    => $target_query['value'] ?? null,
		];
	}

	/**
	 * A character's held count/level in a named entry of a trait-list-shaped block, resolved
	 * through the same block/fork logic `resolve_value()` already uses for every other
	 * caller (1.1.0 §3.4 - rumor levels: "Media x3 reads levels 1-3"). Goes *through*
	 * `resolve_value()` rather than adding a new case inside it - a trait block's held list
	 * is already exactly what that method returns for a `json`/`stack_relative_list` field
	 * with no `field`/`pool` narrowing, so this is a thin reduction over that list, the same
	 * one `evaluate_named_count()` already performs for the query operators.
	 *
	 * Returns 0 when the key resolves to nothing, or the named entry is absent - "no rating"
	 * and "not held at all" are the same answer here, matching every other absent-trait
	 * convention in this engine.
	 *
	 * @param object $character A decoded character row.
	 * @param string $key       A field-map key resolving to a trait-list-shaped block (e.g. `influences`).
	 * @param string $name      The held entry's name, matched case-insensitively.
	 * @return int
	 */
	public static function trait_rating( object $character, string $key, string $name ): int {
		$list = self::resolve_value( $character, $key )['value'];
		if ( ! is_array( $list ) ) {
			return 0;
		}

		foreach ( $list as $entry ) {
			if ( strcasecmp( (string) ( $entry['name'] ?? '' ), $name ) === 0 ) {
				return (int) ( $entry['count'] ?? 0 );
			}
		}
		return 0;
	}

	/**
	 * Resolves an audience's `rules` (1.1.0 §2.1) to the character IDs it reaches - the
	 * multi-condition sibling of `resolve_target_query()`, which a plot's own delivery rule
	 * still uses unchanged. Distinct from it because an audience rule can combine several
	 * conditions with AND/OR (`Query_Engine::find_matches()` already supports this; a
	 * `target_query` never has), and because a null/empty rule set here means "matches
	 * nobody" - unlike `resolve_target_query( null )`, which means everyone. `restricted`
	 * with no rules and no connections is meant to be nobody-but-the-connections; falling
	 * back to "everyone" would silently defeat the audience it was set to narrow.
	 *
	 * Always resolves against the `char` inventory: an audience is a question of which
	 * characters may see something, never items or locations.
	 *
	 * Deliberately uncached. Found writing `AudienceThreadTest`, which failed on a stale
	 * cross-test result the moment two tests happened to share a rule set - `resolve_target_query()`
	 * had the identical shape, logged as D54 and fixed the same way in 1.1.0 S4. A caller
	 * resolving the same rules across many rows in one request (`Audience::filter()`) is
	 * expected to memoize locally, on the stack, if it needs to.
	 *
	 * @param string     $game_slug
	 * @param array|null $rules `{logic: 'AND'|'OR', conditions: array}` or null.
	 * @return int[] Character IDs.
	 */
	public static function resolve_audience_rules( string $game_slug, ?array $rules ): array {
		if ( empty( $rules['conditions'] ) ) {
			return [];
		}

		$logic   = strtoupper( (string) ( $rules['logic'] ?? 'AND' ) ) === 'OR' ? 'OR' : 'AND';
		$matches = self::find_matches( $game_slug, $rules['conditions'], $logic, 'char' );

		return array_map( static fn( $c ) => (int) $c->id, $matches );
	}
}
