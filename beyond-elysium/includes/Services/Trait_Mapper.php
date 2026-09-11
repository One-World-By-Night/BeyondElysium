<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Maps GV exchange-file trait lists and trait names onto BE schema blocks.
 * Pure functions operating on already-decoded block definitions - no DB
 * access, directly unit-testable, matching the `Query_Engine`/`Action_Allocator`
 * pure-core pattern.
 */
class Trait_Mapper {

	/** @var array<string,array<string,array<string,mixed>>>|null Cached gex-trait-list-map.php contents. */
	private static $list_map = null;

	/**
	 * Loads gex-trait-list-map.php and caches its contents for the lifetime
	 * of the request. Every subsequent call returns the same cached array
	 * rather than re-reading the file.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	private static function list_map(): array {
		if ( self::$list_map === null ) {
			self::$list_map = require __DIR__ . '/gex-trait-list-map.php';
		}
		return self::$list_map;
	}

	/**
	 * Classifies a whole GV list - which of the five outcomes applies before
	 * any individual trait inside it gets resolved. `{stack}` in a returned
	 * `block_slug` is substituted with the real stack slug (the merged
	 * `Influences`/`Backgrounds` case).
	 *
	 * @param string $stack_slug
	 * @param string $gv_list_name Exactly the `LinkedTraitList.Name` value from the file.
	 * @return array{outcome:string,block_slug?:string,object_type?:string,note?:string}
	 */
	public static function classify_list( string $stack_slug, string $gv_list_name ): array {
		$map     = self::list_map();
		$shared  = $map['shared'] ?? [];
		$forStack = $map[ $stack_slug ] ?? [];

		$entry = $forStack[ $gv_list_name ] ?? $shared[ $gv_list_name ] ?? null;

		if ( $entry === null ) {
			return [
				'outcome' => 'preserve_as_note',
				'note'    => "No mapping declared for \"{$gv_list_name}\" on stack \"{$stack_slug}\".",
			];
		}

		if ( isset( $entry['block_slug'] ) ) {
			$entry['block_slug'] = str_replace( '{stack}', $stack_slug, $entry['block_slug'] );
		}

		return $entry;
	}

	/**
	 * Resolves one raw trait name against one or more candidate blocks' real
	 * catalogs, through a five-step order:
	 *
	 *   1. exact match                              -> 'exact'
	 *   2. case/whitespace-normalized match          -> 'normalized'
	 *   3. fuzzy match within threshold              -> 'fuzzy' (never auto-applied)
	 *   4. no match, block allows custom             -> 'custom'
	 *   5. no match, block does not allow custom     -> 'unresolved'
	 *
	 * A name matching real items in more than one distinct candidate block is
	 * flagged 'ambiguous' rather than silently resolved to the first hit.
	 *
	 * @param string                 $raw_name  The trait name as it appears in the import.
	 * @param array<int,object>      $blocks    One or more decoded `Schema_Block` rows (candidates).
	 * @return array{outcome:string,block_slug?:string,matched_name?:string,suggestions?:string[],candidates?:string[]}
	 */
	public static function resolve_trait( string $raw_name, array $blocks ): array {
		if ( empty( $blocks ) ) {
			return [ 'outcome' => 'unresolved', 'suggestions' => [] ];
		}

		// Strips a "Combo: "/"Combination: " import prefix from the raw name.
		$raw_name = self::strip_combo_decorations( $raw_name );

		// Exact match, across all candidate blocks - collect every block it hits.
		$exact_hits = self::hits_in_blocks( $raw_name, $blocks, static function ( $item_name ) use ( $raw_name ) {
			return $item_name === $raw_name;
		} );
		if ( count( $exact_hits ) > 1 ) {
			return [ 'outcome' => 'ambiguous', 'candidates' => array_column( $exact_hits, 'block_slug' ) ];
		}
		if ( count( $exact_hits ) === 1 ) {
			return [ 'outcome' => 'exact', 'block_slug' => $exact_hits[0]['block_slug'], 'matched_name' => $raw_name ];
		}

		// Normalized match.
		$needle       = Fuzzy_Matcher::normalize( $raw_name );
		$normal_hits  = self::hits_in_blocks( $raw_name, $blocks, static function ( $item_name ) use ( $needle ) {
			return Fuzzy_Matcher::normalize( $item_name ) === $needle;
		} );
		if ( count( $normal_hits ) > 1 ) {
			return [ 'outcome' => 'ambiguous', 'candidates' => array_column( $normal_hits, 'block_slug' ) ];
		}
		if ( count( $normal_hits ) === 1 ) {
			return [
				'outcome'      => 'normalized',
				'block_slug'   => $normal_hits[0]['block_slug'],
				'matched_name' => $normal_hits[0]['item_name'],
			];
		}

		// Fuzzy match, across every candidate block's catalog pooled together.
		$all_names = [];
		foreach ( $blocks as $block ) {
			foreach ( self::items_of( $block ) as $item ) {
				$all_names[] = $item->name;
			}
		}
		$suggestions = Fuzzy_Matcher::suggest( $raw_name, $all_names );
		if ( ! empty( $suggestions ) ) {
			return [ 'outcome' => 'fuzzy', 'suggestions' => $suggestions ];
		}

		// No match anywhere: allowed as custom only when there is exactly one candidate block that allows it.
		if ( count( $blocks ) === 1 && self::allows_custom( $blocks[0] ) ) {
			return [ 'outcome' => 'custom', 'block_slug' => $blocks[0]->slug ];
		}

		return [ 'outcome' => 'unresolved', 'suggestions' => [] ];
	}

