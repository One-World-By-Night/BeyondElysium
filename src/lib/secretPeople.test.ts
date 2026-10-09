import { describe, expect, it } from 'vitest';
import { matchPerson, peopleFor, tellerFields } from './secretPeople';
import type { SecretPerson } from '../types/secret';

const people: SecretPerson[] = [
	{ id: 11, name: 'Radu Bathory', kind: 'npc' },
	{ id: 12, name: 'Konstantin Drake', kind: 'pc' },
	{ id: 13, name: 'Twin', kind: 'pc' },
	{ id: 14, name: 'twin', kind: 'npc' },
];

describe( 'matchPerson', () => {
	it( 'finds a person by their exact name, ignoring case and extra spaces', () => {
		expect( matchPerson( 'radu bathory', people )?.id ).toBe( 11 );
		expect( matchPerson( '  Konstantin   Drake ', people )?.id ).toBe( 12 );
	} );

	it( 'finds nobody for a partial or unknown name', () => {
		expect( matchPerson( 'Radu', people ) ).toBeNull();
		expect( matchPerson( 'Somebody Else', people ) ).toBeNull();
		expect( matchPerson( '', people ) ).toBeNull();
	} );

	it( 'does not guess between two people with the same name', () => {
		expect( matchPerson( 'Twin', people ) ).toBeNull();
	} );
} );

describe( 'tellerFields', () => {
	it( 'sends nothing when no one is chosen or typed', () => {
		expect( tellerFields( '', '', people ) ).toEqual( {} );
		expect( tellerFields( '', '   ', people ) ).toEqual( {} );
	} );

	it( 'sends the chosen person as a character', () => {
		expect( tellerFields( 12, '', people ) ).toEqual( {
			teller_character_id: 12,
		} );
	} );

	it( 'prefers a chosen person over typed text', () => {
		expect( tellerFields( 12, 'Radu Bathory', people ) ).toEqual( {
			teller_character_id: 12,
		} );
	} );

	it( 'sends a typed name that matches a listed person as that character', () => {
		expect( tellerFields( '', 'RADU BATHORY', people ) ).toEqual( {
			teller_character_id: 11,
		} );
	} );

	it( 'sends any other typed name as plain text, tidied', () => {
		expect(
			tellerFields( '', '  A stranger   at the bar ', people )
		).toEqual( {
			teller_name: 'A stranger at the bar',
		} );
	} );

	it( 'sends an ambiguous typed name as plain text rather than guessing', () => {
		expect( tellerFields( '', 'Twin', people ) ).toEqual( {
			teller_name: 'Twin',
		} );
	} );

	it( 'ignores a chosen id that is not in the list', () => {
		expect( tellerFields( 99, 'A stranger', people ) ).toEqual( {
			teller_name: 'A stranger',
		} );
	} );
} );

describe( 'peopleFor', () => {
	it( 'leaves out the character that is doing the learning', () => {
		expect( peopleFor( people, 12 ).map( ( p ) => p.id ) ).toEqual( [
			11, 13, 14,
		] );
	} );

	it( 'takes the learner id as text or as a number', () => {
		expect( peopleFor( people, '12' ).map( ( p ) => p.id ) ).toEqual( [
			11, 13, 14,
		] );
		expect( peopleFor( people, 12 ) ).toEqual( peopleFor( people, '12' ) );
	} );

	it( 'keeps everyone when no character is chosen yet', () => {
		expect( peopleFor( people, null ) ).toEqual( people );
		expect( peopleFor( people, '' ) ).toEqual( people );
	} );
} );
