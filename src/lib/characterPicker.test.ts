import { pickerRows } from './characterPicker';
import type { Character } from '../types/character';

function character( fields: Partial< Character > ): Character {
	return {
		id: 1,
		name: 'Nobody',
		wp_user_id: null,
		player_name: null,
		pending_player_email: null,
		is_npc: false,
		...fields,
	} as Character;
}

const roster = [
	character( {
		id: 5,
		name: 'Zeke Holt',
		wp_user_id: '9' as unknown as number,
		player_name: 'Pat Player',
	} ),
	character( {
		id: 3,
		name: 'Mara Quill',
		pending_player_email: 'mara@example.test',
	} ),
	character( { id: 4, name: 'Anton Drake' } ),
	character( { id: 2, name: 'Bea Frost' } ),
	character( { id: 7, name: 'Sire of Night', is_npc: true } ),
];

describe( 'pickerRows', () => {
	it( 'lists free characters first, then waiting, then linked, each by name', () => {
		expect( pickerRows( roster, '' ).map( ( r ) => r.name ) ).toEqual( [
			'Anton Drake',
			'Bea Frost',
			'Mara Quill',
			'Zeke Holt',
		] );
	} );

	it( 'says who holds a linked character and what a waiting one waits for', () => {
		const rows = pickerRows( roster, '' );

		expect( rows.find( ( r ) => r.id === 5 ) ).toMatchObject( {
			state: 'linked',
			note: 'Pat Player',
		} );
		expect( rows.find( ( r ) => r.id === 3 ) ).toMatchObject( {
			state: 'waiting',
			note: 'mara@example.test',
		} );
		expect( rows.find( ( r ) => r.id === 4 ) ).toMatchObject( {
			state: 'free',
			note: '',
		} );
	} );

	it( 'never offers an NPC', () => {
		expect( pickerRows( roster, '' ).some( ( r ) => r.id === 7 ) ).toBe(
			false
		);
	} );

	it( 'narrows by any part of the name, ignoring case', () => {
		expect( pickerRows( roster, 'RO' ).map( ( r ) => r.name ) ).toEqual( [
			'Bea Frost',
		] );
	} );

	it( "marks the rows already linked to the player being given characters as that player's own", () => {
		expect(
			pickerRows( roster, '', 9 ).find( ( r ) => r.id === 5 )?.state
		).toBe( 'own' );
	} );
} );