	/**
	 * Resolves one raw trait from a `tiered_power`-classified list against a
	 * decoded `tiered_power` `Schema_Block`. Recognizes three raw name shapes:
	 *
	 *   - A named pick: `$raw_name` is `"{Family}: {Power}"`, optionally with
	 *     its own tier as `"{Family}: {Power} ({tier})"`. The tier typically
	 *     rides in the trait's separate `note` field instead, and `$raw_total`
	 *     is the level's cost, not its level number.
	 *   - A numbered rung: `$raw_name` is the bare family name and
	 *     `$raw_total` is the level as a digit string.
	 *   - A traditioned numbered rung: `$raw_name` is `"{Tradition}: {Family}"`
	 *     where `{Family}` is itself a real top-level family. `$raw_total` is
	 *     still that family's own numbered level; `{Tradition}` is carried
	 *     through as `tradition` on a successful resolution.
	 *
	 * @param string $raw_name
	 * @param string $raw_total
	 * @param object $block Decoded `tiered_power` `Schema_Block` row.
	 * @return array{outcome:string,block_slug?:string,family?:string,level?:int,power_name?:string,tier?:string,tradition?:string,suggestions?:string[]}
	 */
	public static function resolve_tiered_power_trait( string $raw_name, string $raw_total, $block ): array {
		$powers   = (array) ( $block->definition->powers ?? [] );
		$raw_name = self::strip_export_decorations( $raw_name );

		$tradition_split = self::split_tradition_prefix( $raw_name, $powers );
		if ( $tradition_split !== null ) {
			[ $tradition, $family_name ] = $tradition_split;
			$result = self::resolve_numbered_power( $family_name, $raw_total, $powers, $block->slug );
			if ( in_array( $result['outcome'], [ 'exact', 'normalized' ], true ) ) {
				$result['tradition'] = $tradition;
			}
			return $result;
		}

		$named = self::parse_named_power( $raw_name, $powers );
		if ( $named !== null ) {
			return self::resolve_named_power( $named[0], $named[1], $powers, $block->slug );
		}

		return self::resolve_numbered_power( $raw_name, $raw_total, $powers, $block->slug );
	}

	/**
	 * Strips a trailing `*` marker that Grapevine's export tool can attach
	 * to a bare family name ("Auspex*"), a power name ("Spirit Manipulation*"),
	 * or right after the family and before the colon
	 * ("Thaumaturgy*: The Path of Blood").
	 */
	private static function strip_export_decorations( string $raw_name ): string {
		return trim( str_replace( '*', '', $raw_name ) );
	}

