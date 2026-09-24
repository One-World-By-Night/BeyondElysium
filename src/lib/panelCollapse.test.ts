import {
	readCollapsed,
	writeCollapsed,
	storedCollapsed,
	setCollapsed,
} from './panelCollapse';

beforeEach( () => {
	window.localStorage.clear();
} );

describe( 'readCollapsed', () => {
	it( 'reads nothing touched when the store is empty', () => {
		expect( readCollapsed() ).toEqual( {} );
	} );

	it( 'reads back what was written', () => {
		writeCollapsed( { a: true, b: false } );
		expect( readCollapsed() ).toEqual( { a: true, b: false } );
	} );

	it( 'ignores a malformed store rather than throwing', () => {
		window.localStorage.setItem( 'be-collapsed-panels', 'not json' );
		expect( readCollapsed() ).toEqual( {} );
	} );

	it( 'ignores the pre-1.2.9 array shape', () => {
		// A stored bare array of collapsed ids lands on the defaults.
		window.localStorage.setItem( 'be-collapsed-panels', '["a","b"]' );
		expect( readCollapsed() ).toEqual( {} );
	} );

	it( 'drops non-boolean values', () => {
		window.localStorage.setItem(
			'be-collapsed-panels',
			'{"a":true,"b":"yes","c":1}'
		);
		expect( readCollapsed() ).toEqual( { a: true } );
	} );
} );

describe( 'storedCollapsed', () => {
	it( 'separates "expanded" from "never touched"', () => {
		setCollapsed( 'touched', false );
		expect( storedCollapsed( 'touched' ) ).toBe( false );
		expect( storedCollapsed( 'untouched' ) ).toBeUndefined();
	} );
} );

describe( 'setCollapsed', () => {
	it( 'folds and unfolds one panel without disturbing the others', () => {
		setCollapsed( 'a', true );
		setCollapsed( 'b', true );
		setCollapsed( 'a', false );
		expect( readCollapsed() ).toEqual( { a: false, b: true } );
	} );
} );
