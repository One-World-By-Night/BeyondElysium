<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Creature_Stack;

defined( 'ABSPATH' ) || exit;

/**
 * Derives the XP cost of a proposed character change.
 */
class Cost_Engine {

	// Public entry points: DB-touching wrappers.

	/**
	 * Prices one proposed change against a character's current state.
	 *
	 * @param object $character Character row with decoded (array) `sheet_data` and a
	 *                           `stack_slug`.
	 * @param array  $change    Shape: `change_type`, `change_data` (with `block_slug` plus
	 *                           `trait` / `values` / `fields`), matching `ChangeRequest`.
	 * @return int Signed XP delta - positive costs, negative refunds.
	 */
	public static function cost_for_change( $character, array $change ): int {
		return self::quote_for_change( $character, $change )['xp'];
	}

	/**
	 * The most a Storyteller may price one unit of a custom purchase.
	 */
	public const MAX_CUSTOM_PRICE = 500;

	/**
	 * `cost_for_change()` plus whether the figure is a price.
	 *
	 * @param object $character  As `cost_for_change()`.
	 * @param array  $change     As `cost_for_change()`.
	 * @param bool   $by_manager Whether the submitter is a Storyteller. Only a Storyteller's own
	 *                           `chosen_cost` on a custom trait is read as its price; a player never
	 *                           prices their own homebrew.
	 * @return array{xp:int,priced:bool,unpriced_reason:?string}
	 */
	public static function quote_for_change( $character, array $change, bool $by_manager = false ): array {
		$change_data = $change['change_data'] ?? [];
		$block_slug  = $change_data['block_slug'] ?? null;
		if ( ! $block_slug ) {
			return self::quoted( 0 );
		}

		// Prefer this character's chronicle-specific fork of the block, if one exists.
		$block = Purchase_Scope::widen( Schema_Block::find_for_game( $block_slug, (string) ( $character->owner_slug ?? '' ) ), (string) ( $character->owner_slug ?? '' ) );
		if ( ! $block ) {
			// A missing block has no cost rule to apply; default to zero.
			return self::quoted( 0 );
		}

		$sheet_data  = is_array( $character->sheet_data ?? null ) ? $character->sheet_data : [];
		$change_type = $change['change_type'] ?? '';

		switch ( $block->section_type ) {
			case 'trait_list':
				return self::quote_trait_list_change( $sheet_data, $block->definition, $block_slug, $change_type, $change_data, $by_manager );

			case 'tiered_power':
				$trait_name = $change_data['trait']['name'] ?? '';
				$in_type    = $trait_name !== '' ? self::is_in_type( $character, $block_slug, $trait_name ) : true;
				return self::quote_tiered_power_change( $sheet_data, $block->definition, $change_type, $change_data, $in_type, $by_manager );

			case 'resource_pool':
				return self::quoted( self::price_resource_pool_change( $sheet_data, $block->definition, $block_slug, $change_data ) );

			default:
				// identity_field changes carry no XP cost.
				return self::quoted( 0 );
		}
	}

	/**
	 * Resolves whether a trait is in-type for the character.
	 */
	public static function is_in_type( $character, string $block_slug, string $trait_name ): bool {
		return ( self::in_type_check( $character, $block_slug ) )( $trait_name );
	}

	/**
	 * Loads what `is_in_type()` needs for one block.
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
	 * Determines whether a trait name is in-type for a given identity value.
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
	 * A trait_list change, quoted.
	 *
	 * @param array<string,mixed> $sheet_data
	 * @param array<string,mixed> $change_data
	 * @return array{xp:int,priced:bool,unpriced_reason:?string}
	 */
	public static function quote_trait_list_change(
		array $sheet_data,
		$definition,
		string $block_slug,
		string $change_type,
		array $change_data,
		bool $by_manager = false
	): array {
		$trait = $change_data['trait'] ?? [];
		$name  = $trait['name'] ?? null;
		if ( $name === null ) {
			return self::quoted( 0 );
		}

		if ( empty( $trait['custom'] ) && self::find_item( $definition, $name ) !== null ) {
			return self::quoted( self::price_trait_list_change( $sheet_data, $definition, $block_slug, $change_type, $change_data ) );
		}

		$sign = ! empty( $definition->negative ) ? -1 : 1;
		$own  = $by_manager ? self::usable_price( $trait['chosen_cost'] ?? null ) : null;

		if ( 'add_trait' === $change_type ) {
			if ( $own === null ) {
				return self::unquoted();
			}
			return self::quoted( $sign * $own * max( 1, (int) ( $trait['count'] ?? 1 ) ) );
		}

		if ( 'modify_trait' === $change_type ) {
			$held      = self::find_held_trait( $sheet_data, $block_slug, $definition, $trait, is_array( $change_data['previous'] ?? null ) ? $change_data['previous'] : null );
			$old_count = $held['count'] ?? 0;
			$new_count = array_key_exists( 'count', $trait ) ? max( 0, (int) $trait['count'] ) : $old_count;
			if ( $new_count <= $old_count ) {
				return self::quoted( 0 );
			}
			$unit = $own ?? ( isset( $held['chosen_cost'] ) ? (int) $held['chosen_cost'] : null );
			if ( $unit === null ) {
				return self::unquoted();
			}
			return self::quoted( $sign * $unit * ( $new_count - $old_count ) );
		}

		return self::quoted( 0 );
	}

