<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Creature_Stack;

defined( 'ABSPATH' ) || exit;

/**
 * Derives the XP cost of a proposed character change - and, since PC-1
 * (point-calculator-design.md), the XP value of a character's already-held
 * state, for `Services\Point_Audit`'s itemised report. Both read the same
 * catalog through the same rules: pricing lives in exactly one place, so a
 * previewed cost, an approved cost, and an audit line can never disagree.
 *
 * A trait holds only its name and level; this class computes its price at
 * runtime from the block and stack definitions plus the character's
 * current identity, every time it is asked.
 *
 * `cost_for_change()` and `is_in_type()` are `$wpdb`-touching wrappers
 * around pure, database-free logic: `price_trait_list_change()`,
 * `price_tiered_power_change()`, `price_resource_pool_change()`, and
 * `is_in_type_pure()` price a proposed *change*; `price_held_trait_list_item()`,
 * `price_held_tiered_power()`, and `price_held_resource_pool()` price an
 * already-*held* state for the audit. All are pure and directly unit-tested.
 * `Point_Audit` (not this class) owns the report itself - resolving blocks,
 * walking held entries, and assembling the envelope are report construction,
 * not pricing, and belong in a class whose own scope is "the XP cost of a
 * proposed change."
 */
class Cost_Engine {

	// Public entry points: DB-touching wrappers.

	/**
	 * Prices one proposed change against a character's current state.
	 * Looks up the affected block and dispatches to the pricing logic for
	 * its section type. Never mutates anything, so it is safe to call
	 * repeatedly while a change is still being previewed.
	 *
	 * @param object $character Character row with decoded (array) `sheet_data` and a
	 *                           `stack_slug`.
	 * @param array  $change    Shape: `change_type`, `change_data` (with `block_slug` plus
	 *                           `trait` / `values` / `fields`), matching `ChangeRequest`.
	 * @return int Signed XP delta - positive costs, negative refunds.
	 */
	public static function cost_for_change( $character, array $change ): int {
		$change_data = $change['change_data'] ?? [];
		$block_slug  = $change_data['block_slug'] ?? null;
		if ( ! $block_slug ) {
			return 0;
		}

		// Prefer this character's chronicle-specific fork of the block, if one exists.
		$block = Schema_Block::find_for_game( $block_slug, (string) ( $character->owner_slug ?? '' ) );
		if ( ! $block ) {
			// A missing block has no cost rule to apply; default to zero.
			return 0;
		}

		$sheet_data = is_array( $character->sheet_data ?? null ) ? $character->sheet_data : [];
		$change_type = $change['change_type'] ?? '';

		switch ( $block->section_type ) {
			case 'trait_list':
				return self::price_trait_list_change( $sheet_data, $block->definition, $block_slug, $change_type, $change_data );

			case 'tiered_power':
				$trait_name = $change_data['trait']['name'] ?? '';
				$in_type    = $trait_name !== '' ? self::is_in_type( $character, $block_slug, $trait_name ) : true;
				return self::price_tiered_power_change( $sheet_data, $block->definition, $change_type, $change_data, $in_type );

			case 'resource_pool':
				return self::price_resource_pool_change( $sheet_data, $block->definition, $block_slug, $change_data );

			default:
				// identity_field changes carry no XP cost.
				return 0;
		}
	}

	/**
	 * Resolves whether a trait is in-type for the character. Reads the
	 * stack's `in_type_source` (for example `"vampire-identity.Clan"`) to
	 * find which identity block and field hold the in-type list, then
	 * delegates to `is_in_type_pure()` to compare against the character's
	 * identity. Defaults to `true`, the cheaper price, whenever the data
	 * needed to answer is missing.
	 */
	public static function is_in_type( $character, string $block_slug, string $trait_name ): bool {
		return ( self::in_type_check( $character, $block_slug ) )( $trait_name );
	}

