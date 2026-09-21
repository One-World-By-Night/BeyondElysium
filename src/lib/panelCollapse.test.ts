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
		// The first cut of this module stored a bare array of collapsed ids. A viewer
		// carrying one must land on defaults, not on a crash.
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
		// The distinction the character sheet depends on: an untouched section follows its
		// template's own `collapsed` flag, a touched one follows the viewer.
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