	/**
	 * A tiered_power change, quoted.
	 *
	 * @param array<string,mixed> $sheet_data
	 * @param array<string,mixed> $change_data
	 * @return array{xp:int,priced:bool,unpriced_reason:?string}
	 */
	public static function quote_tiered_power_change(
		array $sheet_data,
		$definition,
		string $change_type,
		array $change_data,
		bool $in_type,
		bool $by_manager = false
	): array {
		$trait = $change_data['trait'] ?? [];
		$name  = $trait['name'] ?? null;
		if ( $name === null ) {
			return self::quoted( 0 );
		}

		if ( empty( $trait['custom'] ) && ! self::is_unpriceable_power( $definition, $trait ) ) {
			return self::quoted( self::price_tiered_power_change( $sheet_data, $definition, $change_type, $change_data, $in_type ) );
		}

		if ( 'add_trait' === $change_type ) {
			return self::unquoted();
		}
		if ( 'modify_trait' === $change_type && self::raises_a_power( $sheet_data, (string) ( $change_data['block_slug'] ?? '' ), $trait ) ) {
			return self::unquoted();
		}
		return self::quoted( 0 );
	}

	/**
	 * How many units one price covers.
	 *
	 * @param array<string,mixed> $sheet_data
	 * @param array<string,mixed> $change_data
	 * @return array{per:string,units:int}
	 */
	public static function price_units( array $sheet_data, $definition, string $block_slug, string $change_type, array $change_data ): array {
		$trait = $change_data['trait'] ?? [];

		if ( isset( $definition->powers ) && ! isset( $definition->items ) ) {
			$buys = 'add_trait' === $change_type
				|| ( 'modify_trait' === $change_type && self::raises_a_power( $sheet_data, $block_slug, $trait ) );
			return [ 'per' => 'pick', 'units' => $buys ? 1 : 0 ];
		}

		if ( 'add_trait' === $change_type ) {
			return [ 'per' => 'dot', 'units' => max( 1, (int) ( $trait['count'] ?? 1 ) ) ];
		}
		if ( 'modify_trait' === $change_type && array_key_exists( 'count', $trait ) ) {
			$held      = self::find_held_trait( $sheet_data, $block_slug, $definition, $trait, is_array( $change_data['previous'] ?? null ) ? $change_data['previous'] : null );
			$old_count = $held['count'] ?? 0;
			return [ 'per' => 'dot', 'units' => max( 0, max( 0, (int) $trait['count'] ) - $old_count ) ];
		}
		return [ 'per' => 'dot', 'units' => 0 ];
	}