	/**
	 * Loads what `is_in_type()` needs for one block - the stack's `in_type_source`
	 * and the identity block it names - and returns the check for any trait in
	 * that block, so pricing a character's every held trait loads them once per
	 * block rather than once per trait (1.0.0-review F-087). A caller that has
	 * already loaded the character's stack, or the chronicle's blocks by slug,
	 * passes them in.
	 *
	 * @param object|null          $stack  The character's creature stack, when already loaded.
	 * @param array<string,object> $blocks The character's chronicle's blocks by slug, fork-aware, when already loaded.
	 * @return callable(string):bool
	 */
	public static function in_type_check( $character, string $block_slug, ?object $stack = null, array $blocks = [] ): callable {
		$always = static fn( string $trait_name ): bool => true;

		$stack = $stack ?? Creature_Stack::find_by_slug( $character->stack_slug );
		if ( ! $stack ) {
			return $always;
		}

		$in_type_source = null;
		foreach ( ( $stack->stack_definition->sections ?? [] ) as $section ) {
			if ( ( $section->block_slug ?? null ) === $block_slug && ! empty( $section->in_type_source ) ) {
				$in_type_source = $section->in_type_source;
				break;
			}
		}
		if ( ! $in_type_source ) {
			return $always;
		}

		$parts = explode( '.', $in_type_source, 2 );
		if ( count( $parts ) !== 2 ) {
			return $always;
		}
		[ $identity_block_slug, $field_name ] = $parts;

		// The chronicle's own fork when it has one: a chronicle that adds a bloodline and its
		// in-clan Disciplines must be priced by that list, not the global one (1.0.0-review F-013).
		$identity_block = $blocks[ $identity_block_slug ] ?? Schema_Block::find_for_game( $identity_block_slug, (string) ( $character->owner_slug ?? '' ) );
		if ( ! $identity_block ) {
			return $always;
		}

		$sheet_data = is_array( $character->sheet_data ?? null ) ? $character->sheet_data : [];
		$definition = $identity_block->definition;
		return static fn( string $trait_name ): bool => self::is_in_type_pure( $sheet_data, $identity_block_slug, $field_name, $trait_name, $definition );
	}

	// Pure logic: DB-free, directly unit-tested.

	/**
	 * Determines whether a trait name is in-type for a given identity
	 * value. Checks the identity block's `clan_disciplines` map for an
	 * explicit in-type list under the character's identity value, falling
	 * back to the character's own `chosen_in_clan` list (used for values
	 * like Caitiff or Pander that have no predefined list) when none exists.
	 */
	public static function is_in_type_pure(
		array $sheet_data,
		string $identity_block_slug,
		string $field_name,
		string $trait_name,
		$identity_definition
	): bool {
		$identity_data  = $sheet_data[ $identity_block_slug ] ?? [];
		$identity_value = $identity_data[ $field_name ] ?? null;
		if ( ! $identity_value ) {
			return true;
		}

		$clan_disciplines = (array) ( $identity_definition->clan_disciplines ?? [] );

		if ( isset( $clan_disciplines[ $identity_value ] ) ) {
			return in_array( $trait_name, (array) $clan_disciplines[ $identity_value ], true );
		}

		$chosen = (array) ( $identity_data['chosen_in_clan'] ?? [] );
		return in_array( $trait_name, $chosen, true );
	}

	/**
	 * Prices an `add_trait`, `remove_trait`, or `modify_trait` change
	 * against a trait_list block. Computes the cost as the delta between
	 * the before-total and after-total price, so a count change only
	 * charges the newly added units and a `chosen_cost` change reprices
	 * correctly through the same path.
	 */
	public static function price_trait_list_change(
		array $sheet_data,
		$definition,
		string $block_slug,
		string $change_type,
		array $change_data
	): int {
		$trait = $change_data['trait'] ?? [];
		$name  = $trait['name'] ?? null;
		if ( $name === null ) {
			return 0;
		}

		// Homebrew traits have no catalog entry to price against.
		if ( ! empty( $trait['custom'] ) ) {
			return 0;
		}

		$item = self::find_item( $definition, $name );
		if ( ! $item || ! isset( $item->cost ) ) {
			return 0;
		}

		$negative = ! empty( $definition->negative );
		$sign     = $negative ? -1 : 1;

		$held       = self::find_held_trait( $sheet_data, $block_slug, $name );
		$old_count  = $held['count'] ?? 0;
		$old_chosen = $held['chosen_cost'] ?? null;

		$new_count  = $old_count;
		$new_chosen = $old_chosen;

		if ( 'add_trait' === $change_type ) {
			$old_count  = 0;
			$old_chosen = null;
			$new_count  = max( 1, (int) ( $trait['count'] ?? 1 ) );
			$new_chosen = $trait['chosen_cost'] ?? null;
		} elseif ( 'remove_trait' === $change_type ) {
			$new_count  = 0;
			$new_chosen = null;
		} elseif ( 'modify_trait' === $change_type ) {
			if ( array_key_exists( 'count', $trait ) ) {
				$new_count = max( 0, (int) $trait['count'] );
			}
			if ( array_key_exists( 'chosen_cost', $trait ) ) {
				$new_chosen = $trait['chosen_cost'];
			}
		}

		$unit_cost_string = (string) $item->cost;
		$old_total        = $old_count > 0 ? self::price_item_cost( $unit_cost_string, $old_chosen ) * $old_count : 0;
		$new_total        = $new_count > 0 ? self::price_item_cost( $unit_cost_string, $new_chosen ) * $new_count : 0;

		return $sign * ( $new_total - $old_total );
	}

