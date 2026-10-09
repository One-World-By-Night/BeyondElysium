import {
	loadedCreatureTypes,
	rememberCreatureTypes,
} from './useCreatureTypeNames';

describe( 'loadedCreatureTypes', () => {
	it( 'is empty until a chronicle’s types are remembered', () => {
		expect( loadedCreatureTypes( 'never-loaded' ) ).toEqual( [] );
	} );

	it( 'gives back what was remembered for that chronicle', () => {
		rememberCreatureTypes( 'first', [
			{ slug: 'vampire', name: 'Kindred' },
		] );
		expect( loadedCreatureTypes( 'first' ) ).toEqual( [
			{ slug: 'vampire', name: 'Kindred' },
		] );
	} );

	it( 'never gives one chronicle’s names to another', () => {
		rememberCreatureTypes( 'one', [
			{ slug: 'vampire', name: 'Kindred' },
		] );
		expect( loadedCreatureTypes( 'two' ) ).toEqual( [] );
		rememberCreatureTypes( 'two', [
			{ slug: 'vampire', name: 'Vampire' },
		] );
		expect( loadedCreatureTypes( 'one' )[ 0 ].name ).toBe( 'Kindred' );
		expect( loadedCreatureTypes( 'two' )[ 0 ].name ).toBe( 'Vampire' );
	} );

	it( 'hands back the same empty list every time', () => {
		expect( loadedCreatureTypes( 'nobody-a' ) ).toBe(
			loadedCreatureTypes( 'nobody-b' )
		);
	} );
} );