	/**
	 * Strips a leading `"Combo: "`/`"Combination: "` label that Grapevine's
	 * export text prefixes onto a held combo discipline, along with any
	 * trailing constituent-disciplines note in parentheses or brackets
	 * (`"Combo: Blood Sight (Aus 3, PoB 1)"` becomes `"Blood Sight"`). A
	 * no-op for any name that does not start with this exact prefix.
	 */
	private static function strip_combo_decorations( string $raw_name ): string {
		if ( ! preg_match( '/^comb(?:o|ination)\s*:\s*/i', $raw_name, $prefix_match ) ) {
			return $raw_name;
		}
		$stripped = substr( $raw_name, strlen( $prefix_match[0] ) );
		// Strips one trailing "(...)" or "[...]" group at the very end only.
		$stripped = preg_replace( '/\s*[\(\[][^\(\)\[\]]*[\)\]]\s*$/', '', $stripped );
		return trim( $stripped );
	}

	/**
	 * Splits `"{Tradition}: {Family}"` into `[tradition, family]` only when `{Family}` is
	 * an EXACT match for a real top-level family - an unrecognized or misspelled suffix
	 * deliberately does not qualify here (stays on the existing named-pick/suggestion path
	 * instead of guessing this is a traditioned rung). `{Tradition}` itself is never
	 * checked against anything - unlike a family name, a tradition name (Thaumaturgy,
	 * Sadhana, Wanga, ...) is not itself a seeded catalog entry.
	 *
	 * @param string   $raw_name
	 * @param object[] $powers
	 * @return array{0:string,1:string}|null
	 */
	private static function split_tradition_prefix( string $raw_name, array $powers ): ?array {
		if ( ! preg_match( '/^([^:]+):\s*(.+)$/', $raw_name, $m ) ) {
			return null;
		}
		$tradition = trim( $m[1] );
		$family    = trim( $m[2] );

		foreach ( $powers as $power ) {
			if ( ( $power->name ?? null ) === $family ) {
				return [ $tradition, $family ];
			}
		}

		// Falls back to stripping a leading "The " from the family name, tried only after the exact check above.
		if ( stripos( $family, 'the ' ) === 0 ) {
			$stripped = trim( substr( $family, 4 ) );
			foreach ( $powers as $power ) {
				if ( ( $power->name ?? null ) === $stripped ) {
					return [ $tradition, $stripped ];
				}
			}
		}

		return null;
	}

	/**
	 * Applies an ST's explicitly chosen resolution for a `fuzzy` match,
	 * re-entering the same lookup an exact match would take but using the
	 * chosen name in place of the raw one.
	 *
	 * For a `trait_list` resolution, `$chosen_name` is a full item name -
	 * pass `$blocks`, leave `$block` null. For a `tiered_power` resolution,
	 * `$chosen_name` is whichever half of the raw name was fuzzy: a bare
	 * power name for a named pick, or a family name for a numbered rung -
	 * pass `$block`, leave `$blocks` empty. `$raw_name` is still needed to
	 * tell a named pick from a numbered rung the same way
	 * `resolve_tiered_power_trait()` itself does.
	 *
	 * @param string      $raw_name
	 * @param string      $raw_total
	 * @param string      $chosen_name
	 * @param object|null $block  Set only for a tiered_power resolution.
	 * @param object[]    $blocks Set only for a trait_list resolution.
	 * @return array
	 */
	public static function resolve_chosen( string $raw_name, string $raw_total, string $chosen_name, $block, array $blocks ): array {
		if ( $block !== null ) {
			$powers   = (array) ( $block->definition->powers ?? [] );
			$raw_name = self::strip_export_decorations( $raw_name );

			// Must agree with resolve_tiered_power_trait()'s own branch order for "Tradition: Family" names.
			$tradition_split = self::split_tradition_prefix( $raw_name, $powers );
			if ( $tradition_split !== null ) {
				$result = self::resolve_numbered_power( $chosen_name, $raw_total, $powers, $block->slug );
				if ( in_array( $result['outcome'], [ 'exact', 'normalized' ], true ) ) {
					$result['tradition'] = $tradition_split[0];
				}
				return $result;
			}

			$named = self::parse_named_power( $raw_name, $powers );
			if ( $named !== null ) {
				return self::resolve_named_power( $named[0], $chosen_name, $powers, $block->slug );
			}
			return self::resolve_numbered_power( $chosen_name, $raw_total, $powers, $block->slug );
		}

		return self::resolve_trait( $chosen_name, $blocks );
	}

