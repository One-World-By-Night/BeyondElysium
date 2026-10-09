import { describe, expect, it } from 'vitest';
import { demoBannerMessage } from './demoBanner';

describe( 'demoBannerMessage', () => {
	it( 'is null when the status has not loaded yet', () => {
		expect( demoBannerMessage( null ) ).toBeNull();
	} );

	it( 'is null when the chronicle is not a demo', () => {
		expect(
			demoBannerMessage( {
				on: false,
				reset_hours: null,
				next_reset: null,
				last_reset: null,
			} )
		).toBeNull();
	} );

	it( 'names no time when the next reset is unknown', () => {
		const message = demoBannerMessage( {
			on: true,
			reset_hours: 6,
			next_reset: null,
			last_reset: null,
		} );
		expect( message ).toBe(
			'Demo chronicle: everything here resets on its own schedule.'
		);
	} );

	it( 'names the next reset time when known', () => {
		const message = demoBannerMessage( {
			on: true,
			reset_hours: 6,
			next_reset: '2026-10-03T15:00:00+00:00',
			last_reset: null,
		} );
		expect( message ).toContain(
			'Demo chronicle: everything here resets on its own schedule. Next reset about'
		);
	} );
} );