	/**
	 * What a Storyteller's price makes of a purchase that was waiting for one: the change's own data with the price
	 * stamped onto the trait.
	 *
	 * @param array<string,mixed> $sheet_data
	 * @param array<string,mixed> $change_data
	 * @return array{change_data:array<string,mixed>,xp:int}
	 */
	public static function apply_set_price( array $sheet_data, $definition, string $block_slug, string $change_type, array $change_data, int $set_cost ): array {
		$trait = is_array( $change_data['trait'] ?? null ) ? $change_data['trait'] : [];
		$units = self::price_units( $sheet_data, $definition, $block_slug, $change_type, $change_data );

		if ( 'pick' === $units['per'] ) {
			$carried = 0;
			if ( 'modify_trait' === $change_type && $units['units'] > 0 ) {
				$held    = self::find_held_power( $sheet_data, $block_slug, (string) ( $trait['name'] ?? '' ), isset( $trait['power_name'] ) ? (string) $trait['power_name'] : null );
				$carried = (int) ( $held['chosen_cost'] ?? 0 );
			}
			$trait['chosen_cost'] = $carried + $set_cost;
			$xp                   = $units['units'] > 0 ? $set_cost : 0;
		} else {
			$trait['chosen_cost'] = $set_cost;
			$xp                   = ( ! empty( $definition->negative ) ? -1 : 1 ) * $set_cost * $units['units'];
		}

		$change_data['trait'] = $trait;
		unset( $change_data['cost_pending'] );
		return [ 'change_data' => $change_data, 'xp' => $xp ];
	}

	/** @return array{xp:int,priced:bool,unpriced_reason:?string} */
	private static function quoted( int $xp ): array {
		return [ 'xp' => $xp, 'priced' => true, 'unpriced_reason' => null ];
	}

	/** @return array{xp:int,priced:bool,unpriced_reason:?string} */
	private static function unquoted(): array {
		return [ 'xp' => 0, 'priced' => false, 'unpriced_reason' => 'custom_no_catalog_entry' ];
	}

	/**
	 * A whole number from 0 to `MAX_CUSTOM_PRICE`, or null: what a price is allowed to be.
	 */
	private static function usable_price( $value ): ?int {
		if ( $value === null || ! is_numeric( $value ) || (float) $value !== (float) (int) $value ) {
			return null;
		}
		$price = (int) $value;
		return $price >= 0 && $price <= self::MAX_CUSTOM_PRICE ? $price : null;
	}

	/**
	 * Whether a tiered change names a family, or a pick under one, that the catalog does not carry.
	 */
	private static function is_unpriceable_power( $definition, array $trait ): bool {
		$power = self::find_power( $definition, (string) ( $trait['name'] ?? '' ) );
		if ( $power === null ) {
			return true;
		}
		$power_name = $trait['power_name'] ?? null;
		return $power_name !== null && self::find_power_level_by_name( $power, (string) $power_name ) === null;
	}

	/**
	 * Whether a tiered `modify_trait` raises the level the character holds.
	 */
	private static function raises_a_power( array $sheet_data, string $block_slug, array $trait ): bool {
		if ( ! array_key_exists( 'level', $trait ) ) {
			return false;
		}
		$held = self::find_held_power( $sheet_data, $block_slug, (string) ( $trait['name'] ?? '' ), isset( $trait['power_name'] ) ? (string) $trait['power_name'] : null );
		return (int) $trait['level'] > (int) ( $held['level'] ?? 0 );
	}

	/**
	 * Prices an `add_trait`, `remove_trait`, or `modify_trait` change against a trait_list block.
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

		$held       = self::find_held_trait( $sheet_data, $block_slug, $definition, $trait, is_array( $change_data['previous'] ?? null ) ? $change_data['previous'] : null );
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
	 * Prices an `add_trait` / `remove_trait` / `modify_trait` change against a tiered_power block.
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

		if ( $power_name !== null ) {
			$already_held = self::find_held_power( $sheet_data, $block_slug, $name, $power_name ) !== null;
			$cost         = self::elder_tier_cost( $power, $power_name ) + $modifier;

			if ( 'remove_trait' === $change_type ) {
				return $already_held ? -$cost : 0;
			}
			if ( 'add_trait' === $change_type ) {
				// A pick that is already held.
				return $already_held ? 0 : $cost;
			}
			// modify_trait reaches here only for a metadata edit (e.g. tradition) on an already-identified pick.
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
	 * Prices a `modify_resource` change against a resource_pool block.
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
	 * Reads a resource pool's permanent rating from its stored value.
	 */
	private static function pool_permanent_value( $value, int $default ): int {
		if ( is_array( $value ) ) {
			return (int) ( $value['permanent'] ?? $default );
		}
		return $value === null ? $default : (int) $value;
	}

	/**
	 * Finds a pool definition by name within a resource_pool block definition.
	 */
	private static function find_pool( $definition, string $name ) {
		foreach ( ( $definition->pools ?? [] ) as $pool ) {
			if ( ( $pool->name ?? null ) === $name ) {
				return $pool;
			}
		}
		return null;
	}

	// Held-state pricing: prices what a character currently holds.