	/**
	 * Split `"{Family}: {Power}"` (tier parenthetical optional) into `[family, power_name]`,
	 * or null when the raw name has no `Family:` shape at all - a bare family name for a
	 * numbered rung never contains a colon.
	 *
	 * Splits on the **longest real catalog family the name actually starts with**, not on
	 * the first colon: over a hundred real seeded families contain colons of their own
	 * ("Akhu: Path of Blood", "Thaumaturgy (Camarilla): Alchemy", "Wanga: Ash Path"), so a
	 * blind first-colon split mis-parses every one of them. Falls back to a first-colon
	 * split only when no real family matches, so an unrecognized or misspelled family still
	 * reaches `resolve_named_power()` (and its suggestions) as a named shape rather than
	 * silently dropping through to the numbered-rung path.
	 *
	 * @param string   $raw_name
	 * @param object[] $powers Decoded `definition->powers`; empty is allowed (fallback only).
	 * @return array{0:string,1:string}|null
	 */
	private static function parse_named_power( string $raw_name, array $powers = [] ): ?array {
		$best = null;
		foreach ( $powers as $power ) {
			$family = (string) ( $power->name ?? '' );
			if ( $family === '' ) {
				continue;
			}
			$prefix = $family . ':';
			if ( strncasecmp( $raw_name, $prefix, strlen( $prefix ) ) === 0
				&& ( $best === null || strlen( $family ) > strlen( $best ) )
			) {
				$best = $family;
			}
		}

		if ( $best !== null ) {
			$power_name = trim( substr( $raw_name, strlen( $best ) + 1 ) );
			return $power_name === '' ? null : [ $best, $power_name ];
		}

		if ( ! preg_match( '/^([^:]+):\s*(.+)$/', $raw_name, $m ) ) {
			return null;
		}
		return [ trim( $m[1] ), trim( $m[2] ) ];
	}

	/**
	 * Resolves a named-pick power (`"{Family}: {Power}"`) against a family's
	 * own levels: exact match first, then a normalized match, then a fuzzy
	 * suggestion. Tries the power name both as given and with a trailing
	 * parenthetical tier removed.
	 *
	 * @param string   $family_name
	 * @param string   $power_name
	 * @param object[] $powers
	 * @param string   $block_slug
	 * @return array{outcome:string,block_slug?:string,family?:string,power_name?:string,tier?:string,suggestions?:string[]}
	 */
	private static function resolve_named_power( string $family_name, string $power_name, array $powers, string $block_slug ): array {
		$power = self::find_by_name( $powers, $family_name );
		if ( $power === null ) {
			return [ 'outcome' => 'unresolved', 'suggestions' => [] ];
		}

		$levels = (array) ( $power->levels ?? [] );

		// Tries the name exactly as given first, then with a trailing parenthetical tier removed.
		$candidates = [ $power_name ];
		$stripped   = trim( (string) preg_replace( '/\s*\([^()]+\)\s*$/', '', $power_name ) );
		if ( $stripped !== '' && $stripped !== $power_name ) {
			$candidates[] = $stripped;
		}

		foreach ( $candidates as $candidate ) {
			$exact = self::find_by_name( $levels, $candidate, 'power_name' );
			if ( $exact !== null ) {
				return [
					'outcome'    => 'exact',
					'block_slug' => $block_slug,
					'family'     => $power->name,
					'power_name' => $exact->power_name,
					'tier'       => $exact->tier,
				];
			}
		}

		foreach ( $candidates as $candidate ) {
			$needle = Fuzzy_Matcher::normalize( $candidate );
			foreach ( $levels as $level ) {
				if ( Fuzzy_Matcher::normalize( $level->power_name ?? '' ) === $needle ) {
					return [
						'outcome'    => 'normalized',
						'block_slug' => $block_slug,
						'family'     => $power->name,
						'power_name' => $level->power_name,
						'tier'       => $level->tier,
					];
				}
			}
		}

		$names       = array_map( static fn( $l ) => $l->power_name ?? '', $levels );
		$suggestions = Fuzzy_Matcher::suggest( (string) end( $candidates ), $names );
		if ( ! empty( $suggestions ) ) {
			return [ 'outcome' => 'fuzzy', 'suggestions' => $suggestions ];
		}

		return [ 'outcome' => 'unresolved', 'suggestions' => [] ];
	}

