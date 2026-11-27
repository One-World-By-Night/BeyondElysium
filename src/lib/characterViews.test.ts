import {
	CHARACTER_VIEWS,
	isCharacterTab,
	isCharacterView,
	navKey,
	readLastView,
	rememberView,
	resolveView,
	viewsFor,
} from './characterViews';

describe( 'the Character section views', () => {
	it( 'lists the views in row order', () => {
		expect( [ ...CHARACTER_VIEWS ] ).toEqual( [
			'play',
			'sheet',
			'story',
			'history',
			'edit',
		] );
	} );

	it( 'gives Sheet and History to anyone and the rest to whoever may edit', () => {
		expect( viewsFor( true ) ).toEqual( [
			'play',
			'sheet',
			'story',
			'history',
			'edit',
		] );
		expect( viewsFor( false ) ).toEqual( [ 'sheet', 'history' ] );
	} );

	it( 'knows which tab keys open the section', () => {
		for ( const key of [ 'character', ...CHARACTER_VIEWS ] ) {
			expect( isCharacterTab( key ) ).toBe( true );
			expect( navKey( key ) ).toBe( 'character' );
		}
		expect( isCharacterView( 'character' ) ).toBe( false );
		expect( isCharacterTab( 'plots' ) ).toBe( false );
		expect( navKey( 'plots' ) ).toBe( 'plots' );
		expect( navKey( 'new' ) ).toBe( 'characters' );
	} );
} );

describe( 'which view opens', () => {
	const all = viewsFor( true );
	const readOnly = viewsFor( false );

	it( "opens the tab's own view when it is allowed", () => {
		expect( resolveView( 'edit', 'play', all ) ).toBe( 'edit' );
		expect( resolveView( 'sheet', 'play', all ) ).toBe( 'sheet' );
	} );

	it( 'opens the last view used for the section key', () => {
		expect( resolveView( 'character', 'play', all ) ).toBe( 'play' );
		expect( resolveView( 'character', 'story', all ) ).toBe( 'story' );
	} );

	it( 'opens Sheet the first time', () => {
		expect( resolveView( 'character', null, all ) ).toBe( 'sheet' );
		expect( resolveView( 'character', 'nonsense', all ) ).toBe( 'sheet' );
	} );

	it( 'never opens a view the viewer may not use', () => {
		expect( resolveView( 'edit', null, readOnly ) ).toBe( 'sheet' );
		expect( resolveView( 'character', 'play', readOnly ) ).toBe( 'sheet' );
		expect( resolveView( 'play', 'history', readOnly ) ).toBe( 'history' );
	} );
} );

describe( 'the view last used on a device', () => {
	function store( initial: Record< string, string > = {} ) {
		const data = { ...initial };
		return {
			data,
			getItem: ( key: string ) => data[ key ] ?? null,
			setItem: ( key: string, value: string ) => {
				data[ key ] = value;
			},
		};
	}

	it( 'remembers a view and reads it back', () => {
		const s = store();
		expect( readLastView( s ) ).toBeNull();
		rememberView( 'play', s );
		expect( readLastView( s ) ).toBe( 'play' );
		rememberView( 'story', s );
		expect( readLastView( s ) ).toBe( 'story' );
	} );

	it( 'ignores a stored value that is not a view', () => {
		expect(
			readLastView( store( { 'be-character-view': 'x' } ) )
		).toBeNull();
	} );

	it( 'forgets quietly when storage is blocked', () => {
		const blocked = {
			getItem: () => {
				throw new Error( 'blocked' );
			},
			setItem: () => {
				throw new Error( 'blocked' );
			},
		};
		expect( readLastView( blocked ) ).toBeNull();
		expect( () => rememberView( 'play', blocked ) ).not.toThrow();
		expect( readLastView( null ) ).toBeNull();
		expect( () => rememberView( 'play', null ) ).not.toThrow();
	} );
} );
