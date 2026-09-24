<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Which purchase lists a chronicle has opened up.
 */
class Purchase_Scope {

	/**
	 * The switches an HST has, and the block families (slug suffixes) each one opens.
	 */
	public const AREAS = [
		'abilities'    => [ 'abilities' ],
		'backgrounds'  => [ 'backgrounds' ],
		'merits_flaws' => [ 'merits', 'flaws' ],
	];

	/** @var array<string,string[]> Family slugs by suffix. */
	private static array $families = [];

	/** @var array<string,array<string,object>> A family's blocks, keyed "game|suffix". */
	private static array $loaded = [];

	public static function reset_cache(): void {
		self::$families = [];
		self::$loaded   = [];
	}

	/**
	 * The areas this chronicle has switched on.
	 *
	 * @return string[]
	 */
	public static function open_areas( string $game_slug ): array {
		if ( $game_slug === '' ) {
			return [];
		}
		$game  = Game::find_by_slug( $game_slug );
		$scope = is_object( $game ) && is_object( $game->settings ?? null ) ? ( $game->settings->purchase_scope ?? null ) : null;
		return array_keys( array_filter( self::normalize( $scope ) ) );
	}

	/**
	 * A write's value as switches, or null when it is not a set of known ones.
	 *
	 * @param mixed $value
	 * @return array<string,bool>|null
	 */
	public static function sanitize( $value ): ?array {
		if ( $value instanceof \stdClass ) {
			$value = (array) $value;
		}
		if ( ! is_array( $value ) || $value === [] ) {
			return null;
		}
		$clean = [];
		foreach ( $value as $area => $on ) {
			if ( ! is_string( $area ) || ! isset( self::AREAS[ $area ] ) ) {
				return null;
			}
			$bool = is_scalar( $on ) ? filter_var( $on, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) : null;
			if ( $bool === null ) {
				return null;
			}
			$clean[ $area ] = $bool;
		}
		return $clean;
	}

	/**
	 * What is stored, as switches: known areas only, anything else ignored.
	 *
	 * @param mixed $stored
	 * @return array<string,bool>
	 */
	public static function normalize( $stored ): array {
		if ( $stored instanceof \stdClass ) {
			$stored = (array) $stored;
		}
		$switches = array_fill_keys( array_keys( self::AREAS ), false );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $area => $on ) {
				if ( isset( $switches[ $area ] ) && is_scalar( $on ) ) {
					$switches[ $area ] = filter_var( $on, FILTER_VALIDATE_BOOLEAN );
				}
			}
		}
		return $switches;
	}

	/**
	 * The family a block slug belongs to (`vampire-abilities` is `abilities`), or null.
	 */
	public static function suffix_of( string $block_slug ): ?string {
		foreach ( self::AREAS as $suffixes ) {
			foreach ( $suffixes as $suffix ) {
				if ( str_ends_with( $block_slug, '-' . $suffix ) ) {
					return $suffix;
				}
			}
		}
		return null;
	}

	/**
	 * The switch that opens a family.
	 */
	public static function area_of( string $suffix ): ?string {
		foreach ( self::AREAS as $area => $suffixes ) {
			if ( in_array( $suffix, $suffixes, true ) ) {
				return $area;
			}
		}
		return null;
	}

	/**
	 * Every live block of a family: the ones creature stacks actually list.
	 *
	 * @return string[]
	 */
	public static function family_slugs( string $suffix ): array {
		if ( ! isset( self::$families[ $suffix ] ) ) {
			$found = [];
			foreach ( Creature_Stack::all() as $stack ) {
				foreach ( (array) ( $stack->stack_definition->sections ?? [] ) as $section ) {
					$slug = (string) ( $section->block_slug ?? '' );
					if ( $slug !== '' && str_ends_with( $slug, '-' . $suffix ) ) {
						$found[ $slug ] = true;
					}
				}
			}
			$slugs = array_keys( $found );
			sort( $slugs );
			self::$families[ $suffix ] = $slugs;
		}
		return self::$families[ $suffix ];
	}

	/**
	 * A block as this chronicle buys from it: its own items first.
	 *
	 * @param object|null   $block     A `Schema_Block` row.
	 * @param string[]|null $open      The chronicle's open areas, when the caller already has them.
	 * @return object|null
	 */
	public static function widen( $block, string $game_slug, ?array $open = null ) {
		if ( ! is_object( $block ) || $game_slug === '' || ( $block->section_type ?? '' ) !== 'trait_list' || ! is_object( $block->definition ?? null ) ) {
			return $block;
		}
		$suffix = self::suffix_of( (string) ( $block->slug ?? '' ) );
		if ( $suffix === null ) {
			return $block;
		}
		$open = $open ?? self::open_areas( $game_slug );
		if ( ! in_array( self::area_of( $suffix ), $open, true ) ) {
			return $block;
		}

		$own    = (string) $block->slug;
		$items  = [];
		$seen   = [];
		foreach ( (array) ( $block->definition->items ?? [] ) as $item ) {
			$items[] = $item;
			if ( is_object( $item ) && isset( $item->name ) ) {
				$seen[ (string) $item->name ] = true;
			}
		}
		foreach ( self::family_blocks( $suffix, $game_slug ) as $slug => $other ) {
			if ( $slug === $own ) {
				continue;
			}
			foreach ( (array) ( $other->definition->items ?? [] ) as $item ) {
				$name = is_object( $item ) ? (string) ( $item->name ?? '' ) : '';
				if ( $name === '' || isset( $seen[ $name ] ) ) {
					continue;
				}
				$seen[ $name ] = true;
				$items[]       = $item;
			}
		}

		$widened             = clone $block;
		$widened->definition = clone $block->definition;
		$widened->definition->items = $items;
		return $widened;
	}

	/**
	 * `widen()` over a slug-keyed map of blocks, as `Creature_Stack::resolve()` returns them.
	 *
	 * @param array<string,object> $blocks
	 * @return array<string,object>
	 */
	public static function widen_blocks( array $blocks, string $game_slug ): array {
		$open = self::open_areas( $game_slug );
		if ( $open === [] ) {
			return $blocks;
		}
		foreach ( $blocks as $slug => $block ) {
			$widened = self::widen( $block, $game_slug, $open );
			if ( $widened !== null ) {
				$blocks[ $slug ] = $widened;
			}
		}
		return $blocks;
	}

	/**
	 * A family's blocks as this chronicle sees them (its own fork of one winning), in slug order.
	 *
	 * @return array<string,object>
	 */
	private static function family_blocks( string $suffix, string $game_slug ): array {
		$key = $game_slug . '|' . $suffix;
		if ( ! isset( self::$loaded[ $key ] ) ) {
			$loaded = Schema_Block::find_by_slugs_for_game( self::family_slugs( $suffix ), $game_slug );
			$keep   = [];
			foreach ( self::family_slugs( $suffix ) as $slug ) {
				if ( isset( $loaded[ $slug ] ) && ( $loaded[ $slug ]->section_type ?? '' ) === 'trait_list' ) {
					$keep[ $slug ] = $loaded[ $slug ];
				}
			}
			self::$loaded[ $key ] = $keep;
		}
		return self::$loaded[ $key ];
	}
}
