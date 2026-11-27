<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * What a player's new character may not start with, read from the creation tally: ratings that only play can raise.
 */
class Creation_Limits {

	/**
	 * The reason a new character's build is refused, or null when nothing in it is out of reach for a player.
	 *
	 * @param array<string,mixed> $tally `Creation_Tally::for_stack()`.
	 */
	public static function refusal( array $tally ): ?string {
		$messages = [];
		foreach ( (array) ( $tally['unbuyable'] ?? [] ) as $item ) {
			if ( ( $item['kind'] ?? '' ) === 'raised_by' ) {
				$messages[] = sprintf(
					/* translators: 1: a pool's name (Mercy), 2: how many dots are past the free ones */
					_n(
						'%1$s is set %2$d dot past the free dots a new character gets. More are raised in play.',
						'%1$s is set %2$d dots past the free dots a new character gets. More are raised in play.',
						(int) $item['dots'],
						'beyond-elysium'
					),
					(string) $item['pool'],
					(int) $item['dots']
				);
			} elseif ( ( $item['kind'] ?? '' ) === 'book_max' ) {
				$messages[] = sprintf(
					/* translators: 1: a pool's name (Balance), 2: the rating it is set to, 3: the most the book allows */
					__( '%1$s is set to %2$d, above the %3$d the book allows. A Storyteller sets it higher.', 'beyond-elysium' ),
					(string) $item['pool'],
					(int) $item['value'],
					(int) $item['max']
				);
			}
		}
		return $messages === [] ? null : implode( ' ', $messages );
	}

	/**
	 * The reason a build is refused for costing more than a new character starts with, or null when it fits. A
	 * Storyteller entering a sheet that already exists ticks Existing character, which skips the tally altogether.
	 *
	 * @param array<string,mixed> $tally      `Creation_Tally::for_stack()`.
	 * @param bool                $is_manager Whether the caller is a Storyteller, who is told how to enter an existing sheet.
	 */
	public static function over_budget( array $tally, bool $is_manager ): ?string {
		$needed   = (int) ( $tally['xp']['needed'] ?? 0 );
		$starting = (int) ( $tally['xp']['starting'] ?? 0 );
		if ( $needed <= $starting ) {
			return null;
		}
		if ( $is_manager ) {
			return sprintf(
				/* translators: 1: the experience the build needs, 2: the experience a new character starts with */
				__( 'This build needs %1$d XP and a new character here starts with %2$d. Tick Existing character for a sheet that already exists.', 'beyond-elysium' ),
				$needed,
				$starting
			);
		}
		return sprintf(
			/* translators: 1: the experience the build needs, 2: the experience a new character starts with */
			__( 'This build needs %1$d XP and a new character here starts with %2$d.', 'beyond-elysium' ),
			$needed,
			$starting
		);
	}
}
