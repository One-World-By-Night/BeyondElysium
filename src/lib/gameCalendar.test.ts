import { formatGameDate, gameNightHeading } from './gameCalendar';

describe( 'formatGameDate', () => {
	it( 'writes a game date in the given locale, on the day it names', () => {
		expect( formatGameDate( '2026-10-02', 'en-US' ) ).toBe(
			'Friday, October 2, 2026'
		);
		expect( formatGameDate( '2026-10-02', 'pt-BR' ) ).toBe(
			'sexta-feira, 2 de outubro de 2026'
		);
	} );

	it( 'returns text that is not a date unchanged', () => {
		expect( formatGameDate( 'next Friday', 'en-US' ) ).toBe(
			'next Friday'
		);
		expect( formatGameDate( '', 'en-US' ) ).toBe( '' );
	} );
} );

describe( 'gameNightHeading', () => {
	it( 'puts the start time after the date', () => {
		expect(
			gameNightHeading( { date: '2026-10-02', time: '7pm' }, 'en-US' )
		).toBe( 'Friday, October 2, 2026 · 7pm' );
	} );

	it( 'is the date alone when there is no start time', () => {
		expect(
			gameNightHeading( { date: '2026-10-02', time: null }, 'en-US' )
		).toBe( 'Friday, October 2, 2026' );
		expect( gameNightHeading( { date: '2026-10-02' }, 'en-US' ) ).toBe(
			'Friday, October 2, 2026'
		);
	} );
} );
