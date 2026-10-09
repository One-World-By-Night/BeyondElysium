/**
 * `hasEmptyRecapField()` tells the Draft recap button whether there's still something to draft.
 * `mergeDraftedRecap()` fills in only the fields that are still empty, and never touches one that isn't.
 */
import { hasEmptyRecapField, mergeDraftedRecap } from './SessionRecapPanel';
import type { Recap } from './SessionRecapPanel';

function emptyRecap(): Recap {
	return {
		key_events: '',
		player_decisions: '',
		npcs_involved: [],
		cliffhanger: '',
		prep: '',
	};
}

function filledRecap(): Recap {
	return {
		key_events: 'The coterie met the Prince.',
		player_decisions: 'They agreed to the boon.',
		npcs_involved: [ { name: 'Prince Marcus', status: 'alive' } ],
		cliffhanger: 'Who sent the letter?',
		prep: "Draft the letter's contents.",
	};
}

describe( 'hasEmptyRecapField', () => {
	it( 'is true for a recap with nothing filled in yet', () => {
		expect( hasEmptyRecapField( emptyRecap() ) ).toBe( true );
	} );

	it( 'is true while only npcs_involved is still empty', () => {
		expect(
			hasEmptyRecapField( { ...filledRecap(), npcs_involved: [] } )
		).toBe( true );
	} );

	it( 'is false once every field has something in it', () => {
		expect( hasEmptyRecapField( filledRecap() ) ).toBe( false );
	} );
} );

describe( 'mergeDraftedRecap', () => {
	it( 'fills in every field from the draft when the recap starts empty', () => {
		expect( mergeDraftedRecap( emptyRecap(), filledRecap() ) ).toEqual(
			filledRecap()
		);
	} );

	it( 'never overwrites a field that already has something in it', () => {
		const current: Recap = {
			...emptyRecap(),
			key_events: 'Written by hand already.',
		};

		const merged = mergeDraftedRecap( current, filledRecap() );

		expect( merged.key_events ).toBe( 'Written by hand already.' );
	} );

	it( 'leaves npcs_involved alone once it already has a row', () => {
		const current: Recap = {
			...emptyRecap(),
			npcs_involved: [ { name: 'Already added', status: 'injured' } ],
		};

		const merged = mergeDraftedRecap( current, filledRecap() );

		expect( merged.npcs_involved ).toEqual( [
			{ name: 'Already added', status: 'injured' },
		] );
	} );

	it( 'leaves a field blank when the draft answered it with whitespace only', () => {
		const merged = mergeDraftedRecap( emptyRecap(), {
			...filledRecap(),
			cliffhanger: '   ',
		} );

		expect( merged.cliffhanger ).toBe( '' );
	} );
} );