	/**
	 * Prices an `add_trait` / `remove_trait` / `modify_trait` change against a
	 * tiered_power block. `$in_type` must already be resolved by the caller
	 * (`is_in_type()`/`is_in_type_pure()`) - this function has no DB access of its own.
	 */
	public static function price_tiered_power_change(
		array $sheet_data,
		$definition,
		string $change_type,
		array $change_data,
		bool $in_type
	): int {
		$trait      = $change_data['trait'] ?? [];
		$name       = $trait['name'] ?? null;
		$block_slug = $change_data['block_slug'] ?? '';
		if ( $name === null ) {
			return 0;
		}

		$power = self::find_power( $definition, $name );
		if ( ! $power ) {
			return 0;
		}

		$sequential = ! empty( $definition->sequential );
		$modifier   = $in_type ? 0 : (int) ( $definition->out_of_type_cost_modifier ?? 0 );

		$power_name = $trait['power_name'] ?? null;

		// An Elder-and-above pick (Decision 037: no numbered ladder position, matched by
		// name within the tier instead) is identified by (family, power_name) together,
		// never by a numbered level - a family can hold several distinct Elder+ picks at
		// once (0.99.2-workflow.md: "you can have multiple powers at those levels"), so
		// adding one never touches whatever else the family already holds, priced as a
		// flat per-pick transaction rather than a sequential ladder step.
		if ( $power_name !== null ) {
			$already_held = self::find_held_power( $sheet_data, $block_slug, $name, $power_name ) !== null;
			$cost         = self::elder_tier_cost( $power, $power_name ) + $modifier;

			if ( 'remove_trait' === $change_type ) {
				return $already_held ? -$cost : 0;
			}
			if ( 'add_trait' === $change_type ) {
				// Defensive: the UI never re-offers an already-held pick, so this is
				// reachable only via a malformed or replayed request.
				return $already_held ? 0 : $cost;
			}
			// modify_trait reaches here only for a metadata edit (e.g. tradition) on an
			// already-identified pick - the pick itself isn't being bought or sold.
			return 0;
		}

		// Numbered-ladder pricing: one plain holding per family, identified by name alone.
		$held      = self::find_held_power( $sheet_data, $block_slug, $name );
		$old_level = $held['level'] ?? 0;
		$new_level = $old_level;

		if ( 'add_trait' === $change_type ) {
			$old_level = 0;
			$new_level = (int) ( $trait['level'] ?? 1 );
		} elseif ( 'remove_trait' === $change_type ) {
			$new_level = 0;
		} elseif ( 'modify_trait' === $change_type && array_key_exists( 'level', $trait ) ) {
			$new_level = (int) $trait['level'];
		}

		if ( $sequential ) {
			return self::sequential_step_cost( $definition, $power, $old_level, $new_level, $modifier );
		}

		$old_cost = $old_level > 0 ? self::level_base_cost( $definition, $power, $old_level ) + $modifier : 0;
		$new_cost = $new_level > 0 ? self::level_base_cost( $definition, $power, $new_level ) + $modifier : 0;
		return $new_cost - $old_cost;
	}

	/**
	 * Prices a `modify_resource` change against a resource_pool block - the
	 * purchase-flow half of PC-9 (`point-calculator-design.md` §4.3). A
	 * single change can touch several pools at once (`Change_Engine.php`
	 * merges the whole `values` map), so this sums each pool's own delta
	 * rather than pricing one pool per call. A pool with no `cost_per_dot`
	 * (`werewolf-renown`, awarded not bought; wraith `Angst`; changeling
	 * `Banality`, a penalty track) contributes zero - free, not unpriced,
	 * since a purchase that touches no priced pool genuinely costs nothing.
	 * Never refunds below a pool's own `free_dots` baseline.
	 */
	public static function price_resource_pool_change( array $sheet_data, $definition, string $block_slug, array $change_data ): int {
		$values = (array) ( $change_data['values'] ?? [] );
		$total  = 0;

		foreach ( $values as $pool_name => $new_value ) {
			$pool_def = self::find_pool( $definition, (string) $pool_name );
			if ( $pool_def === null || ! isset( $pool_def->cost_per_dot ) ) {
				continue;
			}

			$free          = (int) ( $pool_def->free_dots ?? 0 );
			$old_value     = $sheet_data[ $block_slug ][ $pool_name ] ?? null;
			$old_permanent = self::pool_permanent_value( $old_value, (int) ( $pool_def->default_start ?? 0 ) );
			$new_permanent = self::pool_permanent_value( $new_value, $old_permanent );

			$old_chargeable = max( 0, $old_permanent - $free );
			$new_chargeable = max( 0, $new_permanent - $free );

			$total += ( $new_chargeable - $old_chargeable ) * (int) $pool_def->cost_per_dot;
		}

		return $total;
	}

