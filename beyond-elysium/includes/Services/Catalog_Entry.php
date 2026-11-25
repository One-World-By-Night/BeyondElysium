<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an entry to a chronicle's own copy of a catalog block: an item on a trait list, a family with its levels on a
 * power list with a ladder, or a pick under a family on a power list without one. The book's block is never changed.
 */
class Catalog_Entry {

	public const ADDED   = 'added';
	public const PRESENT = 'present';
	public const FAILED  = 'failed';

	public const ITEM   = 'item';
	public const FAMILY = 'family';
	public const PICK   = 'pick';

	/**
	 * What a block takes: an item (a priced trait list), a family (a power list with a ladder), a pick (a power list
	 * without one), or null for any other block, an unpriced list, a Blood Magic list or one spent from a pool.
	 *
	 * @param object|null $block
	 */
	public static function kind( ?object $block ): ?string {
		if ( null === $block ) {
			return null;
		}
		$definition = $block->definition ?? null;
		if ( ! is_object( $definition ) ) {
			return null;
		}
		if ( 'trait_list' === $block->section_type ) {
			return empty( $definition->unpriced ) ? self::ITEM : null;
		}
		if ( 'tiered_power' === $block->section_type ) {
			if ( isset( $definition->_meta->spent_from ) || ! empty( $definition->blood_magic ) ) {
				return null;
			}
			return Cost_Engine::meta_ladder( $definition ) !== [] ? self::FAMILY : self::PICK;
		}
		return null;
	}

