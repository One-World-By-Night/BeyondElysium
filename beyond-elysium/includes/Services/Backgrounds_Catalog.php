<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * The single fork-aware lookup for a chronicle's merged `{stack}-backgrounds`
 * catalog. Replaces three independent copies of this same lookup
 * (Action_Allocator::catalog_sources(), Rumor_Generator::character_influences(),
 * Query_Engine::catalog_sources()/block_is_atomic()) that called
 * Schema_Block::find_by_slug() and were therefore blind to a chronicle's own
 * fork of the block - see BE_PROCESS/background-ledger-apr-design.md §3.1.
 *
 * @see BE_PROCESS/background-ledger-apr-design.md §5.2-5.3
 */
class Backgrounds_Catalog {

	/**
	 * Builds a name -> source map ('Influences', 'Backgrounds', 'Backgrounds,
	 * <Type>') for every item in one merged backgrounds block, resolved
	 * through this chronicle's own fork when one exists.
	 *
	 * @param string $block_slug
	 * @param string $game_slug
	 * @return array<string,string>
	 */
	public static function sources_for( string $block_slug, string $game_slug ): array {
		$block = Schema_Block::find_for_game( $block_slug, $game_slug );
		if ( ! $block || empty( $block->definition->items ) ) {
			return [];
		}

		$by_name = [];
		foreach ( $block->definition->items as $item ) {
			$by_name[ $item->name ] = $item->source ?? '';
		}
		return $by_name;
	}

	/**
	 * Returns the full catalog item list for one merged backgrounds block,
	 * resolved through this chronicle's own fork when one exists.
	 *
	 * @param string $block_slug
	 * @param string $game_slug
	 * @return array
	 */
	public static function items_for( string $block_slug, string $game_slug ): array {
		$block = Schema_Block::find_for_game( $block_slug, $game_slug );
		return $block->definition->items ?? [];
	}

	/**
	 * Returns every distinct background/influence name across every
	 * `{stack}-backgrounds` block in the catalog, fork-aware, for the
	 * background_actions picker and for validating a chronicle's chosen
	 * list against real catalog names. Each name carries which stacks offer
	 * it and whether it is Influence-sourced on any of them - an
	 * Influence-sourced item is already granted unconditionally and is never
	 * itself a valid background_actions entry.
	 *
	 * A stack with no backgrounds block at all (e.g. `bete`) simply
	 * contributes nothing; this is not an error condition.
	 *
	 * @param string $game_slug
	 * @return array[] {name, stacks: string[], is_influence: bool}
	 */
	public static function union_names( string $game_slug ): array {
		$by_name = [];

		foreach ( Schema_Block::all_for_game_by_types( [ 'trait_list' ], $game_slug ) as $block ) {
			if ( substr( $block->slug, -12 ) !== '-backgrounds' ) {
				continue;
			}
			$stack = substr( $block->slug, 0, -12 );

			foreach ( $block->definition->items ?? [] as $item ) {
				$name = $item->name ?? '';
				if ( $name === '' ) {
					continue;
				}
				if ( ! isset( $by_name[ $name ] ) ) {
					$by_name[ $name ] = [ 'name' => $name, 'stacks' => [], 'is_influence' => false ];
				}
				$by_name[ $name ]['stacks'][] = $stack;
				if ( ( $item->source ?? '' ) === 'Influences' ) {
					$by_name[ $name ]['is_influence'] = true;
				}
			}
		}

		$names = array_values( $by_name );
		usort( $names, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		return $names;
	}
}
