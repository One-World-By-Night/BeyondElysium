<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Creature_Stack;

defined( 'ABSPATH' ) || exit;

/**
 * How a character's creature type, as its chronicle has it, treats a pool's temporary points: the ordinary kind that
 * spend down from the rating and regain back up to it, none at all (`rating_only`), or a count that builds up past the
 * rating (`accumulates`).
 */
class Pool_Markers {

	public const ORDINARY    = 'ordinary';
	public const RATING_ONLY = 'rating_only';
	public const ACCUMULATES = 'accumulates';

	/**
	 * The kind of one pool definition.
	 *
	 * @param object $pool A pool of a resource_pool block's definition.
	 */
	public static function kind_of( object $pool ): string {
		if ( ! empty( $pool->rating_only ) ) {
			return self::RATING_ONLY;
		}
		return ! empty( $pool->accumulates ) ? self::ACCUMULATES : self::ORDINARY;
	}

	/**
	 * The most a build-up pool's temporary count reaches: its own `max`, or null when it has none.
	 *
	 * @param object $pool A pool of a resource_pool block's definition.
	 */
	public static function ceiling_of( object $pool ): ?int {
		return is_int( $pool->max ?? null ) ? $pool->max : null;
	}

	/**
	 * The pools of a character's sheet that are not ordinary, by block slug and pool name.
	 *
	 * @param object $character A character row.
	 * @return array<string,array<string,array{kind:string,max:int|null}>>
	 */
	public static function for_character( object $character ): array {
		$sheet    = is_array( $character->sheet_data ?? null ) ? $character->sheet_data : [];
		$resolved = Creature_Stack::resolve( (string) $character->stack_slug, (string) ( $character->owner_slug ?? '' ), array_map( 'strval', array_keys( $sheet ) ) );
		if ( $resolved === null ) {
			return [];
		}

		$marked = [];
		foreach ( $resolved['blocks'] as $block_slug => $block ) {
			if ( ( $block->section_type ?? '' ) !== 'resource_pool' ) {
				continue;
			}
			foreach ( (array) ( $block->definition->pools ?? [] ) as $pool ) {
				if ( ! is_object( $pool ) || ! isset( $pool->name ) ) {
					continue;
				}
				$kind = self::kind_of( $pool );
				if ( $kind !== self::ORDINARY ) {
					$marked[ (string) $block_slug ][ (string) $pool->name ] = [ 'kind' => $kind, 'max' => self::ceiling_of( $pool ) ];
				}
			}
		}
		return $marked;
	}

	/**
	 * One pool's kind and the ceiling of its temporary count, for a pool the character holds.
	 *
	 * @param object $character A character row.
	 * @return array{kind:string,max:int|null} The ordinary kind, with no ceiling, for a pool no marker names.
	 */
	public static function of( object $character, string $block_slug, string $pool_name ): array {
		return self::for_character( $character )[ $block_slug ][ $pool_name ] ?? [ 'kind' => self::ORDINARY, 'max' => null ];
	}
}
