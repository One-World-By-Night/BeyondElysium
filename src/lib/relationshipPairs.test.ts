import { describe, expect, it } from 'vitest';
import {
	edgeSummary,
	groupEdges,
	mergeCharacters,
	normalizeConnections,
	pairKey,
	splitStMarkers,
	wrapLabel,
} from './relationshipPairs';
import type { Character } from '../types/character';
import type { Connection } from '../types/plot';

function connection( overrides: Partial< Connection > = {} ): Connection {
	return {
		id: 1,
		game_id: 1,
		source_type: 'character',
		source_id: 1,
		target_type: 'character',
		target_id: 2,
		label: null,
		notes: null,
		created_by: 1,
		created_at: '2026-10-01 00:00:00',
		...overrides,
	};
}

describe( 'normalizeConnections', () => {
	it( 'turns string ids into numbers and leaves a missing target null', () => {
		const rows = normalizeConnections( [
			{
				...connection(),
				id: '7' as unknown as number,
				game_id: '3' as unknown as number,
				source_id: '1966' as unknown as number,
				target_id: '1991' as unknown as number,
				created_by: '10' as unknown as number,
			},
			connection( { id: 8, target_type: 'tag', target_id: null } ),
		] );

		expect( rows[ 0 ] ).toMatchObject( {
			id: 7,
			game_id: 3,
			source_id: 1966,
			target_id: 1991,
			created_by: 10,
		} );
		expect( rows[ 1 ].target_id ).toBeNull();
	} );
} );

describe( 'groupEdges', () => {
	const everyone = () => true;

	it( 'puts connections in either direction between two characters into one group', () => {
		const groups = groupEdges(
			[
				connection( { id: 1, source_id: 1, target_id: 2 } ),
				connection( { id: 2, source_id: 2, target_id: 1 } ),
				connection( { id: 3, source_id: 1, target_id: 3 } ),
			],
			everyone
		);

		expect( groups.map( ( g ) => g.key ) ).toEqual( [ '1-2', '1-3' ] );
		expect( groups[ 0 ].connections.map( ( c ) => c.id ) ).toEqual( [
			1, 2,
		] );
		expect( groups[ 0 ] ).toMatchObject( { a: 1, b: 2 } );
	} );

	it( 'leaves out a connection with a hidden end or no target', () => {
		const groups = groupEdges(
			[
				connection( { id: 1, source_id: 1, target_id: 2 } ),
				connection( { id: 2, source_id: 1, target_id: 9 } ),
				connection( { id: 3, target_type: 'tag', target_id: null } ),
			],
			( id ) => id !== 9
		);

		expect( groups.map( ( g ) => g.key ) ).toEqual( [ '1-2' ] );
	} );

	it( 'keys a pair the same way round', () => {
		expect( pairKey( 5, 2 ) ).toBe( pairKey( 2, 5 ) );
	} );
} );

describe( 'splitStMarkers', () => {
	it( 'returns plain text as one segment', () => {
		expect( splitStMarkers( 'Old friends.' ) ).toEqual( [
			{ text: 'Old friends.', st: false },
		] );
	} );

	it( 'marks the passage between [ST] and [/ST]', () => {
		expect(
			splitStMarkers( 'Friends. [ST]He is her sire.[/ST] Allies.' )
		).toEqual( [
			{ text: 'Friends. ', st: false },
			{ text: 'He is her sire.', st: true },
			{ text: ' Allies.', st: false },
		] );
	} );

	it( 'runs an opener with no closer to the end', () => {
		expect( splitStMarkers( 'Known. [ST]Secret' ) ).toEqual( [
			{ text: 'Known. ', st: false },
			{ text: 'Secret', st: true },
		] );
	} );

	it( 'returns nothing for no text', () => {
		expect( splitStMarkers( '' ) ).toEqual( [] );
	} );
} );

describe( 'edgeSummary', () => {
	it( 'names the pair and the label of a single connection', () => {
		expect(
			edgeSummary( 'Isolde', 'Konstantin', [
				connection( { label: 'Primogen Council' } ),
			] )
		).toBe( 'Isolde and Konstantin: Primogen Council' );
	} );

	it( 'names just the pair when the connection has no label', () => {
		expect( edgeSummary( 'Isolde', 'Konstantin', [ connection() ] ) ).toBe(
			'Isolde and Konstantin'
		);
	} );

	it( 'counts the connections when there are several', () => {
		expect(
			edgeSummary( 'Isolde', 'Konstantin', [
				connection( { id: 1 } ),
				connection( { id: 2 } ),
			] )
		).toBe( 'Isolde and Konstantin: 2 connections' );
	} );
} );

describe( 'mergeCharacters', () => {
	const named = ( id: number, name: string ) => ( { id, name } ) as Character;

	it( 'lists player characters and NPCs together in name order', () => {
		const merged = mergeCharacters(
			[ named( 2, 'Konstantin Drake' ), named( 1, 'Isolde Marchetti' ) ],
			[ named( 9, 'Radu Bathory' ) ]
		);

		expect( merged.map( ( c ) => c.name ) ).toEqual( [
			'Isolde Marchetti',
			'Konstantin Drake',
			'Radu Bathory',
		] );
	} );

	it( 'keeps a character held in both lists once', () => {
		const merged = mergeCharacters(
			[ named( 1, 'Isolde Marchetti' ) ],
			[ named( 1, 'Isolde Marchetti' ), named( 9, 'Radu Bathory' ) ]
		);

		expect( merged.map( ( c ) => c.id ) ).toEqual( [ 1, 9 ] );
	} );
} );

describe( 'wrapLabel', () => {
	it( 'keeps a name that fits on one line', () => {
		expect( wrapLabel( 'Isolde Marchetti' ) ).toEqual( [
			'Isolde Marchetti',
		] );
		expect( wrapLabel( 'Detective Rosa Alvarez' ) ).toEqual( [
			'Detective Rosa Alvarez',
		] );
	} );

	it( 'breaks a long name at spaces', () => {
		expect(
			wrapLabel( 'Selene Marchetti-Cole of the Hollow Court' )
		).toEqual( [ 'Selene Marchetti-Cole', 'of the Hollow Court' ] );
	} );

	it( 'cuts a single word longer than a line', () => {
		expect( wrapLabel( 'A'.repeat( 30 ) ) ).toEqual( [
			'A'.repeat( 22 ),
			'A'.repeat( 8 ),
		] );
	} );

	it( 'gives an empty name back as it is', () => {
		expect( wrapLabel( '' ) ).toEqual( [ '' ] );
	} );
} );
