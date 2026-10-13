/**
 * The Players tab's Discord section: splitting links by what they wait for, and naming a link's characters.
 */
import type { DiscordLink } from '../types';

/**
 * Splits a chronicle's links into ones waiting for a Storyteller's decision and ones already linked.
 */
export function splitLinksByStatus( links: DiscordLink[] ): {
	waiting: DiscordLink[];
	approved: DiscordLink[];
} {
	return {
		waiting: links.filter( ( link ) => link.status === 'confirmed' ),
		approved: links.filter( ( link ) => link.status === 'approved' ),
	};
}

/**
 * A link's characters as one readable string, or a note that it carries none.
 */
export function discordLinkCharacterNames(
	link: DiscordLink,
	noCharactersLabel: string
): string {
	return link.characters.length > 0
		? link.characters.map( ( c ) => c.name ).join( ', ' )
		: noCharactersLabel;
}