	/**
	 * Resolves a numbered-rung power (a bare family name plus a numeric
	 * level) against a family's own levels: exact match first, then a
	 * normalized match, then a fuzzy suggestion. Returns unresolved when the
	 * family matches but the given level does not exist on it.
	 *
	 * @param string   $raw_name
	 * @param string   $raw_total
	 * @param object[] $powers
	 * @param string   $block_slug
	 * @return array{outcome:string,block_slug?:string,family?:string,level?:int,suggestions?:string[]}
	 */
	private static function resolve_numbered_power( string $raw_name, string $raw_total, array $powers, string $block_slug ): array {
		$exact = self::find_by_name( $powers, $raw_name );
		$power = $exact;
		$outcome_if_found = 'exact';

		if ( $power === null ) {
			$needle = Fuzzy_Matcher::normalize( $raw_name );
			foreach ( $powers as $candidate ) {
				if ( Fuzzy_Matcher::normalize( $candidate->name ) === $needle ) {
					$power = $candidate;
					$outcome_if_found = 'normalized';
					break;
				}
			}
		}

		if ( $power === null ) {
			$names       = array_map( static fn( $p ) => $p->name, $powers );
			$suggestions = Fuzzy_Matcher::suggest( $raw_name, $names );
			if ( ! empty( $suggestions ) ) {
				return [ 'outcome' => 'fuzzy', 'suggestions' => $suggestions ];
			}
			return [ 'outcome' => 'unresolved', 'suggestions' => [] ];
		}

		if ( ! is_numeric( $raw_total ) ) {
			return [ 'outcome' => 'unresolved', 'suggestions' => [] ];
		}

		$level_num = (int) $raw_total;
		foreach ( (array) $power->levels as $level ) {
			if ( ( $level->level ?? null ) === $level_num ) {
				return [
					'outcome'    => $outcome_if_found,
					'block_slug' => $block_slug,
					'family'     => $power->name,
					'level'      => $level_num,
				];
			}
		}

		// The family is real but this exact numbered rung is not; not a name problem, so no suggestions to offer.
		return [ 'outcome' => 'unresolved', 'suggestions' => [] ];
	}

	/**
	 * Finds the first item in a list whose named property matches exactly,
	 * comparing case-sensitively. Used to look up a power or level by name
	 * within an already-decoded catalog. Returns null when no item matches.
	 *
	 * @param object[] $items
	 * @param string   $name
	 * @param string   $prop
	 * @return object|null
	 */
	private static function find_by_name( array $items, string $name, string $prop = 'name' ) {
		foreach ( $items as $item ) {
			if ( ( $item->{$prop} ?? null ) === $name ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Collects every candidate block that has at least one item satisfying
	 * `$predicate`, stopping at the first matching item per block. Used by
	 * resolve_trait() to find exact and normalized matches across multiple
	 * candidate blocks at once.
	 *
	 * @param string   $raw_name
	 * @param object[] $blocks
	 * @param callable $predicate
	 * @return array<int,array{block_slug:string,item_name:string}>
	 */
	private static function hits_in_blocks( string $raw_name, array $blocks, callable $predicate ): array {
		$hits = [];
		foreach ( $blocks as $block ) {
			foreach ( self::items_of( $block ) as $item ) {
				if ( $predicate( $item->name ) ) {
					$hits[] = [ 'block_slug' => $block->slug, 'item_name' => $item->name ];
					break; // one hit per block is enough to count the block as a match
				}
			}
		}
		return $hits;
	}

	/**
	 * Returns a block's catalog items (`definition->items`) as a plain
	 * array, or an empty array when the block has no items defined. A small
	 * convenience wrapper used throughout this class's lookups.
	 *
	 * @param object $block A decoded Schema_Block row.
	 * @return object[]
	 */
	private static function items_of( $block ): array {
		return (array) ( $block->definition->items ?? [] );
	}

	/**
	 * Checks whether a block's definition allows a custom, unrecognized
	 * trait entry to be accepted rather than rejected as unresolved. Reads
	 * the `allow_custom` flag from the block's definition.
	 *
	 * @param object $block
	 * @return bool
	 */
	private static function allows_custom( $block ): bool {
		return ! empty( $block->definition->allow_custom );
	}
}
