import { describe, expect, it } from 'vitest';
import { setPlayerOrder, showsReorderButton } from './playerOrderToggle';

interface Fixture {
	items: Array< { name: string } >;
	allow_custom?: boolean;
	player_order?: boolean;
}

describe( 'setPlayerOrder', () => {
	it( 'turns the flag on from an unset definition', () => {
		const definition: Fixture = { items: [] };
		const result = setPlayerOrder( definition, true );
		expect( result.player_order ).toBe( true );
	} );

	it( 'turns the flag off from an on definition', () => {
		const definition: Fixture = { items: [], player_order: true };
		const result = setPlayerOrder( definition, false );
		expect( result.player_order ).toBe( false );
	} );

	it( 'leaves every other field untouched', () => {
		const definition: Fixture = {
			items: [ { name: 'Celerity' } ],
			allow_custom: true,
		};
		const result = setPlayerOrder( definition, true );
		expect( result.items ).toEqual( [ { name: 'Celerity' } ] );
		expect( result.allow_custom ).toBe( true );
	} );
} );

describe( 'showsReorderButton', () => {
	it( 'is false when player_order is unset', () => {
		expect( showsReorderButton( {} ) ).toBe( false );
	} );

	it( 'is false when player_order is explicitly off', () => {
		expect( showsReorderButton( { player_order: false } ) ).toBe( false );
	} );

	it( 'is true when player_order is on', () => {
		expect( showsReorderButton( { player_order: true } ) ).toBe( true );
	} );
} );
