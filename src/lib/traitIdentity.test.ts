import { readFileSync } from 'fs';
import { join } from 'path';
import { traitRowIdentity, labelPrompt } from './traitIdentity';
import type { TraitListDefinition } from '../types';

/**
 * `labelPrompt()` decides which label the editor asks for, and the separator `traitRowIdentity()` joins on is a real
 * escape.
 */

/**
 * An Abilities-shaped block: specializations, no multiples anywhere.
 */
function abilities(
	overrides: Partial< TraitListDefinition > = {}
): TraitListDefinition {
	return {
		items: [
			{ name: 'Brawl', cost: '1' },
			{ name: 'Occult', cost: '1' },
		],
		has_specializations: true,
		...overrides,
	};
}

/**
 * A Backgrounds-shaped block: no specializations, Retainers alone repeatable.
 */
function backgrounds(): TraitListDefinition {
	return {
		items: [
			{ name: 'Retainers', cost: '1', allow_multiples: true },
			{ name: 'Generation', cost: '1' },
		],
	};
}

describe( 'labelPrompt', () => {
	it( 'asks for a specialization on a block that has them', () => {
		expect( labelPrompt( abilities(), 'Brawl' ) ).toBe( 'specialization' );
	} );

	it( 'asks "who or what?" for an allow_multiples item with no specializations', () => {
		expect( labelPrompt( backgrounds(), 'Retainers' ) ).toBe(
			'who_or_what'
		);
	} );

	it( 'asks nothing for a plain item', () => {
		expect( labelPrompt( backgrounds(), 'Generation' ) ).toBeNull();
	} );

	it( 'asks nothing for an empty name', () => {
		expect( labelPrompt( backgrounds(), '' ) ).toBeNull();
	} );

	it( 'lets an item-level false override a block-level true', () => {
		const block = backgrounds();
		block.allow_multiples = true;
		block.items[ 0 ].allow_multiples = false;
		expect( labelPrompt( block, 'Retainers' ) ).toBeNull();
	} );

	it( 'keeps asking for a specialization even where the item also allows multiples', () => {
		// Lore is allow_multiples on a block that has_specializations.
		const block = abilities();
		block.items[ 0 ].allow_multiples = true;
		expect( labelPrompt( block, 'Brawl' ) ).toBe( 'specialization' );
	} );
} );

describe( 'traitRowIdentity separator', () => {
	it( 'joins name and label with the escaped NUL, not a plain character', () => {
		const identity = traitRowIdentity( backgrounds(), {
			name: 'Retainers',
			specialization: 'Bob',
		} );
		expect( identity ).toBe( 'Retainers\u0000Bob' );
		expect( identity ).not.toBe( 'Retainers Bob' );
	} );

	it( 'the source file on disk carries no raw NUL byte', () => {
		const source = readFileSync(
			join( __dirname, 'traitIdentity.ts' ),
			'utf8'
		);
		expect( source ).not.toContain( '\u0000' );
		expect( source ).toContain( "'\\u0000'" );
	} );
} );
