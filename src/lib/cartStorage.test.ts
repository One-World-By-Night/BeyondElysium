import {
	cartBlocks,
	clearCart,
	loadCart,
	restoreCart,
	saveCart,
} from './cartStorage';

const server = {
	'vampire-abilities': [ { name: 'Occult', count: 3 } ],
	'vampire-backgrounds': [ { name: 'Resources', count: 2 } ],
};

describe( 'cartBlocks', () => {
	it( 'names only the blocks an edit changes, with the value each was made from', () => {
		const edited = {
			...server,
			'vampire-abilities': [ { name: 'Occult', count: 4 } ],
		};
		expect( cartBlocks( server, edited ) ).toEqual( {
			'vampire-abilities': {
				base: [ { name: 'Occult', count: 3 } ],
				edited: [ { name: 'Occult', count: 4 } ],
			},
		} );
	} );

	it( 'reads a key order that differs as the same value', () => {
		const edited = {
			...server,
			'vampire-abilities': [ { count: 3, name: 'Occult' } ],
		};
		expect( cartBlocks( server, edited ) ).toEqual( {} );
	} );

	it( 'takes a block the sheet held none of as a null base', () => {
		const edited = { ...server, 'vampire-merits': [ { name: 'Lucky' } ] };
		expect( cartBlocks( server, edited )[ 'vampire-merits' ] ).toEqual( {
			base: null,
			edited: [ { name: 'Lucky' } ],
		} );
	} );
} );

describe( 'saveCart and loadCart', () => {
	beforeEach( () => window.localStorage.clear() );

	it( 'keeps a cart per character and reads it back', () => {
		const edited = {
			...server,
			'vampire-abilities': [ { name: 'Occult', count: 4 } ],
		};
		saveCart( 7, server, edited );
		const cart = loadCart( 7 );
		expect( Object.keys( cart?.blocks ?? {} ) ).toEqual( [
			'vampire-abilities',
		] );
		expect( loadCart( 8 ) ).toBeNull();
	} );

	it( 'removes the cart when the edit changes nothing', () => {
		const edited = {
			...server,
			'vampire-abilities': [ { name: 'Occult', count: 4 } ],
		};
		saveCart( 7, server, edited );
		saveCart( 7, server, server );
		expect( loadCart( 7 ) ).toBeNull();
	} );

	it( 'treats an unreadable cart as none', () => {
		window.localStorage.setItem( 'be-cart-7', '{not json' );
		expect( loadCart( 7 ) ).toBeNull();
		window.localStorage.setItem( 'be-cart-7', '{"blocks":[],"savedAt":1}' );
		expect( loadCart( 7 ) ).toBeNull();
		window.localStorage.setItem(
			'be-cart-7',
			'{"blocks":{"a":1},"savedAt":1}'
		);
		expect( loadCart( 7 ) ).toBeNull();
	} );

	it( 'clears a cart', () => {
		saveCart( 7, server, {
			...server,
			'vampire-abilities': [ { name: 'Occult', count: 4 } ],
		} );
		clearCart( 7 );
		expect( loadCart( 7 ) ).toBeNull();
	} );
} );

describe( 'restoreCart', () => {
	const cart = {
		savedAt: 1,
		blocks: {
			'vampire-abilities': {
				base: [ { name: 'Occult', count: 3 } ],
				edited: [ { name: 'Occult', count: 4 } ],
			},
			'vampire-backgrounds': {
				base: [ { name: 'Resources', count: 2 } ],
				edited: [ { name: 'Resources', count: 3 } ],
			},
		},
	};

	it( 'brings back a block the server still holds as it was edited from', () => {
		const result = restoreCart( cart, server );
		expect( result.restored ).toEqual( [
			'vampire-abilities',
			'vampire-backgrounds',
		] );
		expect( result.dropped ).toEqual( [] );
		expect( result.sheet[ 'vampire-abilities' ] ).toEqual( [
			{ name: 'Occult', count: 4 },
		] );
	} );

	it( 'drops and names a block a Storyteller changed meanwhile, keeping the server value', () => {
		const changed = {
			...server,
			'vampire-abilities': [ { name: 'Occult', count: 5 } ],
		};
		const result = restoreCart( cart, changed );
		expect( result.dropped ).toEqual( [ 'vampire-abilities' ] );
		expect( result.restored ).toEqual( [ 'vampire-backgrounds' ] );
		expect( result.sheet[ 'vampire-abilities' ] ).toEqual( [
			{ name: 'Occult', count: 5 },
		] );
		expect( result.sheet[ 'vampire-backgrounds' ] ).toEqual( [
			{ name: 'Resources', count: 3 },
		] );
	} );

	it( 'does not touch the server sheet it was given', () => {
		const before = JSON.stringify( server );
		restoreCart( cart, server );
		expect( JSON.stringify( server ) ).toBe( before );
	} );
} );