	/**
	 * Prices one held `trait_list` entry.
	 *
	 * @param array                 $held       One entry from `sheet_data[block_slug]`: `name`, `count?`, `chosen_cost?`, `custom?`.
	 * @param string $block_slug The slug this row is held under (`` cross-block alias routing - omit to skip it).
	 * @param array<string,object>  $blocks     The character's other blocks, keyed by slug, for `moved_from` resolution - `Point_Audit`'s own already-loaded set.
	 * @return array{xp:?int,basis:string,unpriced_reason:?string}
	 */
	public static function price_held_trait_list_item( $definition, array $held, string $block_slug = '', array $blocks = [] ): array {
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
		if ( $item === null && $block_slug !== '' && $blocks !== [] ) {
			$moved = Trait_Alias_Resolver::find_moved_item( $blocks, $block_slug, $name );
			if ( $moved !== null && isset( $moved['item']->name ) && is_string( $moved['item']->name ) ) {
				// Re-key the held row to its item's own current name before recursing.
				return self::price_held_trait_list_item(
					is_object( $moved['block']->definition ?? null ) ? $moved['block']->definition : (object) [],
					array_merge( $held, [ 'name' => $moved['item']->name ] )
				);
			}
		}
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
	 * Prices one held `tiered_power` entry.
	 *
	 * @param array                $held       One entry from `sheet_data[block_slug]`: `name`, `level?`, `power_name?`, `custom?`/`keep_custom?`, `chosen_cost?`.
	 * @param string $block_slug The slug this row is held under (cross-block alias routing - omit to skip it).
	 * @param array<string,object> $blocks     The character's other blocks, keyed by slug, for `moved_from` resolution - `Point_Audit`'s own already-loaded set.
	 * @return array{xp:?int,basis:string,unpriced_reason:?string}
	 */
	public static function price_held_tiered_power( $definition, array $held, bool $in_type, string $block_slug = '', array $blocks = [] ): array {
		$name = $held['name'] ?? null;
		if ( $name === null ) {
			return [ 'xp' => null, 'basis' => 'flat_level', 'unpriced_reason' => 'held_block_not_in_catalog' ];
		}

		$power    = self::find_power( $definition, $name );
		$modifier = $in_type ? 0 : (int) ( $definition->out_of_type_cost_modifier ?? 0 );

		if ( ! empty( $held['custom'] ) || ! empty( $held['keep_custom'] ) ) {
			return self::price_held_custom_pick( $power, $held, $modifier );
		}

		if ( $power === null && $block_slug !== '' && $blocks !== [] ) {
			$moved = Trait_Alias_Resolver::find_moved_power( $blocks, $block_slug, $name );
			if ( $moved !== null && isset( $moved['power']->name ) && is_string( $moved['power']->name ) ) {
				// Re-key the held row to its family's own current name before recursing.
				return self::price_held_tiered_power(
					is_object( $moved['block']->definition ?? null ) ? $moved['block']->definition : (object) [],
					array_merge( $held, [ 'name' => $moved['power']->name ] ),
					$in_type
				);
			}
		}

		if ( $power === null ) {
			return [ 'xp' => null, 'basis' => 'flat_level', 'unpriced_reason' => 'family_not_in_catalog' ];
		}

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
			if ( ! isset( $level_entry->cost ) && ! isset( self::TIER_COSTS[ $tier ] ) ) {
				return [ 'xp' => null, 'basis' => 'elder_pick', 'unpriced_reason' => 'catalog_item_has_no_cost' ];
			}
			$cost  = self::elder_tier_cost( $power, $power_name ) + $modifier;
			$basis = isset( $level_entry->cost ) ? 'elder_pick' : 'tier_fallback';
			return [ 'xp' => $cost, 'basis' => $basis, 'unpriced_reason' => null ];
		}

		$level = (int) ( $held['level'] ?? 0 );
		if ( $level <= 0 ) {
			return [ 'xp' => null, 'basis' => 'flat_level', 'unpriced_reason' => 'level_has_no_cost' ];
		}

		if ( self::declares_ladder( $definition ) && ! empty( $definition->sequential ) ) {
			$ceiling = count( self::ladder_tiers( $definition ) );
			if ( $level > $ceiling ) {
				return self::price_above_ceiling_total( $definition, $power, $ceiling, $level, $modifier );
			}
		}

		if ( ! self::rank_is_valid_for_power( $definition, $power, $level ) ) {
			return [ 'xp' => null, 'basis' => 'flat_level', 'unpriced_reason' => 'level_has_no_cost' ];
		}

		if ( ! empty( $definition->sequential ) ) {
			return [ 'xp' => self::sequential_step_cost( $definition, $power, 0, $level, $modifier ), 'basis' => 'sequential_sum', 'unpriced_reason' => null ];
		}

		return [ 'xp' => self::level_base_cost( $definition, $power, $level ) + $modifier, 'basis' => 'flat_level', 'unpriced_reason' => null ];
	}

