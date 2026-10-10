<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * The creation tally: what a draft character's build uses against its creature type's declared `creation_rules`,
 * step by step, in the book's order - grants and starts, then what each budget covers, then what each pool pays for,
 * then what is left for XP. A guide, never a gate: nothing it reports blocks a save.
 */
class Creation_Tally {

	/**
	 * The step kinds `creation_rules.steps` declares.
	 */
	const KINDS = [ 'prioritized', 'budget', 'free', 'earned', 'limit', 'start', 'grant' ];

	/**
	 * The full tally for one draft build.
	 *
	 * @param object               $stack        A resolved creature stack row (`stack_definition`, `creation_rules`).
	 * @param array<string,object> $blocks       The stack's blocks by slug, as `Creature_Stack::resolve()` gives them.
	 * @param array<string,mixed>  $sheet_data   The draft sheet, in progress.
	 * @param string               $game_slug    The chronicle, for an in-type test's own fallback block lookups.
	 * @param int                  $starting_xp  The chronicle's starting experience for a new character.
	 * @return array<string,mixed>
	 */
	public static function for_stack( object $stack, array $blocks, array $sheet_data, string $game_slug = '', int $starting_xp = 0 ): array {
		$character = (object) [
			'owner_slug' => $game_slug,
			'stack_slug' => (string) ( $stack->slug ?? '' ),
			'sheet_data' => $sheet_data,
		];
		// A creature type with no creation_rules document declared at all has nothing to tally against: the starting
		// sheet is written directly. A document that declares an empty `steps` list is different - it is an authored
		// ruling that nothing is budgeted or free.
		if ( ! is_object( $stack->creation_rules ?? null ) ) {
			return [
				'steps'          => [],
				'pools'          => [],
				'limits'         => [],
				'grants_missing' => [],
				'unbuyable'      => [],
				'xp'             => [ 'starting' => $starting_xp, 'needed' => 0, 'left' => $starting_xp ],
			];
		}

		$sections = self::as_list( $stack->stack_definition->sections ?? [] );
		$steps    = self::as_list( $stack->creation_rules->steps ?? [] );

		$covered        = [];
		$pools          = [];
		$limits         = [];
		$grants_missing = [];
		$resolved       = [];
		$reports        = [];

		foreach ( $steps as $step ) {
			$step    = self::as_array( $step );
			$kind    = (string) ( $step['kind'] ?? '' );
			$applies = self::step_applies( $step['when'] ?? null, $sheet_data );
			if ( $kind === 'grant' ) {
				$missing = $applies ? self::process_grant( $step, $blocks, $sheet_data, $resolved ) : [];
				$grants_missing = array_merge( $grants_missing, $missing );
				$reports[] = [ 'kind' => 'grant', 'label' => (string) ( $step['label'] ?? '' ), 'applies' => $applies, 'missing' => $missing ];
			} elseif ( $kind === 'start' ) {
				$value = null;
				if ( $applies ) {
					$value = self::resolve_start( $step, $blocks, $sheet_data, $resolved );
					$resolved[ (string) ( $step['target'] ?? '' ) ] = $value;
				}
				$reports[] = [ 'kind' => 'start', 'label' => (string) ( $step['label'] ?? '' ), 'applies' => $applies, 'target' => (string) ( $step['target'] ?? '' ), 'value' => $value ];
			}
		}

		foreach ( $steps as $step ) {
			$step    = self::as_array( $step );
			$kind    = (string) ( $step['kind'] ?? '' );
			$applies = self::step_applies( $step['when'] ?? null, $sheet_data );
			if ( $kind === 'prioritized' ) {
				$reports[] = self::process_prioritized( $step, $applies, $blocks, $sheet_data, $covered );
			} elseif ( $kind === 'budget' ) {
				$reports[] = self::process_budget( $step, $applies, $character, $stack, $blocks, $sheet_data, $covered );
			}
		}

		foreach ( $steps as $step ) {
			$step = self::as_array( $step );
			if ( ( $step['kind'] ?? '' ) !== 'earned' ) {
				continue;
			}
			$applies   = self::step_applies( $step['when'] ?? null, $sheet_data );
			$reports[] = self::process_earned( $step, $applies, $blocks, $sheet_data, $pools );
		}
		foreach ( $steps as $step ) {
			$step = self::as_array( $step );
			if ( ( $step['kind'] ?? '' ) !== 'free' ) {
				continue;
			}
			$applies   = self::step_applies( $step['when'] ?? null, $sheet_data );
			$reports[] = self::process_free( $step, $applies, $character, $stack, $sections, $blocks, $sheet_data, $covered, $resolved, $pools );
		}

		foreach ( $steps as $step ) {
			$step = self::as_array( $step );
			if ( ( $step['kind'] ?? '' ) !== 'limit' ) {
				continue;
			}
			$applies = self::step_applies( $step['when'] ?? null, $sheet_data );
			$flags   = $applies ? self::process_limit( $step, $blocks, $sheet_data, $resolved ) : [];
			$limits  = array_merge( $limits, $flags );
			$reports[] = [ 'kind' => 'limit', 'label' => (string) ( $step['label'] ?? '' ), 'applies' => $applies, 'flags' => $flags ];
		}

		$needed = self::price_uncovered( $character, $stack, $sections, $blocks, $sheet_data, $resolved, $covered );

		return [
			'steps'          => $reports,
			'pools'          => self::pool_report( $pools ),
			'limits'         => $limits,
			'grants_missing' => $grants_missing,
			'unbuyable'      => self::unbuyable( $sections, $blocks, $sheet_data, $resolved, $covered ),
			'xp'             => [ 'starting' => $starting_xp, 'needed' => $needed, 'left' => $starting_xp - $needed ],
		];
	}

	// -------------------------------------------------------------------------
	// unbuyable
	// -------------------------------------------------------------------------

