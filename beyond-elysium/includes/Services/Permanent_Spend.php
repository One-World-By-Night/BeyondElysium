<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Creature_Stack;

defined( 'ABSPATH' ) || exit;

/**
 * Which pools a character's creature type spends permanently: the pools its books spend that way, each marked
 * `permanent_spend` in the catalog.
 */
class Permanent_Spend {

	/**
	 * Whether the character's creature type, as its chronicle has it, spends this pool permanently.
	 *
	 * @param object $character A character row.
	 */
	public static function marked( object $character, string $block_slug, string $pool_name ): bool {
		$resolved = Creature_Stack::resolve( (string) $character->stack_slug, (string) ( $character->owner_slug ?? '' ) );
		$block    = $resolved['blocks'][ $block_slug ] ?? null;
		if ( ! is_object( $block ) || ( $block->section_type ?? '' ) !== 'resource_pool' ) {
			return false;
		}
		foreach ( (array) ( $block->definition->pools ?? [] ) as $pool ) {
			if ( is_object( $pool ) && ( $pool->name ?? null ) === $pool_name ) {
				return ! empty( $pool->permanent_spend );
			}
		}
		return false;
	}
}