	/**
	 * The above-ceiling case: the full declared ladder plus `(level - ceiling)` unnamed picks, each priced at the first
	 * rank above the ladder; unpriced when the block declares no rank past its ladder.
	 *
	 * @return array{xp:?int,basis:string,unpriced_reason:?string}
	 */
	private static function price_above_ceiling_total( $definition, $power, int $ceiling, int $level, int $modifier ): array {
		$pick_rank = self::first_pick_rank( $definition );
		$pick_cost = $pick_rank !== null ? ( self::block_tier_costs( $definition )[ $pick_rank ] ?? null ) : null;
		if ( $pick_cost === null ) {
			return [ 'xp' => null, 'basis' => 'sequential_sum', 'unpriced_reason' => 'level_above_ceiling_no_pick_rank' ];
		}

		$ladder_cost = self::sequential_step_cost( $definition, $power, 0, $ceiling, $modifier );
		$picks       = $level - $ceiling;
		return [ 'xp' => $ladder_cost + $picks * ( $pick_cost + $modifier ), 'basis' => 'sequential_sum', 'unpriced_reason' => null ];
	}

	/**
	 * Prices a held custom/keep_custom tiered_power entry.
	 *
	 * @return array{xp:?int,basis:string,unpriced_reason:?string}
	 */
	private static function price_held_custom_pick( $power, array $held, int $modifier ): array {
		$power_name = ( $held['power_name'] ?? '' ) !== '' ? $held['power_name'] : null;

		if ( $power !== null && $power_name !== null ) {
			$level_entry = self::find_power_level_by_name( $power, $power_name );
			if ( $level_entry !== null && isset( $level_entry->cost ) ) {
				return [ 'xp' => self::price_item_cost( (string) $level_entry->cost, null ) + $modifier, 'basis' => 'elder_pick', 'unpriced_reason' => null ];
			}
		}

		if ( array_key_exists( 'chosen_cost', $held ) && $held['chosen_cost'] !== null ) {
			return [ 'xp' => (int) $held['chosen_cost'], 'basis' => 'chosen_cost', 'unpriced_reason' => null ];
		}

		return [ 'xp' => null, 'basis' => 'elder_pick', 'unpriced_reason' => 'custom_no_catalog_entry' ];
	}

	/**
	 * Prices one held `resource_pool` rating.
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
	 * Finds a tiered_power level entry by its exact numbered `level`.
	 */
	private static function find_power_level( $power, int $level ) {
		foreach ( Power_Levels::ladder( $power ) as $power_level ) {
			if ( (int) ( $power_level->level ?? 0 ) === $level ) {
				return $power_level;
			}
		}
		return null;
	}