	/**
	 * Reads a resource pool's permanent rating from its stored value, which
	 * is either `{permanent, temporary}` or a bare int in an older/simpler
	 * shape - matching `Sheet_Document::resource_pool_rows()`'s own reading
	 * of the same data.
	 */
	private static function pool_permanent_value( $value, int $default ): int {
		if ( is_array( $value ) ) {
			return (int) ( $value['permanent'] ?? $default );
		}
		return $value === null ? $default : (int) $value;
	}

	/**
	 * Finds a pool definition by name within a resource_pool block
	 * definition. Scans the definition's `pools` list for an entry whose
	 * name matches exactly, returning null when no match is found.
	 */
	private static function find_pool( $definition, string $name ) {
		foreach ( ( $definition->pools ?? [] ) as $pool ) {
			if ( ( $pool->name ?? null ) === $name ) {
				return $pool;
			}
		}
		return null;
	}

	// Held-state pricing: prices what a character currently holds, not a proposed change.
	// Used by Services\Point_Audit - never by the purchase flow, which always prices a
	// delta via the price_*_change() functions above. Pure, DB-free, under the same
	// banner as the rest of this section (point-calculator-design.md §5.1): every number
	// Point_Audit prints comes from here, so a previewed purchase and an audit line can
	// never disagree.

	/**
	 * Prices one held `trait_list` entry. Never `0` for something merely
	 * unpriced - returns `xp: null` plus a machine-readable
	 * `unpriced_reason` instead, so a caller can tell "free" from "no rule
	 * exists to price this" (point-calculator-design.md §4.1, the governing
	 * rule of the whole item).
	 *
	 * @param array $held One entry from `sheet_data[block_slug]`: `name`, `count?`, `chosen_cost?`, `custom?`.
	 * @return array{xp:?int,basis:string,unpriced_reason:?string}
	 */
	public static function price_held_trait_list_item( $definition, array $held ): array {
		$name = $held['name'] ?? null;
		if ( $name === null ) {
			return [ 'xp' => null, 'basis' => 'catalog_cost', 'unpriced_reason' => 'held_block_not_in_catalog' ];
		}

		if ( ! empty( $held['custom'] ) ) {
			if ( array_key_exists( 'chosen_cost', $held ) && $held['chosen_cost'] !== null ) {
				$count = max( 1, (int) ( $held['count'] ?? 1 ) );
				$sign  = ! empty( $definition->negative ) ? -1 : 1;
				return [ 'xp' => $sign * (int) $held['chosen_cost'] * $count, 'basis' => 'chosen_cost', 'unpriced_reason' => null ];
			}
			return [ 'xp' => null, 'basis' => 'catalog_cost', 'unpriced_reason' => 'custom_no_catalog_entry' ];
		}

		$item = self::find_item( $definition, $name );
		if ( $item === null ) {
			return [ 'xp' => null, 'basis' => 'catalog_cost', 'unpriced_reason' => 'name_not_in_catalog' ];
		}
		if ( ! isset( $item->cost ) || (string) $item->cost === '' ) {
			return [ 'xp' => null, 'basis' => 'catalog_cost', 'unpriced_reason' => 'catalog_item_has_no_cost' ];
		}

		$cost_string = (string) $item->cost;
		$rule        = self::parse_cost_rule( $cost_string );
		$chosen      = $held['chosen_cost'] ?? null;
		$unit_cost   = self::price_item_cost( $cost_string, $chosen );
		$count       = max( 1, (int) ( $held['count'] ?? 1 ) );
		$sign        = ! empty( $definition->negative ) ? -1 : 1;

		$basis = 'catalog_cost';
		if ( in_array( $rule['type'], [ 'set', 'range' ], true ) ) {
			$basis = $chosen !== null ? 'chosen_cost' : 'rule_floor';
		}

		return [ 'xp' => $sign * $unit_cost * $count, 'basis' => $basis, 'unpriced_reason' => null ];
	}

