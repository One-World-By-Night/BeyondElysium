<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a held or imported catalog reference to its CURRENT catalog location, against
 * the declared catalog's own recorded rename/move/split history - never against
 * `sheet_data`, and it never writes anything. 1.3.2's alias-routing item
 * (`1.3.1-design-workflow.md` §11.7 item 1): "A held `{name, power_name}` must resolve
 * through a family's `aliases`, a `moved_from: {block, name}` in another block, a variant
 * family's `split_from`, and a rung's `aliases`. Without it, every holding of a moved or
 * renamed family reads as `family_not_in_catalog`."
 *
 * Four shapes a declared file records (`CATALOG-JSON-FORMAT.md` §4.1's `aliases` table,
 * `1.3.1-design-workflow.md` §11.2's conventions table), all read-only here:
 *
 *  - a family's or item's own `aliases` - a rename that stayed in the same block
 *    (`Meditiation` -> `Meditation`, `Path of Dry Nile` -> `Path of the Dry Nile`);
 *  - a family's or item's own `moved_from: {block, name}` (or a list of those pairs) -
 *    moved to a DIFFERENT block (`vampire-blood-magic`'s `Lure of Flames` carries
 *    `moved_from: [{block: "vampire-disciplines", name: "Creo Ignem"}]`);
 *  - a variant family's `split_from` - a fused ladder split into two or more families
 *    (Kuei-Jin's `Black Wind` into three named aspects);
 *  - a rung or pick's own `aliases` (`vampire-blood-magic`'s `Cadaverous Animation` level
 *    answers to `Call the Homuncular Servant` and `Call of Athanatos` too).
 *
 * **Ambiguity is refused, never guessed.** Three real Kuei-Jin families share
 * `split_from: "Black Wind"` with nothing else to tell them apart - 1.3.1's own ruling is
 * that a Storyteller decides which aspect a held `Black Wind` means, not this class
 * (`1.3.1-design-workflow.md` §11.3). Every lookup here returns the single unambiguous
 * match or null; it never picks one of several candidates for the caller. The same
 * discipline applies to a name this class simply has no record for at all - families like
 * `vampire-blood-magic`'s `Grave's Decay`/`Ash Path`, which changed BLOCK with no name
 * change and carry no `moved_from` at all, are a real, known, un-annotated gap (logged in
 * `1.3.2-design-workflow.md`), not something this class infers from a same-name coincidence
 * across blocks - the format's own first principle is "declared, never derived"
 * (`CATALOG-JSON-FORMAT.md` §1), and a plain cross-block name match is exactly the kind of
 * inference that principle rules out.
 *
 * Pure: no database or WordPress calls, matching `Power_Levels`/`Trait_Identity`'s own
 * discipline (both cited by the item this class implements) - a decoded block definition,
 * or a `slug => block` map a caller already loaded, in, an object or null out. A caller
 * that already has the character's other blocks loaded - `Cost_Engine::in_type_check()`'s
 * own `array<string,object> $blocks` shape, or `Point_Audit`'s `$blocks` - passes them to
 * the `find_moved_*()` pair for the cross-block search; a caller with only the one
 * addressed block gets same-block alias/`split_from` resolution alone.
 */
class Trait_Alias_Resolver {

	// -------------------------------------------------------------------------------
	// Same-block: tiered_power families.
	// -------------------------------------------------------------------------------

	/**
	 * Finds a family by its current name, or - only when nothing carries that name
	 * directly - by a recorded alias or the name it was `split_from`. Returns null when
	 * nothing answers to the name, or when more than one family does (see the class
	 * docblock: an ambiguous `split_from` is left unresolved, not guessed).
	 *
	 * @param array<int,object|array<string,mixed>> $powers `definition->powers`, decoded or raw.
	 * @return object|null
	 */
	public static function find_power_by_name( array $powers, string $name ): ?object {
		foreach ( $powers as $power ) {
			$power = (object) $power;
			if ( ( $power->name ?? null ) === $name ) {
				return $power;
			}
		}
		return self::single_match(
			$powers,
			static fn( object $power ): bool => self::answers_to( $power, $name )
		);
	}

	/**
	 * A family/item answers to a name when the name is one of its own recorded `aliases`,
	 * or the name it was `split_from`.
	 */
	private static function answers_to( object $entry, string $name ): bool {
		foreach ( (array) ( $entry->aliases ?? [] ) as $alias ) {
			if ( $alias === $name ) {
				return true;
			}
		}
		return isset( $entry->split_from ) && $entry->split_from === $name;
	}

	/**
	 * @param array<int,object|array<string,mixed>> $entries
	 * @param callable(object):bool                 $predicate
	 */
	private static function single_match( array $entries, callable $predicate ): ?object {
		$matches = [];
		foreach ( $entries as $entry ) {
			$entry = (object) $entry;
			if ( $predicate( $entry ) ) {
				$matches[] = $entry;
			}
		}
		return count( $matches ) === 1 ? $matches[0] : null;
	}

	// -------------------------------------------------------------------------------
	// Same-block: trait_list items.
	// -------------------------------------------------------------------------------

	/**
	 * Finds a trait_list item by its current name, or - only when nothing carries that
	 * name directly - by a recorded alias (`Lore` answering to the CSV's own `RD Data`,
	 * `Meditation` answering to the seeded typo `Meditiation`). Items carry no `split_from`
	 * in the real files (`1.3.1-design-workflow.md` §11.2's table scopes it to families) -
	 * checked here for symmetry with `find_power_by_name()` regardless, since nothing
	 * prevents a future file from using it the same way.
	 *
	 * @param array<int,object|array<string,mixed>> $items `definition->items`, decoded or raw.
	 * @return object|null
	 */
	public static function find_item_by_name( array $items, string $name ): ?object {
		foreach ( $items as $item ) {
			$item = (object) $item;
			if ( ( $item->name ?? null ) === $name ) {
				return $item;
			}
		}
		return self::single_match(
			$items,
			static fn( object $item ): bool => self::answers_to( $item, $name )
		);
	}

	// -------------------------------------------------------------------------------
	// Same-family: rungs and picks.
	// -------------------------------------------------------------------------------

	/**
	 * Finds a level entry - a rung or a pick, `Power_Levels::all()`'s own combined list -
	 * by its own `power_name`, or - only when nothing carries that name directly - by one
	 * of its recorded `aliases` (`vampire-blood-magic`'s `Grave's Decay` level answering to
	 * both `Dissolve the Flesh` and `Disolve`).
	 *
	 * @param array<int,object> $levels
	 * @return object|null
	 */
	public static function find_level_by_name( array $levels, string $power_name ): ?object {
		foreach ( $levels as $level ) {
			if ( ( $level->power_name ?? null ) === $power_name ) {
				return $level;
			}
		}
		return self::single_match(
			$levels,
			static function ( object $level ) use ( $power_name ): bool {
				foreach ( (array) ( $level->aliases ?? [] ) as $alias ) {
					if ( $alias === $power_name ) {
						return true;
					}
				}
				return false;
			}
		);
	}

	// -------------------------------------------------------------------------------
	// Cross-block: moved_from.
	// -------------------------------------------------------------------------------

	/**
	 * Every `{block, name}` pair a family or item's own `moved_from` carries, normalized:
	 * the format allows a bare object (one prior home - most families) or a list of them
	 * (`Movement of the Mind` merges two Disciplines, `Creo Motus` and `Rego Motus`), and
	 * this reads either shape, decoded JSON or a hand-built array alike.
	 *
	 * @return array<int,array{block:string,name:string}>
	 */
	private static function moved_from_pairs( object $entry ): array {
		$raw = $entry->moved_from ?? null;
		if ( $raw === null ) {
			return [];
		}

		if ( is_array( $raw ) && array_key_exists( 'block', $raw ) ) {
			// A single {block,name} pair, hand-built as a plain assoc array.
			$raw = [ $raw ];
		} elseif ( ! is_array( $raw ) ) {
			// A single {block,name} pair decoded as stdClass - the real JSON shape for an
			// item (`vampire-gargoyle-powers`) and for most families.
			$raw = [ $raw ];
		}
		// Otherwise $raw is already a list of pairs (stdClass or assoc arrays) - the real
		// JSON shape for a family that absorbed more than one prior name.

		$pairs = [];
		foreach ( $raw as $pair ) {
			$pair = (object) $pair;
			if ( isset( $pair->block ) && isset( $pair->name ) ) {
				$pairs[] = [ 'block' => (string) $pair->block, 'name' => (string) $pair->name ];
			}
		}
		return $pairs;
	}

	/**
	 * Searches every already-loaded block for a `tiered_power` family whose `moved_from`
	 * names this exact `(from_block_slug, name)` pair - the read path for a held reference
	 * to a family that left its original block entirely (D67/B-5: nine Latin-named Hermetic
	 * Disciplines folded into `vampire-blood-magic`, `Awakening of the Steel`, `Cenotaph
	 * Path`, and others). Refuses rather than guesses when more than one family claims the
	 * same prior home (not observed in the real catalog today, but the same discipline as
	 * every other lookup here).
	 *
	 * `$blocks` is the same `array<string,object>` shape `Cost_Engine::in_type_check()` and
	 * `Point_Audit` already load once per character - keyed by slug, each a decoded
	 * `Schema_Block` row (chronicle-fork-aware, when the caller resolved it that way).
	 *
	 * @param array<string,object> $blocks
	 * @return array{block_slug:string,block:object,power:object}|null
	 */
	public static function find_moved_power( array $blocks, string $from_block_slug, string $name ): ?array {
		$found = self::find_moved( $blocks, $from_block_slug, $name, 'tiered_power', 'powers', 'power' );
		return $found === null ? null : [
			'block_slug' => (string) $found['block_slug'],
			'block'      => $found['block'],
			'power'      => $found['power'],
		];
	}

	/**
	 * The trait_list counterpart of `find_moved_power()` - `vampire-gargoyle-powers`' items
	 * each carry `moved_from: {block: "vampire-disciplines", name: "Gargoyle Powers"}`,
	 * `kueijin-techniques`' items point back at `kueijin-disciplines`' two retired families.
	 *
	 * @param array<string,object> $blocks
	 * @return array{block_slug:string,block:object,item:object}|null
	 */
	public static function find_moved_item( array $blocks, string $from_block_slug, string $name ): ?array {
		$found = self::find_moved( $blocks, $from_block_slug, $name, 'trait_list', 'items', 'item' );
		return $found === null ? null : [
			'block_slug' => (string) $found['block_slug'],
			'block'      => $found['block'],
			'item'       => $found['item'],
		];
	}

	/**
	 * @param array<string,object> $blocks
	 * @return array<string,mixed>|null
	 */
	private static function find_moved(
		array $blocks,
		string $from_block_slug,
		string $name,
		string $section_type,
		string $container_key,
		string $entry_key
	): ?array {
		$matches = [];
		foreach ( $blocks as $slug => $block ) {
			if ( ! is_object( $block ) || ( $block->section_type ?? null ) !== $section_type ) {
				continue;
			}
			$definition = is_object( $block->definition ?? null ) ? $block->definition : null;
			if ( $definition === null ) {
				continue;
			}
			foreach ( (array) ( $definition->{$container_key} ?? [] ) as $entry ) {
				$entry = (object) $entry;
				foreach ( self::moved_from_pairs( $entry ) as $pair ) {
					if ( $pair['block'] === $from_block_slug && $pair['name'] === $name ) {
						$matches[] = [
							'block_slug' => (string) $slug,
							'block'      => $block,
							$entry_key   => $entry,
						];
					}
				}
			}
		}
		return count( $matches ) === 1 ? $matches[0] : null;
	}
}