	/**
	 * What no starting experience can buy: the dots of a pool that is only ever raised in play (`raised_by`) that no
	 * step covers for free, and a pool set above the most the book allows (`book_max`).
	 *
	 * @param array<int,object>    $sections
	 * @param array<string,object> $blocks
	 * @param array<string,mixed>  $sheet_data
	 * @param array<string,mixed>  $resolved
	 * @param array<string,bool>   $covered
	 * @return array<int,array<string,mixed>> `{kind, section, pool, dots}` or `{kind, section, pool, value, max}`.
	 */
	private static function unbuyable( array $sections, array $blocks, array $sheet_data, array $resolved, array $covered ): array {
		$found = [];
		foreach ( $sections as $section ) {
			$slug  = (string) ( $section->block_slug ?? '' );
			$block = $blocks[ $slug ] ?? null;
			if ( $block === null || ( $block->section_type ?? null ) !== 'resource_pool' ) {
				continue;
			}
			$held_map = self::as_array( $sheet_data[ $slug ] ?? [] );
			foreach ( (array) ( $block->definition->pools ?? [] ) as $pool ) {
				$name = (string) ( $pool->name ?? '' );
				if ( $name === '' ) {
					continue;
				}
				$start   = array_key_exists( $slug . '.' . $name, $resolved ) ? (int) $resolved[ $slug . '.' . $name ] : (int) ( $pool->default_start ?? 0 );
				$current = self::pool_permanent( $held_map[ $name ] ?? null, $start );

				if ( isset( $pool->raised_by ) ) {
					$dots = 0;
					for ( $d = 1; $d <= $current - $start; $d++ ) {
						if ( ! isset( $covered[ $slug . '|' . $name . '#' . $d ] ) ) {
							$dots++;
						}
					}
					if ( $dots > 0 ) {
						$found[] = [ 'kind' => 'raised_by', 'section' => $slug, 'pool' => $name, 'dots' => $dots ];
					}
				}
				if ( isset( $pool->book_max ) && $current > (int) $pool->book_max ) {
					$found[] = [ 'kind' => 'book_max', 'section' => $slug, 'pool' => $name, 'value' => $current, 'max' => (int) $pool->book_max ];
				}
			}
		}
		return $found;
	}

	// -------------------------------------------------------------------------
	// grant
	// -------------------------------------------------------------------------

	/**
	 * A grant step's entries, and which of them the draft sheet is missing.
	 *
	 * @param array<string,mixed> $step
	 * @param array<string,object> $blocks
	 * @param array<string,mixed> $sheet_data
	 * @param array<string,mixed> $resolved
	 * @return array<int,array<string,mixed>>
	 */
	private static function process_grant( array $step, array $blocks, array $sheet_data, array $resolved ): array {
		$section = (string) ( $step['section'] ?? '' );
		$entries = self::grant_entries( $step, $blocks, $sheet_data, $resolved );

		$missing = [];
		foreach ( $entries as $entry ) {
			if ( ! self::grant_held( $section, $entry, $sheet_data ) ) {
				$missing[] = [ 'section' => $section ] + $entry;
			}
		}
		return $missing;
	}

	/**
	 * @param array<string,mixed> $step
	 * @param array<string,object> $blocks
	 * @param array<string,mixed> $sheet_data
	 * @param array<string,mixed> $resolved
	 * @return array<int,array<string,mixed>>
	 */
	private static function grant_entries( array $step, array $blocks, array $sheet_data, array $resolved ): array {
		if ( isset( $step['entries'] ) && is_array( $step['entries'] ) ) {
			$entries = [];
			foreach ( self::as_list( $step['entries'] ) as $entry ) {
				$entry = self::as_array( $entry );
				if ( is_string( $entry['name'] ?? null ) && $entry['name'] !== '' ) {
					$entries[] = $entry;
				}
			}
			return $entries;
		}

		$from = self::as_array( $step['from'] ?? null );
		if ( $from === [] ) {
			return [];
		}
		$name = self::map_lookup( (string) ( $from['map'] ?? '' ), self::as_list_of_strings( $from['by'] ?? [] ), $sheet_data, $blocks );
		if ( $name === null ) {
			return [];
		}
		$level = $step['level'] ?? null;
		return [ $level !== null ? [ 'name' => $name, 'level' => (int) $level ] : [ 'name' => $name ] ];
	}

	/**
	 * @param array<string,mixed> $entry
	 * @param array<string,mixed> $sheet_data
	 */
	private static function grant_held( string $section, array $entry, array $sheet_data ): bool {
		$rows = self::as_list( $sheet_data[ $section ] ?? [] );
		$name = (string) ( $entry['name'] ?? '' );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ( $row['name'] ?? null ) !== $name ) {
				continue;
			}
			if ( isset( $entry['power_name'] ) ) {
				if ( ( $row['power_name'] ?? null ) === $entry['power_name'] ) {
					return true;
				}
				continue;
			}
			if ( isset( $entry['level'] ) ) {
				if ( (int) ( $row['level'] ?? 0 ) >= (int) $entry['level'] ) {
					return true;
				}
				continue;
			}
			if ( isset( $entry['count'] ) ) {
				if ( (int) ( $row['count'] ?? 1 ) >= (int) $entry['count'] ) {
					return true;
				}
				continue;
			}
			return true;
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// start
	// -------------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $step
	 * @param array<string,object> $blocks
	 * @param array<string,mixed> $sheet_data
	 * @param array<string,mixed> $resolved Earlier `start` steps' own resolved values, by target.
	 */
	private static function resolve_start( array $step, array $blocks, array $sheet_data, array $resolved ): float {
		if ( array_key_exists( 'value', $step ) ) {
			return (float) $step['value'];
		}
		if ( isset( $step['lookup'] ) ) {
			$lookup = self::as_array( $step['lookup'] );
			$value  = self::map_lookup( (string) ( $lookup['map'] ?? '' ), self::as_list_of_strings( $lookup['by'] ?? [] ), $sheet_data, $blocks );
			return $value !== null && is_numeric( $value ) ? (float) $value : 0.0;
		}
		if ( isset( $step['formula'] ) ) {
			$of = self::as_list_of_strings( $step['of'] ?? [] );
			$values = array_map( static fn( string $ref ): float => self::target_value( $ref, $sheet_data, $resolved, $blocks ), $of );
			switch ( (string) $step['formula'] ) {
				case 'average_up':
					return $values === [] ? 0.0 : (float) ceil( array_sum( $values ) / count( $values ) );
				case 'sum_top_two':
					rsort( $values );
					return (float) array_sum( array_slice( $values, 0, 2 ) );
				case 'equal':
					return $values[0] ?? 0.0;
			}
		}
		return 0.0;
	}

