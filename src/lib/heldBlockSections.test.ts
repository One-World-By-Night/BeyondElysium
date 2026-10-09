import { describe, expect, it } from 'vitest';
import { allowsAnyBlock, withHeldBlockSections } from './heldBlockSections';
import type {
	ResolvedStack,
	SchemaBlock,
	TemplateLayoutSection,
} from '../types';

function section(
	blockSlug: string,
	column: number,
	order: number
): TemplateLayoutSection {
	return {
		block_slug: blockSlug,
		column,
		order,
		title: blockSlug,
		display: null,
		collapsed: false,
		width: 'half',
	};
}

function stackOf( anyBlock: boolean, slugs: string[] ): ResolvedStack {
	const blocks: Record< string, SchemaBlock > = {};
	for ( const slug of slugs ) {
		blocks[ slug ] = { slug, name: `Name of ${ slug }` } as SchemaBlock;
	}
	return {
		stack: {
			slug: 'various',
			stack_definition: { sections: [], any_block: anyBlock },
		},
		blocks,
	} as unknown as ResolvedStack;
}

const layout = [ section( 'various-identity', 1, 1 ), section( 'a', 2, 1 ) ];

describe( 'allowsAnyBlock', () => {
	it( 'reads the creature type flag', () => {
		expect( allowsAnyBlock( stackOf( true, [] ) ) ).toBe( true );
		expect( allowsAnyBlock( stackOf( false, [] ) ) ).toBe( false );
		expect( allowsAnyBlock( null ) ).toBe( false );
	} );
} );

describe( 'withHeldBlockSections', () => {
	it( 'leaves a creature type that does not allow any block unchanged', () => {
		const stack = stackOf( false, [ 'vampire-disciplines' ] );
		expect(
			withHeldBlockSections( layout, stack, {
				'vampire-disciplines': [ { name: 'Auspex' } ],
			} )
		).toBe( layout );
	} );

	it( 'appends a full-width section for each held block the layout does not place, after the last column', () => {
		const stack = stackOf( true, [
			'various-identity',
			'a',
			'vampire-disciplines',
			'werewolf-gifts',
		] );
		const result = withHeldBlockSections( layout, stack, {
			'various-identity': {},
			'vampire-disciplines': [ { name: 'Auspex' } ],
			'werewolf-gifts': [],
		} );
		expect( result.map( ( s ) => s.block_slug ) ).toEqual( [
			'various-identity',
			'a',
			'vampire-disciplines',
			'werewolf-gifts',
		] );
		expect( result[ 2 ] ).toMatchObject( {
			column: 3,
			order: 1,
			title: 'Name of vampire-disciplines',
			width: 'full',
			collapsed: false,
		} );
		expect( result[ 3 ].order ).toBe( 2 );
	} );

	it( 'adds a block that was just added but holds nothing yet', () => {
		const stack = stackOf( true, [ 'various-identity', 'mage-spheres' ] );
		const result = withHeldBlockSections( layout, stack, {}, [
			'mage-spheres',
		] );
		expect( result.map( ( s ) => s.block_slug ) ).toContain(
			'mage-spheres'
		);
	} );

	it( 'skips a held block with no definition and never lists a block twice', () => {
		const stack = stackOf( true, [ 'various-identity', 'x' ] );
		const result = withHeldBlockSections(
			layout,
			stack,
			{ unknown: [ 1 ], x: [ 1 ] },
			[ 'x' ]
		);
		expect( result.map( ( s ) => s.block_slug ) ).toEqual( [
			'various-identity',
			'a',
			'x',
		] );
	} );

	it( 'starts at column one when the layout has no sections', () => {
		const stack = stackOf( true, [ 'x' ] );
		const result = withHeldBlockSections( [], stack, { x: [ 1 ] } );
		expect( result ).toHaveLength( 1 );
		expect( result[ 0 ].column ).toBe( 1 );
	} );
} );
