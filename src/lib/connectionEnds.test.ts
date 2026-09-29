import { otherEnd } from './connectionEnds';
import type { Connection } from '../types/plot';

/**
 * A connection as the REST route sends it: its ids arrive as text.
 */
function stored( fields: Record< string, unknown > ): Connection {
	return {
		id: '5',
		game_id: '1',
		label: null,
		notes: null,
		created_by: '1',
		created_at: '2026-09-27 00:00:00',
		...fields,
	} as unknown as Connection;
}

describe( 'otherEnd', () => {
	it( "names the target when the entity is the connection's source", () => {
		const connection = stored( {
			source_type: 'character',
			source_id: '1034',
			target_type: 'character',
			target_id: '1036',
		} );

		expect( otherEnd( connection, 'character', 1034 ).id ).toBe( 1036 );
	} );

	it( "names the source when the entity is the connection's target", () => {
		const connection = stored( {
			source_type: 'plot',
			source_id: '77',
			target_type: 'character',
			target_id: '1034',
		} );

		expect( otherEnd( connection, 'character', 1034 ) ).toEqual( {
			type: 'plot',
			id: 77,
			label: null,
		} );
	} );

	it( 'keeps a tag connection with no target id', () => {
		const connection = stored( {
			source_type: 'character',
			source_id: '1034',
			target_type: 'tag',
			target_id: null,
			label: 'visiting player',
		} );

		expect( otherEnd( connection, 'character', 1034 ) ).toEqual( {
			type: 'tag',
			id: null,
			label: 'visiting player',
		} );
	} );
} );