	// -------------------------------------------------------------------------
	// prioritized
	// -------------------------------------------------------------------------

	/**
	 * @param array<string,mixed>  $step
	 * @param array<string,object> $blocks
	 * @param array<string,mixed>  $sheet_data
	 * @param array<string,bool>   $covered
	 * @return array<string,mixed>
	 */
	private static function process_prioritized( array $step, bool $applies, array $blocks, array $sheet_data, array &$covered ): array {
		$sections = self::as_list_of_strings( $step['sections'] ?? [] );
		$amounts  = array_map( 'intval', self::as_list( $step['amounts'] ?? [] ) );
		if ( ! $applies || $sections === [] ) {
			return [ 'kind' => 'prioritized', 'label' => (string) ( $step['label'] ?? '' ), 'applies' => $applies, 'sections' => [] ];
		}

		$used = [];
		foreach ( $sections as $slug ) {
			$definition = $blocks[ $slug ]->definition ?? null;
			$rows       = self::as_list( $sheet_data[ $slug ] ?? [] );
			$total      = 0;
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! empty( $row['custom'] ) ) {
					continue;
				}
				$total += Cost_Engine::rows_are_purchases( $definition ) ? 1 : max( 1, (int) ( $row['count'] ?? 1 ) );
			}
			$used[ $slug ] = $total;
		}

		$order = $sections;
		usort( $order, static fn( string $a, string $b ): int => $used[ $b ] <=> $used[ $a ] );

		$result = [];
		foreach ( $order as $i => $slug ) {
			$allowed = $amounts[ $i ] ?? 0;
			$result[] = [ 'section' => $slug, 'used' => $used[ $slug ], 'allowed' => $allowed, 'over' => $used[ $slug ] > $allowed ];

			$rows       = self::as_list( $sheet_data[ $slug ] ?? [] );
			$definition = $blocks[ $slug ]->definition ?? null;
			$remaining  = $allowed;
			foreach ( $rows as $row ) {
				if ( $remaining <= 0 ) {
					break;
				}
				if ( ! is_array( $row ) || ! empty( $row['custom'] ) ) {
					continue;
				}
				$identity = $slug . '|' . ( Trait_Identity::of_row( $definition, $row ) ?? (string) ( $row['name'] ?? '' ) );
				$dots     = Cost_Engine::rows_are_purchases( $definition ) ? 1 : max( 1, (int) ( $row['count'] ?? 1 ) );
				for ( $d = 1; $d <= $dots && $remaining > 0; $d++ ) {
					$covered[ $identity . '#' . $d ] = true;
					$remaining--;
				}
			}
		}

		return [ 'kind' => 'prioritized', 'label' => (string) ( $step['label'] ?? '' ), 'applies' => true, 'sections' => $result ];
	}

	// -------------------------------------------------------------------------
	// budget
	// -------------------------------------------------------------------------

	/**
	 * @param array<string,mixed>  $step
	 * @param array<string,object> $blocks
	 * @param array<string,mixed>  $sheet_data
	 * @param array<string,bool>   $covered
	 * @return array<string,mixed>
	 */
	private static function process_budget( array $step, bool $applies, object $character, object $stack, array $blocks, array $sheet_data, array &$covered ): array {
		$section = (string) ( $step['section'] ?? '' );
		$count   = (int) ( $step['count'] ?? 0 );
		if ( ! $applies || $section === '' ) {
			return [ 'kind' => 'budget', 'label' => (string) ( $step['label'] ?? '' ), 'applies' => $applies, 'section' => $section, 'used' => 0, 'allowed' => $count, 'over' => false, 'quotas' => [] ];
		}

		$block      = $blocks[ $section ] ?? null;
		$definition = $block->definition ?? null;
		$type       = $block->section_type ?? null;
		$filter     = self::as_array( $step['filter'] ?? [] );

		$in_type_fn = self::in_type_resolver( $filter, $character, $section, $stack, $blocks );
		$max_tier   = isset( $filter['tier'] ) ? self::tier_index( $definition, (string) $filter['tier'] ) : null;
		$min_tier   = isset( $filter['min_tier'] ) ? self::tier_index( $definition, (string) $filter['min_tier'] ) : null;

		$units = self::section_units( $section, $type, $definition, $sheet_data[ $section ] ?? [], $in_type_fn, $blocks );

		$eligible = [];
		foreach ( $units as $unit ) {
			if ( isset( $covered[ $unit->identity ] ) ) {
				continue;
			}
			if ( ! empty( $filter['in_type'] ) && ! $unit->in_type ) {
				continue;
			}
			if ( $max_tier !== null || $min_tier !== null ) {
				$unit_tier = $unit->tier !== null ? self::tier_index( $definition, $unit->tier ) : null;
				if ( $unit_tier === null ) {
					continue;
				}
				if ( $max_tier !== null && $unit_tier > $max_tier ) {
					continue;
				}
				if ( $min_tier !== null && $unit_tier < $min_tier ) {
					continue;
				}
			}
			$eligible[] = $unit;
		}

		$used = count( $eligible );
		foreach ( array_slice( $eligible, 0, $count ) as $unit ) {
			$covered[ $unit->identity ] = true;
		}

		$quotas = [];
		foreach ( self::as_list( $step['quotas'] ?? [] ) as $quota ) {
			$quota = self::as_array( $quota );
			$test  = self::as_array( $quota['test'] ?? [] );
			$met   = 0;
			foreach ( $eligible as $unit ) {
				$family = self::block_family( $blocks[ $section ] ?? null, $unit->name );
				if ( In_Type::passes_any( [ $test ], $unit->name, $family, $sheet_data, self::block_loader( $blocks ) ) ) {
					$met++;
				}
			}
			$min      = (int) ( $quota['min'] ?? 0 );
			$quotas[] = [ 'label' => (string) ( $quota['label'] ?? '' ), 'min' => $min, 'met' => $met, 'ok' => $met >= $min ];
		}

		return [
			'kind'    => 'budget',
			'label'   => (string) ( $step['label'] ?? '' ),
			'applies' => true,
			'section' => $section,
			'used'    => $used,
			'allowed' => $count,
			'over'    => $used > $count,
			'quotas'  => $quotas,
		];
	}

	// -------------------------------------------------------------------------
	// earned / free
	// -------------------------------------------------------------------------

	/**
	 * @param array<string,mixed>             $step
	 * @param array<string,object>            $blocks
	 * @param array<string,mixed>             $sheet_data
	 * @param array<string,array<string,int>> $pools
	 * @return array<string,mixed>
	 */
	private static function process_earned( array $step, bool $applies, array $blocks, array $sheet_data, array &$pools ): array {
		$pool = (string) ( $step['pool'] ?? '' );
		if ( ! $applies || $pool === '' ) {
			return [ 'kind' => 'earned', 'label' => (string) ( $step['label'] ?? '' ), 'applies' => $applies, 'pool' => $pool, 'points' => 0 ];
		}
		if ( ! isset( $pools[ $pool ] ) ) {
			$pools[ $pool ] = [ 'own' => 0, 'earned' => 0, 'spent' => 0 ];
		}

		$total = 0;
		foreach ( self::as_list( $step['sources'] ?? [] ) as $source ) {
			$source     = self::as_array( $source );
			$slug       = (string) ( $source['section'] ?? '' );
			$rate       = $source['rate'] ?? 1;
			$rows       = self::as_list( $sheet_data[ $slug ] ?? [] );
			$definition = $blocks[ $slug ]->definition ?? (object) [];
			$sub        = 0;
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! empty( $row['custom'] ) ) {
					continue;
				}
				if ( $rate === 'value' ) {
					$priced = Cost_Engine::price_held_trait_list_item( $definition, $row, $slug );
					$sub   += $priced['xp'] !== null ? abs( (int) $priced['xp'] ) : 0;
				} else {
					$dots = Cost_Engine::rows_are_purchases( $definition ) ? 1 : max( 1, (int) ( $row['count'] ?? 1 ) );
					$sub += $dots * (int) $rate;
				}
			}
			if ( isset( $source['max'] ) ) {
				$sub = min( $sub, (int) $source['max'] );
			}
			$total += $sub;
		}
		if ( isset( $step['max'] ) ) {
			$total = min( $total, (int) $step['max'] );
		}

		$pools[ $pool ]['earned'] += $total;
		return [ 'kind' => 'earned', 'label' => (string) ( $step['label'] ?? '' ), 'applies' => true, 'pool' => $pool, 'points' => $total ];
	}

	/**
	 * @param array<string,mixed>             $step
	 * @param array<int,object>               $sections   The stack's own decoded `stack_definition.sections`.
	 * @param array<string,object>            $blocks
	 * @param array<string,mixed>             $sheet_data
	 * @param array<string,bool>              $covered
	 * @param array<string,mixed>             $resolved
	 * @param array<string,array<string,int>> $pools
	 * @return array<string,mixed>
	 */
	private static function process_free( array $step, bool $applies, object $character, object $stack, array $sections, array $blocks, array $sheet_data, array &$covered, array $resolved, array &$pools ): array {
		$pool = (string) ( $step['pool'] ?? '' );
		if ( ! $applies || $pool === '' ) {
			return [ 'kind' => 'free', 'label' => (string) ( $step['label'] ?? '' ), 'applies' => $applies, 'pool' => $pool, 'spent' => 0 ];
		}
		if ( ! isset( $pools[ $pool ] ) ) {
			$pools[ $pool ] = [ 'own' => 0, 'earned' => 0, 'spent' => 0 ];
		}
		$pools[ $pool ]['own'] += (int) ( $step['points'] ?? 0 );

		$rates       = self::as_array( $step['rates'] ?? [] );
		$section_order = array_column( $sections, 'block_slug' );
		$spent       = 0;

		foreach ( $section_order as $slug ) {
			if ( $slug === '' || ! isset( $blocks[ $slug ] ) ) {
				continue;
			}
			$block      = $blocks[ $slug ];
			$definition = $block->definition ?? null;
			$type       = $block->section_type ?? null;
			if ( $type === 'identity_field' || ! empty( $definition->negative ) ) {
				continue;
			}
			$in_type_fn = In_Type::check( $character, $slug, $stack, $blocks );
			$units      = self::section_units( $slug, $type, $definition, $sheet_data[ $slug ] ?? [], $in_type_fn, $blocks, $resolved );

			foreach ( $units as $unit ) {
				if ( isset( $covered[ $unit->identity ] ) ) {
					continue;
				}
				$rate_key = array_key_exists( $slug . '.' . $unit->name, $rates ) ? $slug . '.' . $unit->name : ( array_key_exists( $slug, $rates ) ? $slug : null );
				if ( $rate_key === null ) {
					continue;
				}
				$rate = $rates[ $rate_key ] === 'value' ? $unit->price : (int) $rates[ $rate_key ];
				if ( $rate === null ) {
					continue;
				}
				$balance = $pools[ $pool ]['own'] + $pools[ $pool ]['earned'] - $pools[ $pool ]['spent'];
				if ( $rate <= $balance ) {
					$pools[ $pool ]['spent'] += $rate;
					$covered[ $unit->identity ] = true;
					$spent += $rate;
				}
			}
		}

		return [ 'kind' => 'free', 'label' => (string) ( $step['label'] ?? '' ), 'applies' => true, 'pool' => $pool, 'spent' => $spent ];
	}

	/**
	 * @param array<string,array<string,int>> $pools
	 * @return array<string,array<string,int>>
	 */
	private static function pool_report( array $pools ): array {
		$report = [];
		foreach ( $pools as $name => $pool ) {
			$report[ $name ] = [
				'own'    => $pool['own'],
				'earned' => $pool['earned'],
				'spent'  => $pool['spent'],
				'left'   => $pool['own'] + $pool['earned'] - $pool['spent'],
			];
		}
		return $report;
	}

	// -------------------------------------------------------------------------
	// limit
	// -------------------------------------------------------------------------

	/**
	 * @param array<string,mixed>   $step
	 * @param array<string,object>  $blocks
	 * @param array<string,mixed>   $sheet_data
	 * @param array<string,mixed>   $resolved
	 * @return array<int,array<string,mixed>>
	 */
	private static function process_limit( array $step, array $blocks, array $sheet_data, array $resolved ): array {
		$target = (string) ( $step['section'] ?? '' );
		[ $slug, $name ] = array_pad( explode( '.', $target, 2 ), 2, null );
		$flags  = [];

		if ( isset( $step['max_points'] ) ) {
			$total = self::section_points( (string) $slug, $sheet_data );
			$max   = (int) $step['max_points'];
			if ( $total > $max ) {
				$flags[] = [ 'target' => $target, 'reason' => 'max_points', 'value' => $total, 'max' => $max ];
			}
		}

		if ( isset( $step['max_rating'] ) || isset( $step['min_rating'] ) || isset( $step['ceiling'] ) ) {
			$rows = $name !== null
				? array_values( array_filter( self::as_list( $sheet_data[ $slug ] ?? [] ), static fn( $r ): bool => is_array( $r ) && ( $r['name'] ?? null ) === $name ) )
				: self::as_list( $sheet_data[ $slug ] ?? [] );

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$rating   = (float) ( $row['level'] ?? $row['count'] ?? 0 );
				$row_name = (string) ( $row['name'] ?? $name ?? '' );

				if ( isset( $step['max_rating'] ) ) {
					$max = self::resolve_max_rating( $step['max_rating'], $sheet_data, $resolved, $blocks );
					if ( $rating > $max ) {
						$flags[] = [ 'target' => $slug . '.' . $row_name, 'reason' => 'max_rating', 'value' => $rating, 'max' => $max ];
					}
				}
				if ( isset( $step['min_rating'] ) && $rating < (float) $step['min_rating'] ) {
					$flags[] = [ 'target' => $slug . '.' . $row_name, 'reason' => 'min_rating', 'value' => $rating, 'min' => (float) $step['min_rating'] ];
				}
				if ( isset( $step['ceiling'] ) ) {
					$ceiling_ref = self::resolve_ceiling( $step['ceiling'], $slug, $sheet_data, $blocks );
					if ( $ceiling_ref !== null ) {
						$ceiling_value = self::target_value( $ceiling_ref, $sheet_data, $resolved, $blocks );
						if ( $rating > $ceiling_value ) {
							$flags[] = [ 'target' => $slug . '.' . $row_name, 'reason' => 'ceiling', 'value' => $rating, 'ceiling' => $ceiling_value, 'ceiling_target' => $ceiling_ref ];
						}
					}
				}
			}

			// A pool named directly (no per-row entries) is checked as one value.
			if ( $rows === [] && $name !== null ) {
				$value = self::target_value( $target, $sheet_data, $resolved, $blocks );
				if ( isset( $step['max_rating'] ) ) {
					$max = self::resolve_max_rating( $step['max_rating'], $sheet_data, $resolved, $blocks );
					if ( $value > $max ) {
						$flags[] = [ 'target' => $target, 'reason' => 'max_rating', 'value' => $value, 'max' => $max ];
					}
				}
				if ( isset( $step['min_rating'] ) && $value < (float) $step['min_rating'] ) {
					$flags[] = [ 'target' => $target, 'reason' => 'min_rating', 'value' => $value, 'min' => (float) $step['min_rating'] ];
				}
				if ( isset( $step['ceiling'] ) ) {
					$ceiling_ref = self::resolve_ceiling( $step['ceiling'], $slug, $sheet_data, $blocks );
					if ( $ceiling_ref !== null && $ceiling_ref !== $target ) {
						$ceiling_value = self::target_value( $ceiling_ref, $sheet_data, $resolved, $blocks );
						if ( $value > $ceiling_value ) {
							$flags[] = [ 'target' => $target, 'reason' => 'ceiling', 'value' => $value, 'ceiling' => $ceiling_value, 'ceiling_target' => $ceiling_ref ];
						}
					}
				}
			}
		}

		return $flags;
	}

	/**
	 * @param mixed                 $max_rating A number, or `{base, plus?, cap?}`.
	 * @param array<string,mixed>   $sheet_data
	 * @param array<string,mixed>   $resolved
	 * @param array<string,object>  $blocks
	 */
	private static function resolve_max_rating( $max_rating, array $sheet_data, array $resolved, array $blocks ): float {
		if ( is_numeric( $max_rating ) ) {
			return (float) $max_rating;
		}
		$rule  = self::as_array( $max_rating );
		$value = (float) ( $rule['base'] ?? 0 );
		if ( isset( $rule['plus'] ) ) {
			$value += self::target_value( (string) $rule['plus'], $sheet_data, $resolved, $blocks );
		}
		if ( isset( $rule['cap'] ) ) {
			$value = min( $value, (float) $rule['cap'] );
		}
		return $value;
	}

	/**
	 * @param mixed                $ceiling A `"block.Name"` string, `{named_by: "field"}`, or `{map: "block.mapName", by: [field,...]}`.
	 * @param array<string,mixed>  $sheet_data
	 * @param array<string,object> $blocks
	 */
	private static function resolve_ceiling( $ceiling, string $default_slug, array $sheet_data, array $blocks = [] ): ?string {
		if ( is_string( $ceiling ) ) {
			return $ceiling;
		}
		$rule = self::as_array( $ceiling );
		if ( isset( $rule['map'] ) ) {
			$named = self::map_lookup( (string) $rule['map'], self::as_list_of_strings( $rule['by'] ?? [] ), $sheet_data, $blocks );
			return $named !== null && $named !== '' ? $default_slug . '.' . $named : null;
		}
		if ( ! isset( $rule['named_by'] ) ) {
			return null;
		}
		$named = self::field_value( (string) $rule['named_by'], $sheet_data );
		return $named !== null && $named !== '' ? $default_slug . '.' . $named : null;
	}

	// -------------------------------------------------------------------------
	// Final XP pricing of every unit no grant, budget or free pool covered.
	// -------------------------------------------------------------------------

	/**
	 * @param array<int,object>     $sections The stack's own decoded `stack_definition.sections`.
	 * @param array<string,object>  $blocks
	 * @param array<string,mixed>   $sheet_data
	 * @param array<string,mixed>   $resolved
	 * @param array<string,bool>    $covered
	 */
	private static function price_uncovered( object $character, object $stack, array $sections, array $blocks, array $sheet_data, array $resolved, array $covered ): int {
		$needed = 0;
		foreach ( $sections as $section ) {
			$slug = (string) ( $section->block_slug ?? '' );
			if ( $slug === '' || ! isset( $blocks[ $slug ] ) ) {
				continue;
			}
			$block      = $blocks[ $slug ];
			$definition = $block->definition ?? null;
			$type       = $block->section_type ?? null;
			if ( $type === 'identity_field' || ! empty( $definition->negative ) ) {
				continue;
			}
			$in_type_fn = In_Type::check( $character, $slug, $stack, $blocks );
			$units      = self::section_units( $slug, $type, $definition, $sheet_data[ $slug ] ?? [], $in_type_fn, $blocks, $resolved );
			foreach ( $units as $unit ) {
				if ( isset( $covered[ $unit->identity ] ) || $unit->price === null ) {
					continue;
				}
				$needed += $unit->price;
			}
		}
		return $needed;
	}

	// -------------------------------------------------------------------------
	// Units: one dot, rung or pick, each independently priced.
	// -------------------------------------------------------------------------

	/**
	 * Every priceable unit a section's held rows carry - a trait_list dot, a tiered_power rung or pick, or a pool dot
	 * above its resolved start.
	 *
	 * @param mixed                 $held
	 * @param callable(string):bool $in_type_fn
	 * @param array<string,object>  $blocks
	 * @param array<string,mixed>   $resolved
	 * @param object                $definition
	 * @return array<int,object>
	 */
	private static function section_units( string $slug, ?string $type, $definition, $held, callable $in_type_fn, array $blocks, array $resolved = [] ): array {
		if ( $type === 'trait_list' ) {
			return self::trait_list_units( $slug, $definition, self::as_list( $held ), $blocks );
		}
		if ( $type === 'tiered_power' ) {
			return self::tiered_power_units( $slug, $definition, self::as_list( $held ), $in_type_fn );
		}
		if ( $type === 'resource_pool' ) {
			return self::pool_units( $slug, $definition, is_array( $held ) ? $held : [], $resolved );
		}
		return [];
	}

	/**
	 * @param array<string,object> $blocks
	 * @param object $definition
	 * @param array<int,mixed> $held
	 * @return array<int,object>
	 */
	private static function trait_list_units( string $slug, $definition, array $held, array $blocks ): array {
		$units = [];
		foreach ( $held as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['custom'] ) ) {
				continue;
			}
			$name = (string) ( $row['name'] ?? '' );
			if ( $name === '' ) {
				continue;
			}
			$item     = Trait_Alias_Resolver::find_item_by_name( (array) ( $definition->items ?? [] ), $name );
			$priced   = Cost_Engine::price_held_trait_list_item( $definition, $row, $slug, $blocks );
			$dots     = Cost_Engine::rows_are_purchases( $definition ) ? 1 : max( 1, (int) ( $row['count'] ?? 1 ) );
			$per_dot  = $priced['xp'] !== null ? (int) round( abs( $priced['xp'] ) / $dots ) : null;
			$identity = $slug . '|' . ( Trait_Identity::of_row( $definition, $row ) ?? $name );

			for ( $d = 1; $d <= $dots; $d++ ) {
				$units[] = (object) [
					'section'  => $slug,
					'identity' => $identity . '#' . $d,
					'name'     => $name,
					'tier'     => $item->tier ?? null,
					'group'    => $item->group ?? null,
					'subgroup' => $item->subgroup ?? null,
					'in_type'  => true,
					'price'    => $per_dot,
				];
			}
		}
		return $units;
	}

	/**
	 * @param callable(string):bool $in_type_fn
	 * @param object $definition
	 * @param array<int,mixed> $held
	 * @return array<int,object>
	 */
	private static function tiered_power_units( string $slug, $definition, array $held, callable $in_type_fn ): array {
		$units      = [];
		$sequential = ! empty( $definition->sequential );

		foreach ( $held as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['custom'] ) || ! empty( $row['keep_custom'] ) ) {
				continue;
			}
			$name = (string) ( $row['name'] ?? '' );
			if ( $name === '' ) {
				continue;
			}
			$power      = Trait_Alias_Resolver::find_power_by_name( (array) ( $definition->powers ?? [] ), $name );
			$in_type    = $in_type_fn( $name );
			$power_name = isset( $row['power_name'] ) && $row['power_name'] !== '' ? (string) $row['power_name'] : null;

			if ( $power_name !== null ) {
				$level = $power !== null ? Trait_Alias_Resolver::find_level_by_name( Power_Levels::all( $power ), $power_name ) : null;
				$price = Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', [ 'block_slug' => $slug, 'trait' => [ 'name' => $name, 'power_name' => $power_name ] ], $in_type );
				$units[] = (object) [
					'section'  => $slug,
					'identity' => $slug . '|' . Trait_Identity::of_power( $name, $power_name ),
					'name'     => $name,
					'tier'     => $level->tier ?? null,
					'group'    => $power->group ?? null,
					'subgroup' => $power->subgroup ?? null,
					'in_type'  => $in_type,
					'price'    => $price,
				];
				continue;
			}

			$level = (int) ( $row['level'] ?? 0 );
			if ( $level <= 0 ) {
				continue;
			}
			$identity = $slug . '|' . Trait_Identity::of_power( $name, null );

			if ( $sequential ) {
				$previous = 0;
				for ( $rung = 1; $rung <= $level; $rung++ ) {
					$cumulative = Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', [ 'block_slug' => $slug, 'trait' => [ 'name' => $name, 'level' => $rung ] ], $in_type );
					$units[]    = (object) [
						'section'  => $slug,
						'identity' => $identity . '#' . $rung,
						'name'     => $name,
						'tier'     => Cost_Engine::tier_for_rank( $definition, $rung ),
						'group'    => $power->group ?? null,
						'subgroup' => $power->subgroup ?? null,
						'in_type'  => $in_type,
						'price'    => $cumulative - $previous,
					];
					$previous = $cumulative;
				}
			} else {
				$price   = Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', [ 'block_slug' => $slug, 'trait' => [ 'name' => $name, 'level' => $level ] ], $in_type );
				$units[] = (object) [
					'section'  => $slug,
					'identity' => $identity,
					'name'     => $name,
					'tier'     => Cost_Engine::tier_for_rank( $definition, $level ),
					'group'    => $power->group ?? null,
					'subgroup' => $power->subgroup ?? null,
					'in_type'  => $in_type,
					'price'    => $price,
				];
			}
		}
		return $units;
	}

	/**
	 * @param array<string,mixed> $held_map
	 * @param array<string,mixed> $resolved
	 * @param object $definition
	 * @return array<int,object>
	 */
	private static function pool_units( string $slug, $definition, array $held_map, array $resolved ): array {
		$units = [];
		foreach ( (array) ( $definition->pools ?? [] ) as $pool ) {
			$name = (string) ( $pool->name ?? '' );
			if ( $name === '' ) {
				continue;
			}
			$default = (int) ( $pool->default_start ?? 0 );
			$start   = array_key_exists( $slug . '.' . $name, $resolved ) ? (int) $resolved[ $slug . '.' . $name ] : $default;
			$current = self::pool_permanent( $held_map[ $name ] ?? null, $start );
			$buy_down = ! empty( $pool->buy_down );
			$dots     = $buy_down ? max( 0, $start - $current ) : max( 0, $current - $start );
			if ( $dots <= 0 ) {
				continue;
			}

			$sheet_at_start = [ $slug => [ $name => $start ] ];
			$total = Cost_Engine::price_resource_pool_change( $sheet_at_start, $definition, $slug, [ 'values' => [ $name => $current ] ] );
			$per_dot = (int) round( $total / $dots );

			for ( $d = 1; $d <= $dots; $d++ ) {
				$units[] = (object) [
					'section'  => $slug,
					'identity' => $slug . '|' . $name . '#' . $d,
					'name'     => $name,
					'tier'     => null,
					'group'    => null,
					'subgroup' => null,
					'in_type'  => true,
					'price'    => $per_dot,
				];
			}
		}
		return $units;
	}

	/**
	 * @param mixed $value
	 */
	private static function pool_permanent( $value, int $default ): int {
		if ( is_array( $value ) ) {
			return (int) ( $value['permanent'] ?? $default );
		}
		return $value === null ? $default : (int) $value;
	}

	// -------------------------------------------------------------------------
	// in-type resolution for a budget's filter
	// -------------------------------------------------------------------------

	/**
	 * @param array<string,mixed>  $filter
	 * @param array<string,object> $blocks
	 * @return callable(string):bool
	 */
	private static function in_type_resolver( array $filter, object $character, string $section, object $stack, array $blocks ): callable {
		if ( isset( $filter['test'] ) ) {
			$test   = [ self::as_array( $filter['test'] ) ];
			$sheet  = is_array( $character->sheet_data ?? null ) ? $character->sheet_data : [];
			$loader = self::block_loader( $blocks );
			return static function ( string $name ) use ( $test, $sheet, $loader, $blocks, $section ) {
				$family = self::block_family( $blocks[ $section ] ?? null, $name );
				return In_Type::passes_any( $test, $name, $family, $sheet, $loader );
			};
		}
		return In_Type::check( $character, $section, $stack, $blocks );
	}

	/**
	 * @param object|null $block
	 */
	private static function block_family( ?object $block, string $name ): ?object {
		if ( $block === null || ! is_object( $block->definition ?? null ) ) {
			return null;
		}
		$definition = $block->definition;
		if ( isset( $definition->powers ) ) {
			return Trait_Alias_Resolver::find_power_by_name( (array) $definition->powers, $name );
		}
		return Trait_Alias_Resolver::find_item_by_name( (array) ( $definition->items ?? [] ), $name );
	}

	/**
	 * @param array<string,object> $blocks
	 * @return callable(string):?object
	 */
	private static function block_loader( array $blocks ): callable {
		return static fn( string $slug ): ?object => is_object( $blocks[ $slug ] ?? null ) ? $blocks[ $slug ] : null;
	}

	/**
	 * The tier vocabulary's index of a named rank, or null when the block declares none.
	 *
	 * @param object $definition
	 */
	private static function tier_index( $definition, string $tier ): ?int {
		$ranks = Cost_Engine::meta_ranks( $definition );
		if ( $ranks === null ) {
			return null;
		}
		$index = array_search( $tier, $ranks, true );
		return $index === false ? null : (int) $index;
	}

	// -------------------------------------------------------------------------
	// `when`, map lookups, and reading a value off the draft sheet.
	// -------------------------------------------------------------------------

	/**
	 * @param mixed $when A single test, a list of tests (all must hold), or null.
	 * @param array<string,mixed> $sheet_data
	 */
	private static function step_applies( $when, array $sheet_data ): bool {
		if ( $when === null ) {
			return true;
		}
		if ( is_array( $when ) && array_is_list( $when ) ) {
			foreach ( $when as $one ) {
				if ( ! self::step_applies( $one, $sheet_data ) ) {
					return false;
				}
			}
			return true;
		}
		$when  = self::as_array( $when );
		$field = (string) ( $when['field'] ?? '' );
		$values = self::field_values( $field, $sheet_data );

		if ( array_key_exists( 'set', $when ) ) {
			return ( $values !== [] ) === (bool) $when['set'];
		}
		if ( array_key_exists( 'not', $when ) ) {
			return array_intersect( $values, self::as_list_of_strings( $when['not'] ) ) === [];
		}
		return array_intersect( $values, self::as_list_of_strings( $when['is'] ?? [] ) ) !== [];
	}

	/**
	 * A map kept in an identity block, keyed by the given fields tried in order.
	 *
	 * @param string[]              $by
	 * @param array<string,mixed>   $sheet_data
	 * @param array<string,object>  $blocks
	 * @return mixed|null
	 */
	private static function map_lookup( string $map_ref, array $by, array $sheet_data, array $blocks ) {
		[ $slug, $key ] = array_pad( explode( '.', $map_ref, 2 ), 2, '' );
		$block = $blocks[ $slug ] ?? null;
		$map   = $block !== null ? self::as_array( $block->definition->$key ?? [] ) : [];
		foreach ( $by as $field ) {
			foreach ( self::field_values( $slug . '.' . $field, $sheet_data ) as $value ) {
				if ( isset( $map[ $value ] ) ) {
					return $map[ $value ];
				}
			}
		}
		return null;
	}

	/**
	 * The current effective value of one `"block.Name"` target: an earlier `start` step's own resolved value,
	 * whatever the draft sheet already holds for it, or a resource pool's own declared `default_start` when the
	 * draft carries no value of its own for it yet.
	 *
	 * @param array<string,mixed>   $sheet_data
	 * @param array<string,mixed>   $resolved
	 * @param array<string,object>  $blocks
	 */
	private static function target_value( string $ref, array $sheet_data, array $resolved, array $blocks ): float {
		if ( array_key_exists( $ref, $resolved ) ) {
			return (float) $resolved[ $ref ];
		}
		[ $slug, $name ] = array_pad( explode( '.', $ref, 2 ), 2, '' );
		$section = $sheet_data[ $slug ] ?? null;
		if ( is_array( $section ) ) {
			if ( array_key_exists( $name, $section ) ) {
				return (float) self::pool_permanent( $section[ $name ], (int) self::pool_default_start( $blocks, $slug, $name ) );
			}
			foreach ( $section as $row ) {
				if ( is_array( $row ) && ( $row['name'] ?? null ) === $name ) {
					return (float) ( $row['level'] ?? $row['count'] ?? 0 );
				}
			}
		}
		return self::pool_default_start( $blocks, $slug, $name );
	}

	/**
	 * A resource pool's own declared `default_start`, or 0 when the block is not that pool's own.
	 *
	 * @param array<string,object> $blocks
	 */
	private static function pool_default_start( array $blocks, string $slug, string $name ): float {
		$definition = $blocks[ $slug ]->definition ?? null;
		if ( ! is_object( $definition ) ) {
			return 0.0;
		}
		foreach ( (array) ( $definition->pools ?? [] ) as $pool ) {
			if ( (string) ( $pool->name ?? '' ) === $name ) {
				return (float) ( $pool->default_start ?? 0 );
			}
		}
		return 0.0;
	}

	/**
	 * One identity field's single string value.
	 *
	 * @param array<string,mixed> $sheet_data
	 */
	private static function field_value( string $field, array $sheet_data ): ?string {
		$values = self::field_values( $field, $sheet_data );
		return $values[0] ?? null;
	}

	/**
	 * The values a draft sheet holds for one "block_slug.Field".
	 *
	 * @param array<string,mixed> $sheet_data
	 * @return string[]
	 */
	private static function field_values( string $field, array $sheet_data ): array {
		[ $slug, $name ] = array_pad( explode( '.', $field, 2 ), 2, '' );
		$value  = is_array( $sheet_data[ $slug ] ?? null ) ? ( $sheet_data[ $slug ][ $name ] ?? null ) : null;
		$values = is_array( $value ) ? $value : [ $value ];
		return array_values( array_filter( array_map( static fn( $v ): string => is_scalar( $v ) ? trim( (string) $v ) : '', $values ), static fn( string $v ): bool => $v !== '' ) );
	}

	/**
	 * The total priced points a section's held rows carry (a Merit list's own points, for `max_points`).
	 *
	 * @param array<string,mixed> $sheet_data
	 */
	private static function section_points( string $slug, array $sheet_data ): int {
		$rows = self::as_list( $sheet_data[ $slug ] ?? [] );
		$total = 0;
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$total += (int) ( $row['count'] ?? 1 );
			}
		}
		return $total;
	}

	/**
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	private static function as_array( $value ): array {
		if ( is_object( $value ) ) {
			return json_decode( (string) wp_json_encode( $value ), true ) ?: [];
		}
		return is_array( $value ) ? $value : [];
	}

	/**
	 * @param mixed $value
	 * @return array<int,mixed>
	 */
	private static function as_list( $value ): array {
		if ( is_object( $value ) ) {
			$value = json_decode( (string) wp_json_encode( $value ), true ) ?: [];
		}
		return is_array( $value ) ? array_values( $value ) : [];
	}

	/**
	 * @param mixed $value
	 * @return string[]
	 */
	private static function as_list_of_strings( $value ): array {
		return array_values( array_map( 'strval', self::as_list( $value ) ) );
	}
}
