import {
	readDiscordLinkParams,
	clearedDiscordLinkUrl,
} from './discordLinkParams';

describe( 'readDiscordLinkParams', () => {
	it( 'reads a token and slug together', () => {
		expect(
			readDiscordLinkParams( '?discord_link=abc123&discord_slug=kony' )
		).toEqual( { token: 'abc123', slug: 'kony' } );
	} );

	it( 'reads them alongside an auth=sso parameter', () => {
		expect(
			readDiscordLinkParams(
				'?discord_link=abc123&discord_slug=kony&auth=sso'
			)
		).toEqual( { token: 'abc123', slug: 'kony' } );
	} );

	it( 'is null with neither parameter present', () => {
		expect( readDiscordLinkParams( '' ) ).toBeNull();
	} );

	it( 'is null with only the token', () => {
		expect( readDiscordLinkParams( '?discord_link=abc123' ) ).toBeNull();
	} );

	it( 'is null with only the slug', () => {
		expect( readDiscordLinkParams( '?discord_slug=kony' ) ).toBeNull();
	} );

	it( 'is null for an unrelated query string', () => {
		expect( readDiscordLinkParams( '?join=1&join_slug=kony' ) ).toBeNull();
	} );
} );

describe( 'clearedDiscordLinkUrl', () => {
	it( 'removes the token, the slug, and auth', () => {
		const cleared = clearedDiscordLinkUrl(
			'https://example.test/my-chronicle/?discord_link=abc&discord_slug=kony&auth=sso'
		);
		expect( cleared ).toBe( 'https://example.test/my-chronicle/' );
	} );

	it( 'leaves an unrelated parameter alone', () => {
		const cleared = clearedDiscordLinkUrl(
			'https://example.test/my-chronicle/?tab=sheet&discord_link=abc&discord_slug=kony'
		);
		expect( cleared ).toBe(
			'https://example.test/my-chronicle/?tab=sheet'
		);
	} );

	it( 'is a no-op with nothing to clear', () => {
		const cleared = clearedDiscordLinkUrl(
			'https://example.test/my-chronicle/?tab=sheet'
		);
		expect( cleared ).toBe(
			'https://example.test/my-chronicle/?tab=sheet'
		);
	} );
} );
