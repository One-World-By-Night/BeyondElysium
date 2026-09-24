<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a held or imported catalog reference to its CURRENT catalog location, against the declared catalog's own
 * recorded rename/move/split history.
 */
class Trait_Alias_Resolver {

	// -------------------------------------------------------------------------------
	// Same-block: tiered_power families.
	// -------------------------------------------------------------------------------

	/**
	 * Finds a family by its current name.
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
	 * A family/item answers to a name when the name is one of its own recorded `aliases`, or the name it was
	 * `split_from`.
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
	 * Finds a trait_list item by its current name.
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
	 * Finds a level entry - a rung or a pick, `Power_Levels::all()`'s own combined list.
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
	 * Every `{block, name}` pair a family or item's own `moved_from` carries, normalized: a bare object (one prior home)
	 * or a list of them.
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
			// A single {block,name} pair decoded as stdClass.
			$raw = [ $raw ];
		}

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
	 * Searches every already-loaded block for a `tiered_power` family whose `moved_from` names this exact
	 * `(from_block_slug, name)` pair.
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
	 * The trait_list counterpart of `find_moved_power()`.
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
