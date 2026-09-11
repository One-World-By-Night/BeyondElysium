import { clearDraft, draftDiffersFrom, loadDraft, saveDraft } from './draftStorage';

describe( 'draftStorage', () => {
	beforeEach( () => {
		window.localStorage.clear();
	} );

	it( 'round-trips a saved draft back out for the same character', () => {
		saveDraft( 42, { disciplines: [ { name: 'Celerity', count: 1 } ] } );

		const loaded = loadDraft( 42 );

		expect( loaded ).not.toBeNull();
		expect( loaded?.sheetData ).toEqual( { disciplines: [ { name: 'Celerity', count: 1 } ] } );
		expect( typeof loaded?.savedAt ).toBe( 'number' );
	} );

	it( 'keeps different characters\' drafts separate', () => {
		saveDraft( 1, { virtues: [ { name: 'Conscience', count: 3 } ] } );
		saveDraft( 2, { virtues: [ { name: 'Conviction', count: 4 } ] } );

		expect( loadDraft( 1 )?.sheetData ).toEqual( { virtues: [ { name: 'Conscience', count: 3 } ] } );
		expect( loadDraft( 2 )?.sheetData ).toEqual( { virtues: [ { name: 'Conviction', count: 4 } ] } );
	} );

	it( 'returns null when nothing was ever saved for that character', () => {
		expect( loadDraft( 999 ) ).toBeNull();
	} );

	it( 'returns null after the draft is cleared', () => {
		saveDraft( 7, { disciplines: [] } );
		clearDraft( 7 );

		expect( loadDraft( 7 ) ).toBeNull();
	} );

	it( 'returns null for corrupt stored JSON rather than throwing', () => {
		window.localStorage.setItem( 'be-draft-5', '{not valid json' );

		expect( loadDraft( 5 ) ).toBeNull();
	} );

	it( 'returns null for a stored value missing the expected shape', () => {
		window.localStorage.setItem( 'be-draft-6', JSON.stringify( { somethingElse: true } ) );

		expect( loadDraft( 6 ) ).toBeNull();
	} );
} );

describe( 'draftDiffersFrom', () => {
	it( 'is false for equivalent data regardless of key order', () => {
		const a = { disciplines: [ { name: 'Celerity', count: 1 } ], virtues: [] };
		const b = { virtues: [], disciplines: [ { name: 'Celerity', count: 1 } ] };

		expect( draftDiffersFrom( a, b ) ).toBe( false );
	} );

	it( 'is true when the actual values differ', () => {
		const a = { disciplines: [ { name: 'Celerity', count: 1 } ] };
		const b = { disciplines: [ { name: 'Celerity', count: 2 } ] };

		expect( draftDiffersFrom( a, b ) ).toBe( true );
	} );
} );
