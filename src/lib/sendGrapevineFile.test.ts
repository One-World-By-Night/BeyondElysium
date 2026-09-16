import { otherChroniclesFor } from './sendGrapevineFile';
import type { MyGame, Game } from '../types';

function game( slug: string, name: string ): Game {
	return {
		id: 1,
		name,
		slug,
		game_type: 'met',
		description: '',
		settings: null,
		asc_role_path: null,
	} as Game;
}

function myGame( slug: string, name: string ): MyGame {
	return { slug, name, role: 'player' };
}

describe( 'otherChroniclesFor', () => {
	it( 'puts every chronicle the sender does not already belong to under Other', () => {
		const mine = [ myGame( 'kony', 'Kings of New York' ) ];
		const all = [
			game( 'kony', 'Kings of New York' ),
			game( 'boston', 'Boston by Night' ),
		];

		expect( otherChroniclesFor( mine, all ) ).toEqual( [
			game( 'boston', 'Boston by Night' ),
		] );
	} );

	it( 'never repeats a chronicle the sender already belongs to (F-122)', () => {
		const mine = [
			myGame( 'kony', 'Kings of New York' ),
			myGame( 'boston', 'Boston by Night' ),
		];
		const all = [
			game( 'kony', 'Kings of New York' ),
			game( 'boston', 'Boston by Night' ),
			game( 'be-demo', 'Beyond Elysium Demo' ),
		];

		const others = otherChroniclesFor( mine, all );
		expect( others.map( ( g ) => g.slug ) ).toEqual( [ 'be-demo' ] );
	} );

	it( 'returns every chronicle when the sender has no memberships at all', () => {
		const all = [ game( 'kony', 'Kings of New York' ) ];
		expect( otherChroniclesFor( [], all ) ).toEqual( all );
	} );
} );
