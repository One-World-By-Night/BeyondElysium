<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * The 1.3.3 re-key planner (design §3.5): what a character's `sheet_data` becomes when its
 * install moves from the shared GVM-era blocks (`met-abilities`, `met-merits`, `met-flaws`,
 * `werewolf-rites`) to the declared per-stack ones - and which custom rows can be safely
 * matched to a real catalog item on the way.
 *
 * Pure: no database, no WordPress. The caller resolves every block definition itself
 * (`Schema_Block::find_for_game()`, so a chronicle's fork is honored) and hands them in as
 * decoded objects, the same shape `Trait_Identity` already reads.
 *
 * **Deterministic only.** A custom row is re-keyed when one of six ordered tiers resolves it to
 * exactly one catalog item, and never on a fuzzy guess - a fuzzy candidate is only ever
 * *suggested* in the record. Five guards keep any row that would change its meaning exactly as
 * it is. The re-key never merges two rows, never writes XP, never touches a tiered row's
 * content and never moves a row except along a declared `replaces` pair (§3.11).
 *
 * **A catalog row is only ever respelled.** A moved catalog row whose name the replacement block
 * does not carry exactly is renamed to the declared spelling when a name-equivalence tier
 * (normalized, alias, decoration) resolves it to exactly one item there - the legacy and declared
 * catalogs spell some items differently in case, a hyphen or an apostrophe (R10). Nothing else
 * about the row changes, and one no tier resolves stays a retention gap.
 */
class Custom_Rekey {

	/** Trailing markers a Grapevine export or a player leaves on a name, stripped by the decoration tier. */
	private const DECORATION_CHARS = '*^†#';

	/**
	 * `counts.respelled` is the catalog rows renamed to the declared spelling; `rekeyed`, `kept_custom`
	 * and `by_tier` describe custom entries only, so those figures stay comparable across releases.
	 *
	 * @param array<string,mixed>  $sheet_data      The character's current `sheet_data`.
	 * @param array<string,object> $blocks          Block slug => decoded definition, for every block the
	 *                                              plan may read (the replacement targets and any own block
	 *                                              holding a custom row).
	 * @param array<string,string> $replacement_map Retired slug => live slug, this character's stack.
	 * @param array{suggestions?:bool} $options     `suggestions` (default true): attach `Fuzzy_Matcher`
	 *                                              candidates to a row that stays custom. `apply()` turns it off -
	 *                                              a suggestion is for the plan CSV, never for a write.
	 * @return array{
	 *     sheet_data: array<string,mixed>,
	 *     records: array<int,array<string,mixed>>,
	 *     counts: array<string,mixed>,
	 *     retention_gaps: array<int,array<string,mixed>>,
	 *     duplicates: array<int,array<string,mixed>>,
	 *     changed: bool
	 * }
	 */
	public static function plan_character( array $sheet_data, array $blocks, array $replacement_map, array $options = [] ): array {
		$suggest = $options['suggestions'] ?? true;
		$new     = $sheet_data;
		$moved   = []; // live slug => the retired slug that sent it rows.
		$offsets = []; // live slug => [ index of the first moved row in the new list, how many moved ].
		$index   = []; // block slug => index_items(), built once per block per plan.
		$counts  = [ 'moved_rows' => 0, 'rekeyed' => 0, 'respelled' => 0, 'kept_custom' => 0, 'tiered_custom' => 0, 'by_tier' => [], 'by_reason' => [] ];

		// 1. Block move: every row of a retired key appends, in order, to its replacement.
		foreach ( $replacement_map as $retired => $live ) {
			if ( ! array_key_exists( $retired, $new ) || ! self::is_list( $new[ $retired ] ) ) {
				continue;
			}
			$rows                 = array_values( $new[ $retired ] );
			$existing             = self::is_list( $new[ $live ] ?? null ) ? array_values( $new[ $live ] ) : [];
			$new[ $live ]         = array_merge( $existing, $rows );
			$counts['moved_rows'] += count( $rows );
			$moved[ $live ]       = $retired;
			$offsets[ $live ]     = [ count( $existing ), count( $rows ) ];
			unset( $new[ $retired ] );
		}

		// 1b. What the move itself puts at risk (R3): a catalog row the replacement block does not
		// carry, and identities that were already doubled before anything moved.
		[ $retention_gaps, $duplicates, $respell ] = self::audit_moved_rows( $new, $blocks, $moved, $offsets, $index );

		// 2. Decide every custom row, in its own (or its replacement) block's terms.
		$plans = []; // "slug" => [ index => [ base, decision ] ] - a `rekey` decision may still lose to a guard below.
		foreach ( $new as $slug => $rows ) {
			if ( ! self::is_list( $rows ) ) {
				continue;
			}
			$definition = $blocks[ $slug ] ?? null;
			foreach ( $rows as $i => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$base = [ 'block_from' => $moved[ $slug ] ?? $slug, 'block_to' => $slug, 'index' => $i, 'from' => (string) ( $row['name'] ?? '' ) ];

				if ( isset( $respell[ $slug ][ $i ] ) ) {
					$plans[ $slug ][ $i ] = [
						'base'     => $base + [ 'catalog' => true ],
						'decision' => [ 'outcome' => 'rekey', 'tier' => $respell[ $slug ][ $i ]['tier'], 'to' => $respell[ $slug ][ $i ]['to'], 'home' => null, 'label' => null ],
					];
					continue;
				}
				if ( empty( $row['custom'] ) ) {
					continue;
				}

				if ( ! is_object( $definition ) ) {
					$plans[ $slug ][ $i ] = [ 'base' => $base, 'decision' => [ 'outcome' => 'kept', 'reason' => 'block_unresolved' ] ];
					continue;
				}
				if ( isset( $definition->powers ) && ! isset( $definition->items ) ) {
					$counts['tiered_custom']++;
					continue;
				}
				$index[ $slug ]       ??= self::index_items( $definition );
				$plans[ $slug ][ $i ] = [ 'base' => $base, 'decision' => self::decide( $row, $definition, $index[ $slug ], $suggest ) ];
			}
		}

		// 3. Collision guard, per non-atomic block: a candidate whose new identity is shared by
		// another row there - one already held, or another candidate - stays exactly as it is.
		foreach ( $plans as $slug => $block_plans ) {
			$definition = $blocks[ $slug ] ?? null;
			if ( ! is_object( $definition ) || ! empty( $definition->atomic ) ) {
				continue;
			}
			$projected = [];
			foreach ( $new[ $slug ] as $i => $row ) {
				$decision        = $block_plans[ $i ]['decision'] ?? null;
				$projected[ $i ] = is_array( $row ) && $decision !== null && $decision['outcome'] === 'rekey'
					? self::rekeyed_row( $row, $decision )
					: $row;
			}
			$held = [];
			foreach ( $projected as $row ) {
				$identity = is_array( $row ) ? Trait_Identity::of_row( $definition, $row ) : null;
				if ( $identity !== null ) {
					$held[ $identity ] = ( $held[ $identity ] ?? 0 ) + 1;
				}
			}
			foreach ( $block_plans as $i => $plan ) {
				if ( $plan['decision']['outcome'] !== 'rekey' ) {
					continue;
				}
				$identity = Trait_Identity::of_row( $definition, $projected[ $i ] );
				if ( $identity !== null && $held[ $identity ] > 1 ) {
					$plans[ $slug ][ $i ]['decision'] = [ 'outcome' => 'kept', 'reason' => 'collision', 'would_be' => $plan['decision']['to'] ];
				}
			}
		}

		// 4. Write the survivors and report every custom row's outcome.
		$records = [];
		foreach ( $plans as $slug => $block_plans ) {
			foreach ( $block_plans as $i => $plan ) {
				$decision = $plan['decision'];
				$catalog  = ! empty( $plan['base']['catalog'] );
				if ( $decision['outcome'] === 'rekey' ) {
					$new[ $slug ][ $i ] = self::rekeyed_row( $new[ $slug ][ $i ], $decision );
					$records[]          = $plan['base'] + [
						'outcome' => 'rekeyed',
						'to'      => $decision['to'],
						'tier'    => $decision['tier'],
						'home'    => $decision['home'],
						'label'   => $decision['label'],
					];
					if ( $catalog ) {
						$counts['respelled']++;
						continue;
					}
					$counts['rekeyed']++;
					$counts['by_tier'][ $decision['tier'] ] = ( $counts['by_tier'][ $decision['tier'] ] ?? 0 ) + 1;
					continue;
				}
				if ( $catalog ) {
					// A catalog row whose respelling lost to a guard is not a custom entry left custom:
					// it is a row the declared block cannot resolve, so it is a gap.
					$retention_gaps[] = [ 'block_from' => $plan['base']['block_from'], 'block_to' => $slug, 'index' => $i, 'name' => $plan['base']['from'], 'reason' => $decision['reason'] ];
					continue;
				}
				$records[] = $plan['base'] + [ 'outcome' => 'kept' ] + array_diff_key( $decision, [ 'outcome' => 1 ] );
				$counts['kept_custom']++;
				$counts['by_reason'][ $decision['reason'] ] = ( $counts['by_reason'][ $decision['reason'] ] ?? 0 ) + 1;
			}
		}

		return [
			'sheet_data'     => $new,
			'records'        => $records,
			'counts'         => $counts,
			'retention_gaps' => $retention_gaps,
			'duplicates'     => $duplicates,
			'changed'        => $new !== $sheet_data,
		];
	}

	/**
	 * The two things a block move can get wrong without changing a single value (§3.5 "Output"):
	 *
	 * - **Retention gap.** A moved catalog row - one the character holds as a real catalog entry,
	 *   not a custom one - whose name the replacement block does not carry, exactly or after the
	 *   decoration strip. The row still moves, but the sheet would show it as a name its new
	 *   block cannot resolve; `apply()` refuses the whole install until every gap is understood.
	 *   A missing replacement definition is one gap for the block, not one per row - nothing about
	 *   the rows could be judged. Only moved blocks are audited: a non-moved block's catalog rows
	 *   are exactly where they were.
	 * - **Pre-existing duplicate.** Two moved catalog rows that already share one identity in a
	 *   non-atomic block (`Trait_Identity`) - a sheet that was doubled before this ever ran.
	 *   Reported and never touched: the re-key never merges two rows (§3.11).
	 *
	 * @param array<string,mixed>  $new     `sheet_data` after the move.
	 * @param array<string,object> $blocks
	 * @param array<string,string> $moved   live slug => retired slug.
	 * @param array<string,array{0:int,1:int}> $offsets live slug => [ first moved index, count ].
	 * @param array<string,array<string,mixed>> $index  Per-block lookup tables, filled as blocks are met.
	 * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:array<string,array<int,array{tier:string,to:string}>>}
	 *         `[ retention gaps, duplicates, respellings ]` - the last is live slug => row index => the
	 *         tier that resolved it and the declared name it becomes.
	 */
	private static function audit_moved_rows( array $new, array $blocks, array $moved, array $offsets, array &$index ): array {
		$gaps       = [];
		$duplicates = [];
		$respell    = [];
		foreach ( $offsets as $live => [ $start, $length ] ) {
			$definition = $blocks[ $live ] ?? null;
			if ( ! is_object( $definition ) ) {
				$gaps[] = [ 'block_from' => $moved[ $live ], 'block_to' => $live, 'index' => null, 'name' => null, 'reason' => 'block_unresolved' ];
				continue;
			}
			if ( ! isset( $definition->items ) ) {
				continue; // A tiered replacement has no item list to hold a row to.
			}
			$index[ $live ] ??= self::index_items( $definition );

			$identities = [];
			for ( $k = $start; $k < $start + $length; $k++ ) {
				$row = $new[ $live ][ $k ];
				if ( ! is_array( $row ) || ! empty( $row['custom'] ) || ! is_string( $row['name'] ?? null ) ) {
					continue;
				}
				$name = $row['name'];
				if ( ! isset( $index[ $live ]['exact'][ $name ] ) && ! isset( $index[ $live ]['exact'][ self::strip_decoration( $name ) ] ) ) {
					$found = self::respelling( $row, $index[ $live ] );
					if ( isset( $found['to'] ) ) {
						$respell[ $live ][ $k ] = $found;
					} else {
						$gaps[] = [ 'block_from' => $moved[ $live ], 'block_to' => $live, 'index' => $k, 'name' => $name, 'reason' => $found['reason'] ];
					}
				}
				if ( empty( $definition->atomic ) ) {
					$identity = Trait_Identity::of_row( $definition, $row );
					if ( $identity !== null ) {
						$identities[ $identity ][] = $k;
					}
				}
			}
			foreach ( $identities as $indices ) {
				if ( count( $indices ) > 1 ) {
					$first        = $new[ $live ][ $indices[0] ];
					$duplicates[] = [
						'block_to'       => $live,
						'name'           => $first['name'],
						'specialization' => $first['specialization'] ?? null,
						'indices'        => $indices,
					];
				}
			}
		}
		return [ $gaps, $duplicates, $respell ];
	}

	/**
	 * Whether a moved catalog row the replacement block does not carry exactly can be renamed to
	 * one it does: the name-equivalence tiers only (normalized, alias, and the decoration strip
	 * ahead of them), never a canonical or label reading and never a fuzzy guess. A row a
	 * Storyteller priced or marked as an editor's working copy is left exactly as it is, as a
	 * custom row would be.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $index `index_items()` of the replacement block.
	 * @return array{tier:string,to:string}|array{reason:string} The declared name and the tier that found it, or why not.
	 */
	private static function respelling( array $row, array $index ): array {
		if ( array_key_exists( '_removed', $row ) ) {
			return [ 'reason' => 'removed' ];
		}
		if ( isset( $row['chosen_cost'] ) ) {
			return [ 'reason' => 'has_custom_price' ];
		}
		$match = self::name_match( (string) $row['name'], $index );
		if ( $match === null ) {
			return [ 'reason' => 'not_in_replacement' ];
		}
		if ( $match['tier'] === 'ambiguous' ) {
			return [ 'reason' => 'ambiguous' ];
		}
		return [ 'tier' => $match['tier'], 'to' => $match['item']->name ];
	}

	/**
	 * One custom row's outcome: a `rekey` decision (the catalog item, the tier that decided and
	 * where any label goes) or a `kept` one carrying the guard or reason that stopped it.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $index `index_items()` of this row's block.
	 * @return array<string,mixed>
	 */
	private static function decide( array $row, object $definition, array $index, bool $suggest ): array {
		if ( array_key_exists( '_removed', $row ) ) {
			return [ 'outcome' => 'kept', 'reason' => 'removed' ];
		}
		if ( isset( $row['chosen_cost'] ) ) {
			return [ 'outcome' => 'kept', 'reason' => 'has_custom_price' ];
		}

		$name  = (string) ( $row['name'] ?? '' );
		$match = self::name_match( $name, $index );
		if ( $match === null ) {
			$match = self::canonical_tier( $name, $index );
		}
		if ( $match !== null ) {
			return $match['tier'] === 'ambiguous'
				? [ 'outcome' => 'kept', 'reason' => 'ambiguous' ]
				: [ 'outcome' => 'rekey', 'tier' => $match['tier'], 'to' => $match['item']->name, 'home' => null, 'label' => null ];
		}

		$labelled = self::label_tier( $name, $row, $definition, $index );
		if ( $labelled !== null ) {
			return $labelled;
		}

		return [ 'outcome' => 'kept', 'reason' => 'no_match' ] + ( $suggest ? [ 'suggestions' => self::suggestions( $name, $index ) ] : [] );
	}

	/**
	 * Tiers 1-4: the name as written, then with a trailing decoration stripped. The name-equivalence
	 * half of `decide()`, shared with the respelling of a moved catalog row.
	 *
	 * @param array<string,mixed> $index
	 * @return array{tier:string,item?:object}|null
	 */
	private static function name_match( string $name, array $index ): ?array {
		$match = self::name_tiers( $name, $index );
		if ( $match === null ) {
			$stripped = self::strip_decoration( $name );
			if ( $stripped !== $name && $stripped !== '' ) {
				$match = self::name_tiers( $stripped, $index );
				if ( $match !== null && $match['tier'] !== 'ambiguous' ) {
					$match['tier'] = 'decoration';
				}
			}
		}
		return $match;
	}

	/**
	 * Lookup tables over one block's items, built once per block per plan: 1,291 rituals scanned
	 * by 7,000 custom rows must not re-normalize every catalog name every time.
	 *
	 * @return array{items:array<int,object>,exact:array<string,array<int,object>>,normalized:array<string,array<int,object>>,alias:array<string,array<int,object>>,canonical:array<string,array<int,object>>,rule:?object}
	 */
	private static function index_items( object $definition ): array {
		$index = [ 'items' => [], 'exact' => [], 'normalized' => [], 'alias' => [], 'canonical' => [], 'rule' => null ];
		$rule  = $definition->name_canonicalization ?? null;
		if ( is_object( $rule ) && ( $rule->form ?? null ) === 'group_name_tier' ) {
			$index['rule'] = $rule;
		}
		foreach ( (array) ( $definition->items ?? [] ) as $item ) {
			if ( ! is_object( $item ) || ! isset( $item->name ) || ! is_string( $item->name ) ) {
				continue;
			}
			$index['items'][]                                                   = $item;
			$index['exact'][ $item->name ][]                                    = $item;
			$index['normalized'][ Fuzzy_Matcher::normalize( $item->name ) ][]   = $item;
			foreach ( (array) ( $item->aliases ?? [] ) as $alias ) {
				if ( is_string( $alias ) ) {
					$index['alias'][ $alias ][]                                = $item;
					$index['alias'][ Fuzzy_Matcher::normalize( $alias ) ][]    = $item;
				}
			}
			if ( $index['rule'] !== null ) {
				$key = self::canonical_key( $item->name, $index['rule'] );
				if ( $key !== null ) {
					$index['canonical'][ $key ][] = $item;
				}
			}
		}
		return $index;
	}

	/**
	 * Tiers 1-3 on one candidate name. The first tier that matches at all decides; matching more
	 * than one item there is `ambiguous`, never a pick.
	 *
	 * @param array<string,mixed> $index
	 * @return array{tier:string,item?:object}|null
	 */
	private static function name_tiers( string $name, array $index ): ?array {
		$needle = Fuzzy_Matcher::normalize( $name );
		foreach ( [ 'exact' => $name, 'normalized' => $needle, 'alias' => $name ] as $tier => $key ) {
			$hits = self::unique_items( $index[ $tier ][ $key ] ?? [] );
			if ( $tier === 'alias' && $hits === [] ) {
				$hits = self::unique_items( $index['alias'][ $needle ] ?? [] );
			}
			if ( count( $hits ) > 1 ) {
				return [ 'tier' => 'ambiguous' ];
			}
			if ( count( $hits ) === 1 ) {
				return [ 'tier' => $tier, 'item' => $hits[0] ];
			}
		}
		return null;
	}

	/** @param array<int,object> $items @return array<int,object> */
	private static function unique_items( array $items ): array {
		$seen = [];
		foreach ( $items as $item ) {
			$seen[ spl_object_id( $item ) ] = $item;
		}
		return array_values( $seen );
	}

	private static function strip_decoration( string $name ): string {
		$name = (string) preg_replace( '/\s*\[[^\]]*\]\s*$/u', '', trim( $name ) );
		return trim( rtrim( $name, self::DECORATION_CHARS . " \t" ) );
	}

	/**
	 * Tier 5, only on a block that declares `name_canonicalization` (§3.5a).
	 *
	 * @param array<string,mixed> $index
	 * @return array{tier:string,item?:object}|null
	 */
	private static function canonical_tier( string $name, array $index ): ?array {
		if ( $index['rule'] === null ) {
			return null;
		}
		$key = self::canonical_key( $name, $index['rule'] );
		if ( $key === null ) {
			return null;
		}
		$hits = self::unique_items( $index['canonical'][ $key ] ?? [] );
		if ( count( $hits ) > 1 ) {
			return [ 'tier' => 'ambiguous' ];
		}
		return count( $hits ) === 1 ? [ 'tier' => 'canonical', 'item' => $hits[0] ] : null;
	}

	/**
	 * `Group: Name (tier)` reduced to a comparable key: the normalized group after
	 * `group_aliases`, the normalized name, and the canonical tier word. Null when the string is
	 * not that form or its tier word is not one the rule knows - an unknown word must not match.
	 */
	private static function canonical_key( string $name, object $rule ): ?string {
		if ( ! preg_match( '/^\s*([^:]+?)\s*:\s*(.+?)\s*\(\s*([^()]+?)\s*\)\s*$/u', $name, $m ) ) {
			return null;
		}
		$aliases = [];
		foreach ( (array) ( $rule->group_aliases ?? [] ) as $from => $to ) {
			$aliases[ Fuzzy_Matcher::normalize( (string) $from ) ] = (string) $to;
		}
		$group = Fuzzy_Matcher::normalize( $m[1] );
		$group = isset( $aliases[ $group ] ) ? Fuzzy_Matcher::normalize( $aliases[ $group ] ) : $group;

		$tiers = [];
		foreach ( (array) ( $rule->tier_words ?? [] ) as $from => $to ) {
			$tiers[ Fuzzy_Matcher::normalize( (string) $from ) ] = (string) $to;
		}
		$tier = $tiers[ Fuzzy_Matcher::normalize( $m[3] ) ] ?? null;
		if ( $tier === null ) {
			return null;
		}
		return $group . "\0" . Fuzzy_Matcher::normalize( $m[2] ) . "\0" . $tier;
	}

	/**
	 * Tier 6: `Base: Label` (tried first) or `Base (Label)`, with `Base` resolving through tiers
	 * 1-3 only. The label lives in `specialization` where the item takes one, otherwise in the
	 * row's note (Q1).
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $index
	 * @return array<string,mixed>|null
	 */
	private static function label_tier( string $name, array $row, object $definition, array $index ): ?array {
		$splits = [];
		if ( preg_match( '/^\s*([^:]+?)\s*:\s*(.+?)\s*$/u', $name, $m ) ) {
			$splits[] = [ $m[1], $m[2] ];
		}
		if ( preg_match( '/^\s*(.+?)\s*\(\s*([^()]+?)\s*\)\s*$/u', $name, $m ) ) {
			$splits[] = [ $m[1], $m[2] ];
		}
		foreach ( $splits as [ $base, $label ] ) {
			$hit = self::name_tiers( $base, $index );
			if ( $hit === null ) {
				continue;
			}
			if ( $hit['tier'] === 'ambiguous' ) {
				return [ 'outcome' => 'kept', 'reason' => 'ambiguous' ];
			}
			$item = $hit['item'];
			$home = ! empty( $definition->has_specializations ) || Trait_Identity::allows_multiples( $definition, $item->name )
				? 'specialization'
				: 'note';
			if ( $home === 'specialization' && isset( $row['specialization'] ) && $row['specialization'] !== '' && $row['specialization'] !== $label ) {
				return [ 'outcome' => 'kept', 'reason' => 'label_conflict' ];
			}
			return [ 'outcome' => 'rekey', 'tier' => 'label', 'to' => $item->name, 'home' => $home, 'label' => $label ];
		}
		return null;
	}

	/**
	 * The row as it reads once re-keyed: the catalog name, `custom` dropped, any label in its
	 * home, and `rekeyed_from` recording exactly what the player wrote. Every other key is left
	 * as found.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $decision A `rekey` decision.
	 * @return array<string,mixed>
	 */
	private static function rekeyed_row( array $row, array $decision ): array {
		$from = (string) ( $row['name'] ?? '' );
		unset( $row['custom'] );
		$row['name'] = $decision['to'];
		if ( $decision['label'] !== null ) {
			if ( $decision['home'] === 'specialization' ) {
				$row['specialization'] = $decision['label'];
			} else {
				$existing    = isset( $row['note'] ) ? trim( (string) $row['note'] ) : '';
				$row['note'] = $existing === '' ? $decision['label'] : $decision['label'] . '; ' . $existing;
			}
		}
		$row['rekeyed_from'] = $from;
		return $row;
	}

	/**
	 * @param array<string,mixed> $index
	 * @return string[]
	 */
	private static function suggestions( string $name, array $index ): array {
		return Fuzzy_Matcher::suggest( $name, array_map( static fn( $item ) => $item->name, $index['items'] ) );
	}

	/** @param mixed $value */
	private static function is_list( $value ): bool {
		return is_array( $value ) && array_is_list( $value );
	}
}
