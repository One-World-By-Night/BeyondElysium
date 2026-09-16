/**
 * 1.0.0-review F-080 (Pass H intake `t3-remaining-components`). The character, plot, and item
 * pickers - Connect, Allocate actions, a rumor's or subplot's parent - asked for one page of 100,
 * the route's largest, and showed that as the whole list. In a chronicle past 100, the rest
 * could not be picked, and nothing said so.
 */
import { everyPage } from './everyPage';

function pages( total: number, perPage = 100 ) {
	const calls: number[] = [];
	const fetchPage = async ( page: number ) => {
		calls.push( page );
		const start = ( page - 1 ) * perPage;
		const items = Array.from(
			{ length: Math.max( 0, Math.min( perPage, total - start ) ) },
			( _, i ) => start + i + 1
		);
		return { items, total };
	};
	return { calls, fetchPage };
}

describe( 'everyPage', () => {
	it( 'gathers every page of a long list', async () => {
		const { calls, fetchPage } = pages( 250 );
		const all = await everyPage( fetchPage );

		expect( all ).toHaveLength( 250 );
		expect( all[ 249 ] ).toBe( 250 );
		expect( calls ).toEqual( [ 1, 2, 3 ] );
	} );

	it( 'asks once for a list that fits on one page', async () => {
		const { calls, fetchPage } = pages( 40 );

		expect( await everyPage( fetchPage ) ).toHaveLength( 40 );
		expect( calls ).toEqual( [ 1 ] );
	} );

	it( 'stops at an empty page even if the total says there is more', async () => {
		const fetchPage = async ( page: number ) => ( {
			items: page === 1 ? [ 1, 2 ] : [],
			total: 500,
		} );

		expect( await everyPage( fetchPage ) ).toEqual( [ 1, 2 ] );
	} );
} );
