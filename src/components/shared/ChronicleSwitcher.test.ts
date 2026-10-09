/**
 * `switcherOptionLabel()` names one chronicle in the switcher dropdown: its name and role, "- Demo" where it is one,
 * and a pending count shown only on a chronicle other than the one currently selected.
 */
import { switcherOptionLabel } from './ChronicleSwitcher';
import type { MyGame } from '../../types';

function game( overrides: Partial< MyGame > = {} ): MyGame {
	return {
		slug: 'kony',
		name: 'Kingdom of Night',
		role: 'player',
		demo: false,
		asc_linked: false,
		...overrides,
	};
}

describe( 'switcherOptionLabel', () => {
	it( 'names the chronicle and the role', () => {
		expect( switcherOptionLabel( game(), 'kony' ) ).toBe(
			'Kingdom of Night (player)'
		);
	} );

	it( 'marks a demo chronicle', () => {
		expect( switcherOptionLabel( game( { demo: true } ), 'kony' ) ).toBe(
			'Kingdom of Night (player) - Demo'
		);
	} );

	it( 'shows a pending count for a chronicle other than the one selected', () => {
		const label = switcherOptionLabel(
			game( { slug: 'elsewhere' } ),
			'kony',
			{
				elsewhere: 3,
			}
		);
		expect( label ).toBe( 'Kingdom of Night (player) - 3 pending' );
	} );

	it( 'never shows a pending count for the chronicle currently selected', () => {
		const label = switcherOptionLabel( game( { slug: 'kony' } ), 'kony', {
			kony: 3,
		} );
		expect( label ).toBe( 'Kingdom of Night (player)' );
	} );

	it( 'shows no count when there is nothing pending there', () => {
		const label = switcherOptionLabel(
			game( { slug: 'elsewhere' } ),
			'kony',
			{}
		);
		expect( label ).toBe( 'Kingdom of Night (player)' );
	} );
} );
