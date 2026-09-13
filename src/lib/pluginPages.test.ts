import { characterEditorUrl, characterSheetUrl, isPrintCanvasPath, pluginPageUrl } from './pluginPages';

const ORIGIN = 'http://localhost:8910';

beforeEach( () => {
	Object.defineProperty( window, 'location', {
		value: { origin: ORIGIN },
		writable: true,
	} );
} );

describe( 'pluginPageUrl', () => {
	it( 'builds an absolute URL from a slug', () => {
		expect( pluginPageUrl( 'character-sheet' ) ).toBe( `${ ORIGIN }/character-sheet/` );
	} );
} );

describe( 'characterSheetUrl', () => {
	it( 'includes the character id and URL-encoded game slug', () => {
		expect( characterSheetUrl( 42, 'kony' ) ).toBe( `${ ORIGIN }/character-sheet/?character_id=42&game_slug=kony` );
	} );

	it( 'URL-encodes a game slug with special characters', () => {
		expect( characterSheetUrl( 1, 'a b' ) ).toBe( `${ ORIGIN }/character-sheet/?character_id=1&game_slug=a%20b` );
	} );
} );

describe( 'characterEditorUrl', () => {
	it( 'includes the character id and URL-encoded game slug', () => {
		expect( characterEditorUrl( 42, 'kony' ) ).toBe( `${ ORIGIN }/character-editor/?character_id=42&game_slug=kony` );
	} );
} );

describe( 'isPrintCanvasPath', () => {
	it( 'matches the print-canvas path', () => {
		expect( isPrintCanvasPath( '/character-sheet-print/' ) ).toBe( true );
	} );

	it( 'does not match the ordinary sheet path', () => {
		expect( isPrintCanvasPath( '/character-sheet/' ) ).toBe( false );
	} );

	it( 'matches when the print path is a prefix of a longer pathname', () => {
		expect( isPrintCanvasPath( '/character-sheet-print/index.html' ) ).toBe( true );
	} );
} );
