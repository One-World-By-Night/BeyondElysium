/**
 * The sentences the Players tab shows after an invite or after linking characters.
 */
import { __, sprintf } from '@wordpress/i18n';
import type {
	CharacterLinkResult,
	NamedCharacter,
	PlayerInviteResult,
	SkippedCharacter,
} from '../types';

/**
 * Names joined for a sentence.
 */
function names( characters: NamedCharacter[] ): string {
	return characters.map( ( c ) => c.name ).join( ', ' );
}

/**
 * One sentence for each character an invite or a link left alone and a person can act on.
 */
export function skippedMessages( skipped: SkippedCharacter[] ): string[] {
	const lines: string[] = [];
	for ( const s of skipped ) {
		if ( s.reason === 'linked_elsewhere' ) {
			lines.push(
				sprintf(
					/* translators: 1: a character's name, 2: the player it is linked to */
					__(
						'%1$s is linked to %2$s, so it was left alone. Unlink it from them first.',
						'beyond-elysium'
					),
					s.name ?? '',
					s.linked_to || __( 'another player', 'beyond-elysium' )
				)
			);
		} else if ( s.reason === 'already_linked' ) {
			lines.push(
				sprintf(
					/* translators: %s: a character's name */
					__( '%s was already theirs.', 'beyond-elysium' ),
					s.name ?? ''
				)
			);
		} else if ( s.reason === 'not_saved' ) {
			lines.push(
				sprintf(
					/* translators: %s: a character's name */
					__( '%s could not be saved.', 'beyond-elysium' ),
					s.name ?? ''
				)
			);
		}
	}
	return lines;
}

/**
 * What an invite did, in the order it happened.
 */
export function inviteMessages(
	email: string,
	result: PlayerInviteResult
): string[] {
	const lines: string[] = [];
	if ( result.status === 'linked' ) {
		lines.push(
			result.player?.status === 'staff'
				? sprintf(
						/* translators: %s: the account's display name */
						__(
							'%s is on this chronicle’s staff; their characters are linked.',
							'beyond-elysium'
						),
						result.display_name || email
					)
				: sprintf(
						/* translators: %s: the account's display name */
						__( '%s is a player here now.', 'beyond-elysium' ),
						result.display_name || email
					)
		);
		if ( result.linked.length > 0 ) {
			lines.push(
				sprintf(
					/* translators: %s: comma-separated character names */
					__( 'Linked: %s.', 'beyond-elysium' ),
					names( result.linked )
				)
			);
		}
	} else {
		lines.push(
			result.email_sent
				? sprintf(
						/* translators: %s: the email address invited */
						__(
							'Invitation emailed to %s. They join the first time they sign in with that address.',
							'beyond-elysium'
						),
						email
					)
				: sprintf(
						/* translators: %s: the email address invited */
						__(
							'Invite saved for %s. They join the first time they sign in with that address.',
							'beyond-elysium'
						),
						email
					)
		);
		if ( ( result.held ?? [] ).length > 0 ) {
			lines.push(
				sprintf(
					/* translators: %s: comma-separated character names */
					__( 'Waiting for them: %s.', 'beyond-elysium' ),
					names( result.held ?? [] )
				)
			);
		}
	}
	return [ ...lines, ...skippedMessages( result.skipped ) ];
}

/**
 * What linking characters to a player did.
 */
export function linkMessages(
	player: string,
	result: CharacterLinkResult
): string[] {
	const lines: string[] =
		result.linked.length > 0
			? [
					sprintf(
						/* translators: 1: the player's display name, 2: comma-separated character names */
						__( 'Linked to %1$s: %2$s.', 'beyond-elysium' ),
						player,
						names( result.linked )
					),
				]
			: [];
	return [ ...lines, ...skippedMessages( result.skipped ) ];
}
