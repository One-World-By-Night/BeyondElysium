import {
	splitLinksByStatus,
	discordLinkCharacterNames,
} from './discordLinksSection';
import type { DiscordLink } from '../types';

function link( overrides: Partial< DiscordLink > ): DiscordLink {
	return {
		id: 1,
		discord_user_id: '1',
		discord_username: 'someone',
		status: 'approved',
		wp_user: null,
		characters: [],
		requested_at: '2026-01-01 00:00:00',
		confirmed_at: null,
		decided_at: null,
		revoked_at: null,
		...overrides,
	};
}

describe( 'splitLinksByStatus', () => {
	it( 'sorts confirmed links into waiting', () => {
		const links = [ link( { id: 1, status: 'confirmed' } ) ];
		expect( splitLinksByStatus( links ).waiting ).toHaveLength( 1 );
		expect( splitLinksByStatus( links ).approved ).toHaveLength( 0 );
	} );

	it( 'sorts approved links into approved', () => {
		const links = [ link( { id: 1, status: 'approved' } ) ];
		expect( splitLinksByStatus( links ).approved ).toHaveLength( 1 );
		expect( splitLinksByStatus( links ).waiting ).toHaveLength( 0 );
	} );

	it( 'leaves requested, declined, and revoked links out of both', () => {
		const links = [
			link( { id: 1, status: 'requested' } ),
			link( { id: 2, status: 'declined' } ),
			link( { id: 3, status: 'revoked' } ),
		];
		const { waiting, approved } = splitLinksByStatus( links );
		expect( waiting ).toHaveLength( 0 );
		expect( approved ).toHaveLength( 0 );
	} );
} );

describe( 'discordLinkCharacterNames', () => {
	it( 'joins several character names', () => {
		const withCharacters = link( {
			characters: [
				{ id: 1, name: 'Isolde Marchetti' },
				{ id: 2, name: 'Konstantin Drake' },
			],
		} );
		expect( discordLinkCharacterNames( withCharacters, 'none' ) ).toBe(
			'Isolde Marchetti, Konstantin Drake'
		);
	} );

	it( 'falls back to the given label with no characters', () => {
		expect( discordLinkCharacterNames( link( {} ), 'no characters' ) ).toBe(
			'no characters'
		);
	} );
} );