	/**
	 * Prices one held `tiered_power` entry. Mirrors
	 * `price_tiered_power_change()`'s three-way dispatch (§4.2) exactly, but
	 * against a held state rather than a before/after pair: a flat
	 * `elder_tier_cost()` per named pick, `sequential_step_cost( $power, 0,
	 * $level, $modifier )` for a sequential block - every seeded ladder since
	 * the owner's "levels add up" ruling (1.0.0-review F-040) - or one flat
	 * `level_base_cost()` for a block a chronicle has switched to flat pricing.
	 *
	 * @param array $held One entry from `sheet_data[block_slug]`: `name`, `level?`, `power_name?`, `custom?`/`keep_custom?`, `chosen_cost?`.
	 * @return array{xp:?int,basis:string,unpriced_reason:?string}
	 */
	public static function price_held_tiered_power( $definition, array $held, bool $in_type ): array {
		$name = $held['name'] ?? null;
		if ( $name === null ) {
			return [ 'xp' => null, 'basis' => 'flat_level', 'unpriced_reason' => 'held_block_not_in_catalog' ];
		}

		if ( ! empty( $held['custom'] ) || ! empty( $held['keep_custom'] ) ) {
			if ( array_key_exists( 'chosen_cost', $held ) && $held['chosen_cost'] !== null ) {
				return [ 'xp' => (int) $held['chosen_cost'], 'basis' => 'chosen_cost', 'unpriced_reason' => null ];
			}
			return [ 'xp' => null, 'basis' => 'flat_level', 'unpriced_reason' => 'custom_no_catalog_entry' ];
		}

		$power = self::find_power( $definition, $name );
		if ( $power === null ) {
			return [ 'xp' => null, 'basis' => 'flat_level', 'unpriced_reason' => 'family_not_in_catalog' ];
		}

		$modifier   = $in_type ? 0 : (int) ( $definition->out_of_type_cost_modifier ?? 0 );
		$power_name = ( $held['power_name'] ?? '' ) !== '' ? $held['power_name'] : null;

		if ( $power_name !== null ) {
			$level_entry = self::find_power_level_by_name( $power, $power_name );
			if ( $level_entry === null ) {
				return [ 'xp' => null, 'basis' => 'elder_pick', 'unpriced_reason' => 'family_not_in_catalog' ];
			}
			$tier = strtolower( (string) ( $level_entry->tier ?? '' ) );
			if ( $tier === 'innate' && ! isset( $level_entry->cost ) ) {
				return [ 'xp' => 0, 'basis' => 'innate_free', 'unpriced_reason' => null ];
			}
			$cost  = self::elder_tier_cost( $power, $power_name ) + $modifier;
			$basis = isset( $level_entry->cost ) ? 'elder_pick' : 'tier_fallback';
			return [ 'xp' => $cost, 'basis' => $basis, 'unpriced_reason' => null ];
		}

		$level = (int) ( $held['level'] ?? 0 );
		if ( $level <= 0 ) {
			return [ 'xp' => null, 'basis' => 'flat_level', 'unpriced_reason' => 'level_has_no_cost' ];
		}

		if ( ! self::rank_is_valid_for_power( $power, $level ) ) {
			return [ 'xp' => null, 'basis' => 'flat_level', 'unpriced_reason' => 'level_has_no_cost' ];
		}

		if ( ! empty( $definition->sequential ) ) {
			return [ 'xp' => self::sequential_step_cost( $definition, $power, 0, $level, $modifier ), 'basis' => 'sequential_sum', 'unpriced_reason' => null ];
		}

		return [ 'xp' => self::level_base_cost( $definition, $power, $level ) + $modifier, 'basis' => 'flat_level', 'unpriced_reason' => null ];
	}

	/**
	 * Prices one held `resource_pool` rating (PC-9). Excluded from every
	 * total until a pool declares `cost_per_dot` (§4.3) - pricing it in the
	 * audit alone, ahead of the purchase flow, would bill a Storyteller for
	 * something the character editor gives away free.
	 *
	 * @param mixed $value Raw `sheet_data[block_slug][pool_name]` value - `{permanent,temporary}` or a bare int.
	 * @return array{xp:?int,basis:string,unpriced_reason:?string}
	 */
	public static function price_held_resource_pool( $definition, string $pool, $value ): array {
		$pool_def = self::find_pool( $definition, $pool );
		if ( $pool_def === null ) {
			return [ 'xp' => null, 'basis' => 'catalog_cost', 'unpriced_reason' => 'held_block_not_in_catalog' ];
		}
		if ( ! isset( $pool_def->cost_per_dot ) ) {
			return [ 'xp' => null, 'basis' => 'catalog_cost', 'unpriced_reason' => 'resource_pool_no_pricing_rule' ];
		}

		$permanent  = self::pool_permanent_value( $value, (int) ( $pool_def->default_start ?? 0 ) );
		$free       = (int) ( $pool_def->free_dots ?? 0 );
		$chargeable = max( 0, $permanent - $free );

		return [ 'xp' => $chargeable * (int) $pool_def->cost_per_dot, 'basis' => 'catalog_cost', 'unpriced_reason' => null ];
	}