	/**
	 * Whether the block's catalog already holds the entry: an item by name, a family by name, a pick by its name within
	 * its family.
	 *
	 * @param object $block
	 */
	public static function holds( object $block, string $kind, string $name, string $power_name = '' ): bool {
		$definition = $block->definition ?? null;
		if ( ! is_object( $definition ) ) {
			return false;
		}
		if ( self::ITEM === $kind ) {
			return self::item_in( $definition, $name );
		}
		$family = self::family_in( $definition, $name );
		if ( self::FAMILY === $kind || null === $family ) {
			return null !== $family;
		}
		foreach ( Power_Levels::all( $family ) as $level ) {
			if ( 0 === strcasecmp( (string) ( $level->power_name ?? '' ), $power_name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The groups a trait list files its items under, in catalog order.
	 *
	 * @param object $block
	 * @return string[]
	 */
	public static function groups( object $block ): array {
		$groups = [];
		foreach ( is_array( $block->definition->items ?? null ) ? $block->definition->items : [] as $item ) {
			$group = trim( (string) ( $item->group ?? '' ) );
			if ( '' !== $group && ! in_array( $group, $groups, true ) ) {
				$groups[] = $group;
			}
		}
		return $groups;
	}

	/**
	 * The longest group whose name an item's name starts with, followed by a colon ("Necromancy: Mortis" starts with
	 * Necromancy), without regard to case; null when none does.
	 *
	 * @param string[] $groups
	 */
	public static function suggest_group( string $name, array $groups ): ?string {
		$found = null;
		foreach ( $groups as $group ) {
			$length = strlen( $group ) + 1;
			if ( 0 === strncasecmp( $name, $group . ':', $length ) && ( null === $found || strlen( $group ) > strlen( $found ) ) ) {
				$found = $group;
			}
		}
		return $found;
	}

	/**
	 * How many levels a power list's ladder has.
	 */
	public static function rung_count( object $definition ): int {
		return count( self::rungs( $definition ) );
	}

	/**
	 * Carries out a Storyteller's tick on an approved homebrew add: the entry joins the chronicle's catalog, and the
	 * change's trait becomes the catalog entry it now is (no `custom`, no price of its own, a family's level without
	 * its power name, a pick without a level). Returns the change's data to put on the sheet, unchanged when the entry
	 * was already in the catalog or the tick no longer fits, or null when the catalog could not be written.
	 *
	 * @param string              $game_slug
	 * @param array<string,mixed> $change_data
	 * @param array<string,mixed> $tick        `group` for an item, `rank` for a pick.
	 * @return array<string,mixed>|null
	 */
	public static function add_from_change( string $game_slug, array $change_data, array $tick ): ?array {
		$slug  = (string) ( $change_data['block_slug'] ?? '' );
		$trait = is_array( $change_data['trait'] ?? null ) ? $change_data['trait'] : [];
		$name  = (string) ( $trait['name'] ?? '' );
		$block = '' !== $game_slug && '' !== $slug ? Schema_Block::find_for_game( $slug, $game_slug ) : null;
		$kind  = self::kind( $block );
		if ( null === $kind || '' === $name || empty( $trait['custom'] ) ) {
			return $change_data;
		}

		if ( self::ITEM === $kind ) {
			$outcome = self::add_item( $slug, $game_slug, $name, isset( $trait['chosen_cost'] ) ? (int) $trait['chosen_cost'] : null, (string) ( $tick['group'] ?? '' ) );
		} elseif ( self::FAMILY === $kind ) {
			$outcome = self::add_family( $slug, $game_slug, $name, (int) ( $trait['level'] ?? 0 ), (string) ( $trait['power_name'] ?? '' ) );
			unset( $trait['power_name'] );
		} else {
			$outcome = self::add_pick( $slug, $game_slug, $name, (string) ( $trait['power_name'] ?? '' ), (string) ( $tick['rank'] ?? '' ) );
			unset( $trait['level'] );
		}

		if ( self::FAILED === $outcome ) {
			return null;
		}
		if ( self::PRESENT === $outcome ) {
			return $change_data;
		}
		unset( $trait['custom'], $trait['chosen_cost'] );
		$change_data['trait'] = $trait;
		return $change_data;
	}

	/**
	 * Adds an item to a trait list, priced when `$cost` is given and filed under `$group` when one is named.
	 */
	public static function add_item( string $block_slug, string $game_slug, string $name, ?int $cost = null, string $group = '' ): string {
		$view = self::view( $block_slug, $game_slug, 'trait_list' );
		if ( null === $view ) {
			return self::FAILED;
		}
		if ( self::item_in( $view->definition, $name ) ) {
			return self::PRESENT;
		}

		$block = self::copy( $block_slug, $game_slug );
		if ( null === $block ) {
			return self::FAILED;
		}
		$definition = $block->definition;
		$items      = is_array( $definition->items ?? null ) ? $definition->items : [];
		$item       = [ 'name' => $name ];
		if ( null !== $cost ) {
			$item['cost'] = (string) $cost;
		}
		if ( '' !== $group ) {
			$item['group'] = $group;
		}
		$items[]           = (object) $item;
		$definition->items = $items;
		return self::save( $block_slug, $game_slug, $definition );
	}

	/**
	 * Adds a family to a power list with a ladder: one level for each rung, at the section's cost for that rank, with
	 * `$level` named `$power_name`. A family already there only has an unnamed `$level` named.
	 */
	public static function add_family( string $block_slug, string $game_slug, string $family, int $level, string $power_name = '' ): string {
		$view = self::view( $block_slug, $game_slug, 'tiered_power' );
		if ( null === $view || self::FAMILY !== self::kind( $view ) ) {
			return self::FAILED;
		}
		$rungs = self::rungs( $view->definition );
		if ( ! isset( $rungs[ $level ] ) ) {
			return self::FAILED;
		}

		$existing = self::family_in( $view->definition, $family );
		if ( null !== $existing && ( '' === $power_name || ! self::level_is_unnamed( $existing, $level ) ) ) {
			return self::PRESENT;
		}

		$block = self::copy( $block_slug, $game_slug );
		if ( null === $block ) {
			return self::FAILED;
		}
		$definition = $block->definition;
		$powers     = is_array( $definition->powers ?? null ) ? $definition->powers : [];
		$target     = self::family_in( $definition, $family );

		if ( null !== $target ) {
			foreach ( $target->levels as $row ) {
				if ( (int) ( $row->level ?? 0 ) === $level ) {
					$row->power_name = $power_name;
				}
			}
		} else {
			$costs  = Cost_Engine::block_tier_costs( $definition );
			$levels = [];
			foreach ( $rungs as $number => $tier ) {
				$levels[] = (object) [
					'cost'       => (string) ( $costs[ $tier ] ?? 0 ),
					'tier'       => $tier,
					'level'      => $number,
					'power_name' => $number === $level ? $power_name : '',
				];
			}
			$powers[] = (object) [
				'name'   => $family,
				'elder'  => [],
				'levels' => $levels,
			];
		}

		$definition->powers = $powers;
		return self::save( $block_slug, $game_slug, $definition );
	}

	/**
	 * Adds a family to a power list with a ladder at the first rung of `$tier` that is not yet named in that family, or
	 * is not yet there; fails when the ladder has no such open rung.
	 */
	public static function add_family_at_tier( string $block_slug, string $game_slug, string $family, string $tier, string $power_name = '' ): string {
		$view = self::view( $block_slug, $game_slug, 'tiered_power' );
		if ( null === $view || self::FAMILY !== self::kind( $view ) ) {
			return self::FAILED;
		}
		$existing = self::family_in( $view->definition, $family );
		foreach ( self::rungs( $view->definition ) as $number => $rung_tier ) {
			if ( $rung_tier === $tier && ( null === $existing || self::level_is_unnamed( $existing, $number ) ) ) {
				return self::add_family( $block_slug, $game_slug, $family, $number, $power_name );
			}
		}
		return self::FAILED;
	}

	/**
	 * Adds a pick to a power list without a ladder, under `$family` (created when it is new) at `$rank`, at the
	 * section's cost for that rank.
	 */
	public static function add_pick( string $block_slug, string $game_slug, string $family, string $power_name, string $rank ): string {
		$view = self::view( $block_slug, $game_slug, 'tiered_power' );
		if ( null === $view || self::PICK !== self::kind( $view ) ) {
			return self::FAILED;
		}
		$ranks = Cost_Engine::meta_ranks( $view->definition );
		if ( null !== $ranks && ! in_array( $rank, $ranks, true ) ) {
			return self::FAILED;
		}

		$existing = self::family_in( $view->definition, $family );
		if ( null !== $existing ) {
			foreach ( Power_Levels::all( $existing ) as $level ) {
				if ( 0 === strcasecmp( (string) ( $level->power_name ?? '' ), $power_name ) ) {
					return self::PRESENT;
				}
			}
		}

		$block = self::copy( $block_slug, $game_slug );
		if ( null === $block ) {
			return self::FAILED;
		}
		$definition = $block->definition;
		$powers     = is_array( $definition->powers ?? null ) ? $definition->powers : [];
		$target     = self::family_in( $definition, $family );
		if ( null === $target ) {
			$target   = (object) [
				'name'   => $family,
				'elder'  => [],
				'levels' => [],
			];
			$powers[] = $target;
		}

		$costs  = Cost_Engine::block_tier_costs( $definition );
		$elder  = (object) (array) ( $target->elder ?? [] );
		$picks  = is_array( $elder->$rank ?? null ) ? $elder->$rank : [];
		$picks[] = (object) [
			'cost'       => (string) ( $costs[ $rank ] ?? 0 ),
			'tier'       => $rank,
			'level'      => null,
			'source'     => $family,
			'power_name' => $power_name,
		];
		$elder->$rank  = $picks;
		$target->elder = $elder;

		$definition->powers = $powers;
		return self::save( $block_slug, $game_slug, $definition );
	}

	/**
	 * The block as the chronicle sees it (its own copy, else the book's), when it is of the given section type.
	 */
	private static function view( string $block_slug, string $game_slug, string $section_type ): ?object {
		if ( '' === $game_slug ) {
			return null;
		}
		$block = Schema_Block::find_for_game( $block_slug, $game_slug );
		return $block && $section_type === $block->section_type && is_object( $block->definition ?? null ) ? $block : null;
	}

	/**
	 * The chronicle's own copy of a block, made from the book's when it has none.
	 */
	private static function copy( string $block_slug, string $game_slug ): ?object {
		$block = Schema_Block::find_or_create_fork_for_game( $block_slug, $game_slug );
		return $block && is_object( $block->definition ?? null ) ? $block : null;
	}

	private static function save( string $block_slug, string $game_slug, object $definition ): string {
		return Schema_Block::update( $block_slug, [ 'definition' => $definition ], $game_slug ) ? self::ADDED : self::FAILED;
	}

	private static function item_in( object $definition, string $name ): bool {
		foreach ( is_array( $definition->items ?? null ) ? $definition->items : [] as $item ) {
			if ( 0 === strcasecmp( (string) ( $item->name ?? '' ), $name ) ) {
				return true;
			}
		}
		return false;
	}

	private static function family_in( object $definition, string $name ): ?object {
		foreach ( is_array( $definition->powers ?? null ) ? $definition->powers : [] as $power ) {
			if ( 0 === strcasecmp( (string) ( $power->name ?? '' ), $name ) ) {
				return $power;
			}
		}
		return null;
	}

	private static function level_is_unnamed( object $family, int $level ): bool {
		foreach ( is_array( $family->levels ?? null ) ? $family->levels : [] as $row ) {
			if ( (int) ( $row->level ?? 0 ) === $level ) {
				return '' === ( $row->power_name ?? '' );
			}
		}
		return false;
	}

	/**
	 * Each rung of the section's ladder, by level number, with its tier.
	 *
	 * @return array<int,string>
	 */
	private static function rungs( object $definition ): array {
		$rungs = [];
		for ( $number = 1; null !== ( $tier = Cost_Engine::tier_for_rank( $definition, $number ) ); $number++ ) {
			$rungs[ $number ] = $tier;
		}
		return $rungs;
	}
}
