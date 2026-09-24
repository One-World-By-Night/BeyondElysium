/**
 * `trackerMax` uses a pool's declared maximum, always leaves room to raise a
 * pool with no maximum, and still shows ten dots for a small pool with no
 * maximum.
 */
import { trackerMax } from './poolMax';

describe( 'trackerMax', () => {
	it( "uses a pool's declared maximum", () => {
		expect( trackerMax( 20, { permanent: 20, temporary: 20 } ) ).toBe( 20 );
	} );

	it( 'always leaves room to raise a pool with no maximum', () => {
		expect(
			trackerMax( undefined, { permanent: 10, temporary: 10 } )
		).toBe( 11 );
		expect( trackerMax( undefined, { permanent: 14, temporary: 3 } ) ).toBe(
			15
		);
	} );

	it( 'still shows ten dots for a small pool with no maximum', () => {
		expect( trackerMax( undefined, { permanent: 0, temporary: 0 } ) ).toBe(
			10
		);
	} );
} );
