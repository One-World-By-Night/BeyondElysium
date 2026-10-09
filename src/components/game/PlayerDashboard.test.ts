/**
 * `groupMyChanges()` groups a player's own cross-chronicle changes by chronicle, current one first, pending above
 * recent within each group. `statusLabel()` names each of the four display statuses.
 */
import { groupMyChanges, statusLabel } from './PlayerDashboard';
import type { MyChangeAcrossGames } from '../../types/character';

function row(
	overrides: Partial< MyChangeAcrossGames > = {}
): MyChangeAcrossGames {
	return {
		id: 1,
		character_id: 1,
		character_name: 'Test Character',
		change_type: 'xp_earn',
		category: null,
		change_data: {},
		xp_cost: 0,
		status: 'pending',
		submitted_by: 1,
		reviewed_by: null,
		submitted_at: '2026-10-01 00:00:00',
		reviewed_at: null,
		notes: null,
		reason: null,
		game_slug: 'kony',
		game_name: 'Kingdom of Night',
		description: 'Earned 1 XP',
		display_status: 'pending',
		...overrides,
	};
}

describe( 'groupMyChanges', () => {
	it( 'puts the current chronicle first', () => {
		const groups = groupMyChanges(
			[
				row( {
					id: 1,
					game_slug: 'elsewhere',
					game_name: 'Elsewhere',
				} ),
				row( {
					id: 2,
					game_slug: 'kony',
					game_name: 'Kingdom of Night',
				} ),
			],
			'kony'
		);

		expect( groups.map( ( g ) => g.gameSlug ) ).toEqual( [
			'kony',
			'elsewhere',
		] );
	} );

	it( 'keeps every other chronicle in the order it is first seen', () => {
		const groups = groupMyChanges(
			[
				row( { id: 1, game_slug: 'b', game_name: 'B' } ),
				row( { id: 2, game_slug: 'a', game_name: 'A' } ),
				row( { id: 3, game_slug: 'b', game_name: 'B' } ),
			],
			'kony'
		);

		expect( groups.map( ( g ) => g.gameSlug ) ).toEqual( [ 'b', 'a' ] );
	} );

	it( 'sorts a pending change above a reviewed one within the same chronicle', () => {
		const groups = groupMyChanges(
			[
				row( { id: 1, display_status: 'approved' } ),
				row( { id: 2, display_status: 'pending' } ),
				row( { id: 3, display_status: 'refused' } ),
			],
			'kony'
		);

		expect( groups[ 0 ].changes.map( ( c ) => c.id ) ).toEqual( [
			2, 1, 3,
		] );
	} );

	it( 'returns one group per chronicle, each carrying its real name', () => {
		const groups = groupMyChanges(
			[ row( { game_slug: 'kony', game_name: 'Kingdom of Night' } ) ],
			'kony'
		);

		expect( groups ).toHaveLength( 1 );
		expect( groups[ 0 ].gameName ).toBe( 'Kingdom of Night' );
	} );

	it( 'returns no groups for an empty list', () => {
		expect( groupMyChanges( [], 'kony' ) ).toEqual( [] );
	} );
} );

describe( 'statusLabel', () => {
	it( 'names all four display statuses', () => {
		expect( statusLabel( 'pending' ) ).toBe( 'Pending' );
		expect( statusLabel( 'approved' ) ).toBe( 'Approved' );
		expect( statusLabel( 'auto_approved' ) ).toBe( 'Auto-approved' );
		expect( statusLabel( 'refused' ) ).toBe( 'Refused' );
	} );
} );
