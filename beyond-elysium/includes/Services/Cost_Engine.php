<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Creature_Stack;

defined( 'ABSPATH' ) || exit;

/**
 * Derives the XP cost of a proposed character change.
 *
 * A trait holds only its name and level; this class computes its price at
 * runtime from the block and stack definitions plus the character's
 * current identity, every time it is asked. Pricing lives in exactly one
 * place, so a previewed cost and an approved cost can never disagree.
 *
 * `cost_for_change()` and `is_in_type()` are `$wpdb`-touching wrappers
 * around pure, database-free logic: `price_trait_list_change()`,
 * `price_tiered_power_change()`, and `is_in_type_pure()` do the actual
 * pricing and can be tested without a database.
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

			default:
				// resource_pool and identity_field changes carry no XP cost.
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
		$stack = Creature_Stack::find_by_slug( $character->stack_slug );
		if ( ! $stack ) {
			return true;
		}

		$in_type_source = null;
		foreach ( ( $stack->stack_definition->sections ?? [] ) as $section ) {
			if ( ( $section->block_slug ?? null ) === $block_slug && ! empty( $section->in_type_source ) ) {
				$in_type_source = $section->in_type_source;
				break;
			}
		}
		if ( ! $in_type_source ) {
			return true;
		}

		$parts = explode( '.', $in_type_source, 2 );
		if ( count( $parts ) !== 2 ) {
			return true;
		}
		[ $identity_block_slug, $field_name ] = $parts;

		$identity_block = Schema_Block::find_by_slug( $identity_block_slug );
		if ( ! $identity_block ) {
			return true;
		}

		$sheet_data = is_array( $character->sheet_data ?? null ) ? $character->sheet_data : [];
		return self::is_in_type_pure( $sheet_data, $identity_block_slug, $field_name, $trait_name, $identity_block->definition );
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
			return self::sequential_step_cost( $power, $old_level, $new_level, $modifier );
		}

		$old_cost = $old_level > 0 ? self::level_base_cost( $power, $old_level ) + $modifier : 0;
		$new_cost = $new_level > 0 ? self::level_base_cost( $power, $new_level ) + $modifier : 0;
		return $new_cost - $old_cost;
	}

	/**
	 * Computes the cost of moving a sequential power between two levels
	 * as the sum of every step's base cost in between: raising level 2 to
	 * 4 costs the level-3 step plus the level-4 step, never one flat
	 * level-4 price. Lowering the level charges the same steps as a
	 * negative value, a refund, uncapped.
	 */
	private static function sequential_step_cost( $power, int $old_level, int $new_level, int $modifier ): int {
		if ( $new_level === $old_level ) {
			return 0;
		}
		$direction = $new_level > $old_level ? 1 : -1;
		$lo        = min( $old_level, $new_level ) + 1;
		$hi        = max( $old_level, $new_level );

		$sum = 0;
		for ( $level = $lo; $level <= $hi; $level++ ) {
			$sum += self::level_base_cost( $power, $level ) + $modifier;
		}
		return $direction * $sum;
	}

	/**
	 * Looks up the base cost of one level of a tiered power. Finds the
	 * matching level entry in the power's `levels` list and parses its
	 * `cost` field as free text, the same rule used for trait_list item
	 * costs, returning 0 when the level has no cost set.
	 */
	private static function level_base_cost( $power, int $level ): int {
		foreach ( ( $power->levels ?? [] ) as $power_level ) {
			if ( (int) ( $power_level->level ?? 0 ) === $level ) {
				if ( ! isset( $power_level->cost ) ) {
					return 0;
				}
				return self::price_item_cost( (string) $power_level->cost, null );
			}
		}
		return 0;
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
			$values = array_map( 'intval', preg_split( '/\s+or\s+/i', $trimmed ) );
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
	 * block. Scans the block's stored items for one whose name matches,
	 * returning its level, or null when the character does not hold the
	 * power.
	 *
	 * @return array{level: int}|null
	 */
	private static function find_held_power( array $sheet_data, string $block_slug, string $name ): ?array {
		foreach ( ( $sheet_data[ $block_slug ] ?? [] ) as $item ) {
			if ( ( $item['name'] ?? null ) === $name ) {
				return [ 'level' => (int) ( $item['level'] ?? 0 ) ];
			}
		}
		return null;
	}
}
