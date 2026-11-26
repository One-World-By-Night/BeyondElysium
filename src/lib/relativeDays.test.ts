import { daysBefore, relativeDay } from './relativeDays';
import { formatStoredDay } from './storedTime';

describe( 'daysBefore', () => {
	it( 'counts whole days between dates and ignores the time of day', () => {
		expect( daysBefore( '2026-11-25 23:59:59', '2026-11-26' ) ).toBe( 1 );
		expect( daysBefore( '2026-11-26 00:00:01', '2026-11-26' ) ).toBe( 0 );
		expect( daysBefore( '2026-11-19', '2026-11-26' ) ).toBe( 7 );
	} );

	it( 'counts across a month and a year', () => {
		expect( daysBefore( '2026-10-30', '2026-11-02' ) ).toBe( 3 );
		expect( daysBefore( '2025-12-31', '2026-01-01' ) ).toBe( 1 );
	} );

	it( 'is null for text that is not a date', () => {
		expect( daysBefore( 'soon', '2026-11-26' ) ).toBeNull();
		expect( daysBefore( '2026-11-26', 'now' ) ).toBeNull();
	} );
} );

describe( 'relativeDay', () => {
	const today = '2026-11-26';

	it( 'says today and yesterday', () => {
		expect( relativeDay( '2026-11-26 08:00:00', today ) ).toBe( 'today' );
		expect( relativeDay( '2026-11-25 23:59:00', today ) ).toBe(
			'yesterday'
		);
	} );

	it( 'counts days up to six days ago', () => {
		expect( relativeDay( '2026-11-24', today ) ).toBe( '2 days ago' );
		expect( relativeDay( '2026-11-20', today ) ).toBe( '6 days ago' );
	} );

	it( 'gives the date itself from a week on', () => {
		expect( relativeDay( '2026-11-19', today ) ).toBe( 'Nov 19, 2026' );
	} );

	it( 'gives the date for one ahead of today and leaves other text as it is', () => {
		expect( relativeDay( '2026-11-30', today ) ).toBe( 'Nov 30, 2026' );
		expect( relativeDay( 'sometime', today ) ).toBe( 'sometime' );
	} );
} );

describe( 'formatStoredDay', () => {
	it( 'reads a date as weekday, day and month', () => {
		expect( formatStoredDay( '2026-11-28', { locale: 'en-US' } ) ).toBe(
			'Sat, Nov 28'
		);
	} );

	it( 'adds the time when asked and stored', () => {
		expect(
			formatStoredDay( '2026-11-27 23:59:00', {
				time: true,
				locale: 'en-US',
			} )
		).toBe( 'Fri, Nov 27, 23:59' );
		expect(
			formatStoredDay( '2026-11-27', { time: true, locale: 'en-US' } )
		).toBe( 'Fri, Nov 27' );
	} );

	it( 'returns text that is not a date unchanged', () => {
		expect( formatStoredDay( 'tomorrow' ) ).toBe( 'tomorrow' );
	} );
} );
