import { standingLine } from './standing';

describe( 'standingLine', () => {
	it( 'says what is free to spend, what is held and how many wait', () => {
		expect( standingLine( 16, 6, 3 ) ).toBe(
			'10 XP to spend · 6 XP held · 3 changes waiting'
		);
	} );

	it( 'leaves out whatever is zero', () => {
		expect( standingLine( 10, 0, 0 ) ).toBe( '10 XP to spend' );
		expect( standingLine( 0, 0, 1 ) ).toBe( '1 change waiting' );
		expect( standingLine( 0, 0, 0 ) ).toBe( '' );
	} );

	it( 'reads zero to spend when everything unspent is held', () => {
		expect( standingLine( 6, 6, 2 ) ).toBe(
			'6 XP held · 2 changes waiting'
		);
	} );

	it( 'says by how much the waiting changes outrun the unspent XP', () => {
		expect( standingLine( 4, 6, 2 ) ).toBe(
			'XP to spend: 0 (the changes waiting need 2 more than is unspent) · 2 changes waiting'
		);
	} );
} );