	/**
	 * Finds a tiered_power level entry by its exact numbered `level`. Returns
	 * null for a D66 tied rank (several items share the tier, all `level: null`)
	 * even when the rank itself is real - use `rank_is_valid_for_power()` to
	 * check existence and `level_base_cost()` to price it regardless of ties.
	 */
	private static function find_power_level( $power, int $level ) {
		foreach ( ( $power->levels ?? [] ) as $power_level ) {
			if ( (int) ( $power_level->level ?? 0 ) === $level ) {
				return $power_level;
			}
		}
		return null;
	}

	/**
	 * True when a numbered rank is a real, purchasable position in this power's
	 * ladder - either a single untied item carries that exact `level` (with a
	 * real cost), or the rank's tier (via `tier_for_rank()`) is present among
	 * the family's items at all. D66: several items can share one tier, all
	 * `level: null` - the rank itself is still real and priced via the
	 * canonical tier ladder (`level_base_cost()`), regardless of whether any
	 * one of the tied items happens to carry its own `cost` field.
	 */
	private static function rank_is_valid_for_power( $power, int $level ): bool {
		$exact = self::find_power_level( $power, $level );
		if ( $exact !== null ) {
			return isset( $exact->cost );
		}
		$tier = self::tier_for_rank( $level );
		if ( $tier === null ) {
			return false;
		}
		foreach ( ( $power->levels ?? [] ) as $power_level ) {
			if ( ( $power_level->tier ?? null ) === $tier ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Maps a numbered rank (1=basic, 2=intermediate, ...) to its tier name, using
	 * `TIER_COSTS`' own key order (`innate` excluded - never a numbered rank).
	 * Mirrors `Database\Seeder::TIER_RANKS` exactly; duplicated rather than
	 * shared, since Services doesn't otherwise depend on the Database layer for
	 * one lookup.
	 */
	private static function tier_for_rank( int $rank ): ?string {
		static $numbered = null;
		if ( $numbered === null ) {
			$numbered = array_values( array_diff( array_keys( self::TIER_COSTS ), [ 'innate' ] ) );
		}
		return $numbered[ $rank - 1 ] ?? null;
	}

	/**
	 * Finds a tiered_power level entry by its Elder-and-above `power_name`.
	 */
	private static function find_power_level_by_name( $power, string $power_name ) {
		foreach ( ( $power->levels ?? [] ) as $power_level ) {
			if ( ( $power_level->power_name ?? '' ) === $power_name ) {
				return $power_level;
			}
		}
		return null;
	}

	/**
	 * Computes the cost of moving a sequential power between two levels
	 * as the sum of every step's base cost in between: raising level 2 to
	 * 4 costs the level-3 step plus the level-4 step, never one flat
	 * level-4 price. Lowering the level charges the same steps as a
	 * negative value, a refund, uncapped.
	 */
	private static function sequential_step_cost( $definition, $power, int $old_level, int $new_level, int $modifier ): int {
		if ( $new_level === $old_level ) {
			return 0;
		}
		$direction = $new_level > $old_level ? 1 : -1;
		$lo        = min( $old_level, $new_level ) + 1;
		$hi        = max( $old_level, $new_level );

		$sum = 0;
		for ( $level = $lo; $level <= $hi; $level++ ) {
			$sum += self::level_base_cost( $definition, $power, $level ) + $modifier;
		}
		return $direction * $sum;
	}

	/**
	 * Looks up the base cost of one level of a tiered power. A single untied
	 * item carrying the exact `level` prices from its own `cost` field, parsed
	 * as free text (the same rule used for trait_list item costs) - this is
	 * how a real catalog-entered cost correction or edition variant stays
	 * respected. A D66 tied rank (several items share the tier, no single item
	 * carries the exact number) prices from `block_tier_costs()` - the block's
	 * own real per-tier cost, derived empirically from its seeded data - rather
	 * than guessing which tied item's own `cost` field to trust (real seeded
	 * data is not perfectly uniform within a tier - a stray miskeyed cost on
	 * one alternative is not the rank's real price) or a single hardcoded
	 * table (a Mage Sphere's real tier costs, 5/10/15/20/25, are not a
	 * Discipline's 3/6/9/12/15/18/21 - each block sets its own scale). Returns
	 * 0 when the level has no cost set at all.
	 */
	private static function level_base_cost( $definition, $power, int $level ): int {
		$exact = self::find_power_level( $power, $level );
		if ( $exact !== null ) {
			return isset( $exact->cost ) ? self::price_item_cost( (string) $exact->cost, null ) : 0;
		}
		$tier = self::tier_for_rank( $level );
		return $tier !== null ? self::block_tier_costs( $definition )[ $tier ] ?? 0 : 0;
	}

	/**
	 * Derives a block's real per-tier cost ladder from its own seeded data,
	 * rather than a single hardcoded table - each block can set its own scale
	 * (Mage Spheres are 5/10/15/20/25, Vampire Disciplines 3/6/9/12/15/18/21).
	 * For each tier, takes the most common cost among every item across every
	 * power in the block that carries that tier - a plurality vote across the
	 * whole block outweighs the rare single miskeyed item (D66's own found
	 * example: one Animalism "advanced" item costs 3 where its sibling, and
	 * every other Discipline's real advanced-tier item, costs 9). Memoized per
	 * request on the definition object itself; a definition is loaded once per
	 * request and never mutated after seeding.
	 */
	private static function block_tier_costs( $definition ): array {
		static $cache = null;
		if ( $cache !== null && $cache[0] === $definition ) {
			return $cache[1];
		}

		$tallies = [];
		foreach ( ( $definition->powers ?? [] ) as $power ) {
			foreach ( ( $power->levels ?? [] ) as $power_level ) {
				$tier = $power_level->tier ?? null;
				if ( $tier === null || $tier === 'innate' || ! isset( $power_level->cost ) ) {
					continue;
				}
				$cost = self::price_item_cost( (string) $power_level->cost, null );
				$tallies[ $tier ][ $cost ] = ( $tallies[ $tier ][ $cost ] ?? 0 ) + 1;
			}
		}

		$costs = [];
		foreach ( $tallies as $tier => $by_cost ) {
			arsort( $by_cost );
			$costs[ $tier ] = (int) array_key_first( $by_cost );
		}

		$cache = [ $definition, $costs ];
		return $costs;
	}

	// Free-text cost parsing.

	/**
	 * Parses a GVM `cost` string into an explicit pricing rule.
	 * Recognizes a fixed integer, a set of "or"-separated alternatives, or
	 * a numeric range, and falls back to reading a leading integer from
	 * the string rather than throwing on an unrecognized format.
	 *
	 * @return array{type: 'fixed'|'set'|'range', values: int[]}
	 */
	public static function parse_cost_rule( string $cost_string ): array {
		$trimmed = trim( $cost_string );

		if ( preg_match( '/^-?\d+$/', $trimmed ) ) {
			return [ 'type' => 'fixed', 'values' => [ (int) $trimmed ] ];
		}

		if ( preg_match( '/^-?\d+(?:\s+or\s+-?\d+)+$/i', $trimmed ) ) {
			$values = array_map( 'intval', preg_split( '/\s+or\s+/i', $trimmed ) ?: [] );
			return [ 'type' => 'set', 'values' => $values ];
		}

		if ( preg_match( '/^(-?\d+)\s*-\s*(-?\d+)$/', $trimmed, $m ) ) {
			return [ 'type' => 'range', 'values' => [ (int) $m[1], (int) $m[2] ] ];
		}

		if ( preg_match( '/-?\d+/', $trimmed, $m ) ) {
			return [ 'type' => 'fixed', 'values' => [ (int) $m[0] ] ];
		}

		return [ 'type' => 'fixed', 'values' => [ 0 ] ];
	}

	/**
	 * Resolves a unit cost from a `cost` string plus the player's chosen
	 * value for a variable-cost item. Re-validates the chosen value
	 * against the parsed pricing rule rather than trusting it outright,
	 * falling back to the lowest allowed value when it is missing or
	 * invalid.
	 */
	public static function price_item_cost( string $cost_string, $chosen ): int {
		$rule = self::parse_cost_rule( $cost_string );

		if ( 'fixed' === $rule['type'] ) {
			return $rule['values'][0];
		}

		if ( 'set' === $rule['type'] ) {
			if ( null !== $chosen && in_array( (int) $chosen, $rule['values'], true ) ) {
				return (int) $chosen;
			}
			return min( $rule['values'] );
		}

		// range
		[ $lo, $hi ] = $rule['values'];
		if ( null !== $chosen && (int) $chosen >= $lo && (int) $chosen <= $hi ) {
			return (int) $chosen;
		}
		return $lo;
	}

	// Lookups.

	/**
	 * Finds a catalog item by name within a trait_list block definition.
	 * Scans the definition's `items` list for an entry whose name matches
	 * exactly, returning null when no match is found.
	 */
	private static function find_item( $definition, string $name ) {
		foreach ( ( $definition->items ?? [] ) as $item ) {
			if ( ( $item->name ?? null ) === $name ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Finds a power by name within a tiered_power block definition. Scans
	 * the definition's `powers` list for an entry whose name matches
	 * exactly, returning null when no match is found.
	 */
	private static function find_power( $definition, string $name ) {
		foreach ( ( $definition->powers ?? [] ) as $power ) {
			if ( ( $power->name ?? null ) === $name ) {
				return $power;
			}
		}
		return null;
	}

	/**
	 * Finds a character's currently held instance of a trait within a
	 * block. Scans the block's stored items for one whose name matches,
	 * returning its count and chosen cost, or null when the character
	 * does not hold the trait.
	 *
	 * @return array{count: int, chosen_cost: int|null}|null
	 */
	private static function find_held_trait( array $sheet_data, string $block_slug, string $name ): ?array {
		foreach ( ( $sheet_data[ $block_slug ] ?? [] ) as $item ) {
			if ( ( $item['name'] ?? null ) === $name ) {
				return [
					'count'       => (int) ( $item['count'] ?? 1 ),
					'chosen_cost' => $item['chosen_cost'] ?? null,
				];
			}
		}
		return null;
	}

	/**
	 * Finds a character's currently held instance of a power within a
	 * block. A family holds at most one plain numbered entry (no
	 * `power_name` of its own) - pass `$power_name` as null to find that
	 * one. It may also hold several distinct Elder-and-above picks at once
	 * (Decision 037), each identified by its own `power_name` - pass the
	 * specific one to find that pick and no other, so adding or removing
	 * one Elder+ pick never matches a sibling pick under the same family.
	 *
	 * @return array{level: int, power_name: ?string}|null
	 */
	private static function find_held_power( array $sheet_data, string $block_slug, string $name, ?string $power_name = null ): ?array {
		foreach ( ( $sheet_data[ $block_slug ] ?? [] ) as $item ) {
			if ( ( $item['name'] ?? null ) !== $name ) {
				continue;
			}
			$item_power_name = ( $item['power_name'] ?? '' ) !== '' ? $item['power_name'] : null;
			if ( $power_name !== null ) {
				if ( $item_power_name === $power_name ) {
					return [ 'level' => (int) ( $item['level'] ?? 0 ), 'power_name' => $item_power_name ];
				}
				continue;
			}
			if ( $item_power_name === null ) {
				return [ 'level' => (int) ( $item['level'] ?? 0 ), 'power_name' => null ];
			}
		}
		return null;
	}

	/**
	 * Real MET tier ladder cost (met-mechanics.csv, confirmed against the
	 * real Celerity/Elder/Master rows: Basic 3, Intermediate 6, Advanced 9,
	 * Elder 12, Master 15 - Ascended 18 and Methuselah 21 continue the same
	 * +3-per-tier progression but have no priced catalog example in the CSV
	 * to confirm directly). Used only as a fallback when a level has no
	 * explicit `cost` of its own.
	 */
	const TIER_COSTS = [
		'innate'       => 0,
		'basic'        => 3,
		'intermediate' => 6,
		'advanced'     => 9,
		'elder'        => 12,
		'master'       => 15,
		'ascended'     => 18,
		'methuselah'   => 21,
	];

	/**
	 * Looks up the base cost of one Elder-and-above power pick by name
	 * within a tiered_power power's `levels` list. Prefers the level's own
	 * `cost` (present for the specific powers met-mechanics.csv prices
	 * directly), falling back to TIER_COSTS by the level's `tier` for the
	 * many real Elder+ catalog entries that carry a tier label but no
	 * individually priced cost. Returns 0 when the name matches nothing.
	 */
	private static function elder_tier_cost( $power, string $power_name ): int {
		foreach ( ( $power->levels ?? [] ) as $power_level ) {
			if ( ( $power_level->power_name ?? '' ) !== $power_name ) {
				continue;
			}
			if ( isset( $power_level->cost ) ) {
				return self::price_item_cost( (string) $power_level->cost, null );
			}
			return self::TIER_COSTS[ strtolower( (string) ( $power_level->tier ?? '' ) ) ] ?? 0;
		}
		return 0;
	}
}
