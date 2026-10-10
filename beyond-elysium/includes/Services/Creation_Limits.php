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
}
