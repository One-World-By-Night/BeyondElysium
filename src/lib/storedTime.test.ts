import { formatStoredTime, siteLocale } from './storedTime';

const plain = ( text: string ) => text.replace( /\s/g, ' ' );

afterEach( () => {
	delete window.beyondElysium;
} );

describe( 'formatStoredTime', () => {
	it( 'writes a stored date and time in the given locale', () => {
		expect(
			plain(
				formatStoredTime( '2026-10-20 17:56:38', {
					time: true,
					locale: 'en-US',
				} )
			)
		).toBe( 'Oct 20, 2026, 5:56 PM' );
	} );

	it( 'writes a Portuguese date and time with a 24-hour clock', () => {
		const text = formatStoredTime( '2026-10-20 17:56:38', {
			time: true,
			locale: 'pt-BR',
		} );
		expect( text ).toContain( '17:56' );
		expect( text ).toContain( '2026' );
		expect( text ).not.toContain( 'PM' );
	} );

	it( 'writes a stored date alone', () => {
		expect( formatStoredTime( '2026-10-20', { locale: 'en-US' } ) ).toBe(
			'Oct 20, 2026'
		);
	} );

	it( 'leaves the time off unless it is asked for', () => {
		expect(
			formatStoredTime( '2026-10-20 17:56:38', { locale: 'en-US' } )
		).toBe( 'Oct 20, 2026' );
	} );

	it( 'keeps a late evening on the day it was stored', () => {
		expect(
			plain(
				formatStoredTime( '2026-10-20 23:30:00', {
					time: true,
					locale: 'en-US',
				} )
			)
		).toBe( 'Oct 20, 2026, 11:30 PM' );
		expect(
			formatStoredTime( '2026-10-20 23:30:00', { locale: 'en-US' } )
		).toBe( 'Oct 20, 2026' );
	} );

	it( 'reads a T between date and time', () => {
		expect(
			plain(
				formatStoredTime( '2026-10-20T17:56:00', {
					time: true,
					locale: 'en-US',
				} )
			)
		).toBe( 'Oct 20, 2026, 5:56 PM' );
	} );

	it( 'returns text that is not a stored time unchanged', () => {
		expect( formatStoredTime( 'next Friday', { locale: 'en-US' } ) ).toBe(
			'next Friday'
		);
		expect( formatStoredTime( '', { locale: 'en-US' } ) ).toBe( '' );
		expect( formatStoredTime( '2026-13-40', { locale: 'en-US' } ) ).toBe(
			'2026-13-40'
		);
	} );
} );

describe( 'siteLocale', () => {
	it( 'is the site language as a browser locale', () => {
		window.beyondElysium = { locale: 'pt_BR' } as never;
		expect( siteLocale() ).toBe( 'pt-BR' );
	} );

	it( 'is undefined when the page does not say', () => {
		expect( siteLocale() ).toBeUndefined();
	} );
} );
