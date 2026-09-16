/**
 * 1.0.0-review F-075 (Pass H intake). A bulk action's selection outlived the search it was made
 * on: check a Toreador, search for Brujah instead, check one of those, and "Award XP" went to
 * both - the Toreador no longer on screen. A selection now belongs to one search.
 */
import { searchKey } from './querySelection';

describe( 'searchKey', () => {
	const toreador = [
		{ field: 'clan', operator: 'equals', find: 'Toreador' },
	];
	const brujah = [ { field: 'clan', operator: 'equals', find: 'Brujah' } ];

	it( 'names the same search the same way on every page and in every order', () => {
		expect( searchKey( 'char', toreador, 'AND' ) ).toBe(
			searchKey( 'char', [ { ...toreador[ 0 ] } ], 'AND' )
		);
	} );

	it( 'tells an edited search apart', () => {
		expect( searchKey( 'char', toreador, 'AND' ) ).not.toBe(
			searchKey( 'char', brujah, 'AND' )
		);
		expect( searchKey( 'char', toreador, 'AND' ) ).not.toBe(
			searchKey( 'char', toreador, 'OR' )
		);
		expect( searchKey( 'char', toreador, 'AND' ) ).not.toBe(
			searchKey( 'char', [ ...toreador, ...brujah ], 'AND' )
		);
	} );

	it( 'tells another inventory apart', () => {
		expect( searchKey( 'char', toreador, 'AND' ) ).not.toBe(
			searchKey( 'item', toreador, 'AND' )
		);
	} );
} );
