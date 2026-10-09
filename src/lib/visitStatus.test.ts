import {
	alsoActiveAt,
	travellingBadgeLabel,
	visitSummary,
	visitingFromSummary,
} from './visitStatus';
import type { Visit, VisitingFrom } from '../types/transfer';

function visit( overrides: Partial< Visit > = {} ): Visit {
	return {
		host_chronicle: 'Boston',
		host_site: 'https://boston.example',
		keep_current: false,
		delivered_at: null,
		unreachable_since: null,
		...overrides,
	};
}

function visitingFrom( overrides: Partial< VisitingFrom > = {} ): VisitingFrom {
	return {
		home_chronicle: 'Kony',
		since: '2026-10-01 00:00:00',
		keep_current: false,
		delivered_at: null,
		unreachable_since: null,
		...overrides,
	};
}

describe( 'visitSummary', () => {
	it( 'names the chronicle alone when not kept current', () => {
		expect( visitSummary( visit() ) ).toBe( 'Boston' );
	} );

	it( 'names a kept-current visit with no delivery yet', () => {
		expect( visitSummary( visit( { keep_current: true } ) ) ).toBe(
			'Boston (kept current)'
		);
	} );

	it( 'names when a kept-current visit last delivered', () => {
		expect(
			visitSummary(
				visit( {
					keep_current: true,
					delivered_at: '2026-10-02 12:00:00',
				} )
			)
		).toBe( 'Boston (kept current, delivered 2026-10-02 12:00:00)' );
	} );

	it( 'flags an unreachable visit ahead of its kept-current state', () => {
		expect(
			visitSummary(
				visit( {
					keep_current: true,
					unreachable_since: '2026-10-01 00:00:00',
				} )
			)
		).toBe( "Boston (can't reach it)" );
	} );
} );

describe( 'alsoActiveAt', () => {
	it( 'joins every visit', () => {
		expect(
			alsoActiveAt( [
				visit(),
				visit( { host_chronicle: 'BBF', keep_current: true } ),
			] )
		).toBe( 'Also active at Boston, BBF (kept current)' );
	} );
} );

describe( 'visitingFromSummary', () => {
	it( 'reads as a plain visit when not kept current', () => {
		expect( visitingFromSummary( visitingFrom() ) ).toBe(
			'Visiting from Kony since 2026-10-01 00:00:00.'
		);
	} );

	it( 'names when a kept-current visit last delivered', () => {
		expect(
			visitingFromSummary(
				visitingFrom( {
					keep_current: true,
					delivered_at: '2026-10-02 12:00:00',
				} )
			)
		).toBe( 'Kept current from Kony, updated 2026-10-02 12:00:00.' );
	} );

	it( 'names a kept-current visit with no delivery yet', () => {
		expect(
			visitingFromSummary( visitingFrom( { keep_current: true } ) )
		).toBe( 'Kept current from Kony.' );
	} );

	it( 'flags an unreachable visit ahead of its kept-current state', () => {
		expect(
			visitingFromSummary(
				visitingFrom( {
					keep_current: true,
					unreachable_since: '2026-10-01 00:00:00',
				} )
			)
		).toBe(
			"Visiting from Kony since 2026-10-01 00:00:00. Can't reach Kony."
		);
	} );
} );

describe( 'travellingBadgeLabel', () => {
	it( 'reads as a plain "also active elsewhere" badge when every visit is reachable', () => {
		expect( travellingBadgeLabel( [ visit() ] ) ).toBe(
			'Also active elsewhere'
		);
	} );

	it( 'calls out an unreachable visit even when another visit is fine', () => {
		expect(
			travellingBadgeLabel( [
				visit(),
				visit( { unreachable_since: '2026-10-01 00:00:00' } ),
			] )
		).toBe( "Can't reach a visit" );
	} );
} );
