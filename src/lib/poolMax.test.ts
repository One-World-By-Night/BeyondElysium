/**
 * 1.0.0-review F-079 (Pass H intake `t3-remaining-components`). A resource pool can be defined
 * with no maximum. Its tracker's ceiling was the larger of the pool's value and 10 - so once the
 * pool reached 10, the ceiling was 10, "+" was disabled, and nothing on the sheet could raise it.
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