	/**
	 * True when a numbered rank is a real, purchasable position in this power's ladder.
	 */
	private static function rank_is_valid_for_power( $definition, $power, int $level ): bool {
		$exact = self::find_power_level( $power, $level );
		if ( $exact !== null ) {
			return isset( $exact->cost );
		}
		$tier = self::tier_for_rank( $definition, $level );
		if ( $tier === null ) {
			return false;
		}
		foreach ( Power_Levels::ladder( $power ) as $power_level ) {
			if ( ( $power_level->tier ?? null ) === $tier ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Maps a numbered rank (1=basic, 2=intermediate,...) to its tier name, using `TIER_COSTS`' own key order (`innate`
	 * excluded - never a numbered rank).
	 */
	private static function tier_for_rank( $definition, int $rank ): ?string {
		return self::ladder_tiers( $definition )[ $rank - 1 ] ?? null;
	}

	/**
	 * The block's ladder expanded to one tier name per rung.
	 *
	 * @param object|array $definition
	 * @return string[]
	 */
	private static function ladder_tiers( $definition ): array {
		$ladder = null;
		if ( is_object( $definition ) && isset( $definition->_meta->ladder ) ) {
			$ladder = (array) $definition->_meta->ladder;
		} elseif ( is_array( $definition ) && isset( $definition['_meta']['ladder'] ) ) {
			$ladder = (array) $definition['_meta']['ladder'];
		}

		if ( $ladder === null ) {
			static $fallback = null;
			if ( $fallback === null ) {
				$fallback = array_values( array_diff( array_keys( self::TIER_COSTS ), [ 'innate' ] ) );
			}
			return $fallback;
		}

		$ranks = self::meta_ranks( $definition );
		$order = $ranks !== null
			? array_values( array_filter( $ranks, static fn( $r ): bool => isset( $ladder[ $r ] ) ) )
			: array_keys( $ladder );

		$tiers = [];
		foreach ( $order as $tier ) {
			for ( $i = 0; $i < (int) $ladder[ $tier ]; $i++ ) {
				$tiers[] = (string) $tier;
			}
		}
		return $tiers;
	}

	/**
	 * Finds a tiered_power level entry by its Elder-and-above `power_name`.
	 */
	private static function find_power_level_by_name( $power, string $power_name ) {
		return Trait_Alias_Resolver::find_level_by_name( Power_Levels::all( $power ), $power_name );
	}

	/**
	 * The block's declared rank vocabulary (`_meta.ranks`), in book order.
	 *
	 * @param object|array $definition
	 * @return string[]|null
	 */
	private static function meta_ranks( $definition ): ?array {
		if ( is_object( $definition ) && isset( $definition->_meta->ranks ) ) {
			return array_map( 'strval', (array) $definition->_meta->ranks );
		}
		if ( is_array( $definition ) && isset( $definition['_meta']['ranks'] ) ) {
			return array_map( 'strval', (array) $definition['_meta']['ranks'] );
		}
		return null;
	}

	/**
	 * The first rank above the declared ladder.
	 */
	private static function first_pick_rank( $definition ): ?string {
		$ranks = self::meta_ranks( $definition );
		if ( $ranks === null ) {
			return null;
		}
		// Walk `ranks` - book order.
		$ladder = self::meta_ladder( $definition );
		if ( $ladder === [] ) {
			return null;
		}
		$last = -1;
		foreach ( $ranks as $i => $rank ) {
			if ( array_key_exists( $rank, $ladder ) ) {
				$last = (int) $i;
			}
		}
		if ( $last < 0 ) {
			return null;
		}
		return $ranks[ $last + 1 ] ?? null;
	}

	/**
	 * Computes the cost of moving a sequential power between two levels as the sum of every step's base cost in between:
	 * raising level 2 to 4 costs the level-3 step plus the level-4 step.
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
	 * Looks up the base cost of one level of a tiered power.
	 */
	private static function level_base_cost( $definition, $power, int $level ): int {
		$tier = self::tier_for_rank( $definition, $level );

		if ( $tier !== null && self::declares_ladder( $definition ) ) {
			return self::block_tier_costs( $definition )[ $tier ] ?? 0;
		}

		$exact = self::find_power_level( $power, $level );
		if ( $exact !== null ) {
			return isset( $exact->cost ) ? self::price_item_cost( (string) $exact->cost, null ) : 0;
		}
		return $tier !== null ? self::block_tier_costs( $definition )[ $tier ] ?? 0 : 0;
	}

	/**
	 * Whether this block declares its own ladder (`_meta.ladder`).
	 *
	 * @param object|array $definition
	 */
	private static function declares_ladder( $definition ): bool {
		if ( is_object( $definition ) ) {
			return isset( $definition->_meta->ladder );
		}
		return is_array( $definition ) && isset( $definition['_meta']['ladder'] );
	}

	/**
	 * Derives a block's real per-tier cost ladder from its own seeded data.
	 */
	private static function block_tier_costs( $definition ): array {
		static $cache = null;
		if ( $cache !== null && $cache[0] === $definition ) {
			return $cache[1];
		}

		$meta_costs = self::meta_costs( $definition );
		if ( $meta_costs !== null ) {
			$costs = array_map( 'intval', $meta_costs );
			$cache = [ $definition, $costs ];
			return $costs;
		}

		$tallies = [];
		foreach ( ( $definition->powers ?? [] ) as $power ) {
			foreach ( Power_Levels::all( $power ) as $power_level ) {
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

	/**
	 * The block's declared ladder (`_meta.ladder`).
	 *
	 * @param object|array $definition
	 * @return array<string,int>
	 */
	private static function meta_ladder( $definition ): array {
		if ( is_object( $definition ) && isset( $definition->_meta->ladder ) ) {
			return (array) $definition->_meta->ladder;
		}
		if ( is_array( $definition ) && isset( $definition['_meta']['ladder'] ) ) {
			return (array) $definition['_meta']['ladder'];
		}
		return [];
	}

	/**
	 * The block's own declared per-rank costs (`_meta.costs`).
	 *
	 * @param object|array $definition
	 * @return array<string,int>|null
	 */
	private static function meta_costs( $definition ): ?array {
		if ( is_object( $definition ) && isset( $definition->_meta->costs ) ) {
			return (array) $definition->_meta->costs;
		}
		if ( is_array( $definition ) && isset( $definition['_meta']['costs'] ) ) {
			return (array) $definition['_meta']['costs'];
		}
		return null;
	}

	// Free-text cost parsing.

	/**
	 * Parses a GVM `cost` string into an explicit pricing rule.
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
	 * Resolves a unit cost from a `cost` string plus the player's chosen value for a variable-cost item.
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
	 */
	private static function find_item( $definition, string $name ) {
		return Trait_Alias_Resolver::find_item_by_name( (array) ( $definition->items ?? [] ), $name );
	}

	/**
	 * Finds a power by name within a tiered_power block definition.
	 */
	private static function find_power( $definition, string $name ) {
		return Trait_Alias_Resolver::find_power_by_name( (array) ( $definition->powers ?? [] ), $name );
	}

	/**
	 * Finds the held row a change actually names, and returns its count and chosen cost, or null when the character holds
	 * no such row.
	 *
	 * @param array       $sheet_data
	 * @param string      $block_slug
	 * @param object|null $definition The block definition the caller already resolved.
	 * @param array       $trait      The change's own trait payload.
	 * @param array|null  $previous   The change's `previous` snapshot, which names the row a relabel addresses.
	 * @return array{count: int, chosen_cost: int|null}|null
	 */
	private static function find_held_trait( array $sheet_data, string $block_slug, $definition, array $trait, ?array $previous = null ): ?array {
		$identity = Trait_Identity::target_of( $definition, $trait, $previous );
		if ( $identity === null ) {
			return null;
		}

		foreach ( ( $sheet_data[ $block_slug ] ?? [] ) as $item ) {
			if ( is_array( $item ) && Trait_Identity::of_row( $definition, $item ) === $identity ) {
				return [
					'count'       => (int) ( $item['count'] ?? 1 ),
					'chosen_cost' => $item['chosen_cost'] ?? null,
				];
			}
		}
		return null;
	}

	/**
	 * Finds a character's currently held instance of a power within a block.
	 *
	 * @return array{level: int, power_name: ?string, chosen_cost: ?int}|null
	 */
	private static function find_held_power( array $sheet_data, string $block_slug, string $name, ?string $power_name = null ): ?array {
		foreach ( ( $sheet_data[ $block_slug ] ?? [] ) as $item ) {
			if ( ( $item['name'] ?? null ) !== $name ) {
				continue;
			}
			$item_power_name = ( $item['power_name'] ?? '' ) !== '' ? $item['power_name'] : null;
			$chosen          = isset( $item['chosen_cost'] ) ? (int) $item['chosen_cost'] : null;
			if ( $power_name !== null ) {
				if ( $item_power_name === $power_name ) {
					return [ 'level' => (int) ( $item['level'] ?? 0 ), 'power_name' => $item_power_name, 'chosen_cost' => $chosen ];
				}
				continue;
			}
			if ( $item_power_name === null ) {
				return [ 'level' => (int) ( $item['level'] ?? 0 ), 'power_name' => null, 'chosen_cost' => $chosen ];
			}
		}
		return null;
	}

	/**
	 * The MET tier ladder costs: Basic 3, Intermediate 6, Advanced 9, Elder 12, Master 15, Ascended 18, Methuselah 21.
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
	 * Looks up the base cost of one Elder-and-above power pick by name within a tiered_power power's `levels` list.
	 */
	private static function elder_tier_cost( $power, string $power_name ): int {
		foreach ( Power_Levels::all( $power ) as $power_level ) {
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
