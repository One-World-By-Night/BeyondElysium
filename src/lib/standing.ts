/**
 * What a character's header says about its XP and waiting changes.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * The header line: XP free to spend, XP held for changes still waiting, and how many wait. A part that is zero is left
 * out. A character whose waiting changes need more than it has unspent reads 0 to spend and says by how much.
 */
export function standingLine(
	xpUnspent: number,
	xpHeld: number,
	waiting: number
): string {
	const parts: string[] = [];
	const free = xpUnspent - xpHeld;
	if ( free < 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: how many XP more than the character has unspent its waiting changes need */
				__(
					'XP to spend: 0 (the changes waiting need %d more than is unspent)',
					'beyond-elysium'
				),
				-free
			)
		);
	} else {
		if ( free > 0 ) {
			parts.push(
				sprintf(
					/* translators: %d: experience points the character can spend now */
					__( '%d XP to spend', 'beyond-elysium' ),
					free
				)
			);
		}
		if ( xpHeld > 0 ) {
			parts.push(
				sprintf(
					/* translators: %d: experience points held for changes waiting for a Storyteller */
					__( '%d XP held', 'beyond-elysium' ),
					xpHeld
				)
			);
		}
	}
	if ( waiting > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: how many of this character's changes wait for a Storyteller */
				_n(
					'%d change waiting',
					'%d changes waiting',
					waiting,
					'beyond-elysium'
				),
				waiting
			)
		);
	}
	return parts.join( ' · ' );
}
