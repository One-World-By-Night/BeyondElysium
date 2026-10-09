import { describe, expect, it } from 'vitest';
import {
	blockChoices,
	blockLabel,
	creatureTypesOfBlock,
	groupBlocksByCreatureType,
	optionLabel,
} from './blockGroups';
import type { CreatureStack, SchemaBlock } from '../types';

function block( slug: string, name: string ): SchemaBlock {
	return { slug, name } as unknown as SchemaBlock;
}

function stack(
	slug: string,
	name: string,
	sections: Array< { block_slug: string; negative_block_slug?: string } >
): CreatureStack {
	return {
		slug,
		name,
		stack_definition: {
			sections: sections.map( ( s, i ) => ( {
				...s,
				label: s.block_slug,
				display_order: i,
				required: false,
			} ) ),
		},
	} as unknown as CreatureStack;
}

const LABELS = {
	shared: 'Shared',
	other: 'Other',
	notEnabled: ( name: string ) => `${ name } (not enabled here)`,
};

const blocks = [
	block( 'vampire-abilities', 'Abilities' ),
	block( 'werewolf-abilities', 'Abilities' ),
	block( 'vampire-disciplines', 'Disciplines' ),
	block( 'met-physical-traits', 'Physical Traits' ),
	block( 'met-physical-traits-neg', 'Physical Traits (Negative)' ),
	block( 'orphan-block', 'Orphan' ),
];

const stacks = [
	stack( 'werewolf', 'Werewolf', [
		{ block_slug: 'werewolf-abilities' },
		{
			block_slug: 'met-physical-traits',
			negative_block_slug: 'met-physical-traits-neg',
		},
	] ),
	stack( 'vampire', 'Vampire', [
		{ block_slug: 'vampire-disciplines' },
		{ block_slug: 'vampire-abilities' },
		{
			block_slug: 'met-physical-traits',
			negative_block_slug: 'met-physical-traits-neg',
		},
	] ),
];

describe( 'creatureTypesOfBlock', () => {
	it( 'lists every creature type whose template includes a block, negative halves too', () => {
		const owners = creatureTypesOfBlock( stacks );
		expect( owners.get( 'vampire-abilities' ) ).toEqual( [ 'Vampire' ] );
		expect( owners.get( 'met-physical-traits' ) ).toEqual( [
			'Werewolf',
			'Vampire',
		] );
		expect( owners.get( 'met-physical-traits-neg' ) ).toEqual( [
			'Werewolf',
			'Vampire',
		] );
		expect( owners.has( 'orphan-block' ) ).toBe( false );
	} );
} );

describe( 'groupBlocksByCreatureType', () => {
	it( 'puts each creature type own blocks in its own group, in template order', () => {
		const groups = groupBlocksByCreatureType(
			blocks,
			stacks,
			new Set( [ 'vampire', 'werewolf' ] ),
			LABELS
		);
		const vampire = groups.find( ( g ) => g.label === 'Vampire' );
		expect( vampire?.blocks.map( ( b ) => b.slug ) ).toEqual( [
			'vampire-disciplines',
			'vampire-abilities',
		] );
		const werewolf = groups.find( ( g ) => g.label === 'Werewolf' );
		expect( werewolf?.blocks.map( ( b ) => b.slug ) ).toEqual( [
			'werewolf-abilities',
		] );
	} );

	it( 'puts blocks more than one type uses under Shared, and blocks no type uses under Other', () => {
		const groups = groupBlocksByCreatureType(
			blocks,
			stacks,
			new Set( [ 'vampire', 'werewolf' ] ),
			LABELS
		);
		const shared = groups.find( ( g ) => g.label === 'Shared' );
		expect( shared?.blocks.map( ( b ) => b.slug ) ).toEqual( [
			'met-physical-traits',
			'met-physical-traits-neg',
		] );
		const other = groups.find( ( g ) => g.label === 'Other' );
		expect( other?.blocks.map( ( b ) => b.slug ) ).toEqual( [
			'orphan-block',
		] );
	} );

	it( 'lists enabled creature types first and labels the rest', () => {
		const groups = groupBlocksByCreatureType(
			blocks,
			stacks,
			new Set( [ 'werewolf' ] ),
			LABELS
		);
		expect( groups.map( ( g ) => g.label ) ).toEqual( [
			'Werewolf',
			'Vampire (not enabled here)',
			'Shared',
			'Other',
		] );
	} );

	it( 'never lists a block twice, so two blocks named Abilities are told apart by their group', () => {
		const groups = groupBlocksByCreatureType(
			blocks,
			stacks,
			new Set( [ 'vampire', 'werewolf' ] ),
			LABELS
		);
		const slugs = groups.flatMap( ( g ) =>
			g.blocks.map( ( b ) => b.slug )
		);
		expect( slugs.length ).toBe( new Set( slugs ).size );
		expect( slugs.length ).toBe( blocks.length );
	} );
} );

describe( 'blockLabel', () => {
	const owners = creatureTypesOfBlock( stacks );

	it( 'adds the creature type to a block only one type owns', () => {
		expect( blockLabel( 'Abilities', 'vampire-abilities', owners ) ).toBe(
			'Abilities (Vampire)'
		);
	} );

	it( 'leaves a shared or unowned block as its plain name', () => {
		expect(
			blockLabel( 'Physical Traits', 'met-physical-traits', owners )
		).toBe( 'Physical Traits' );
		expect( blockLabel( 'Orphan', 'orphan-block', owners ) ).toBe(
			'Orphan'
		);
	} );
} );

describe( 'blocks that share a name', () => {
	const gifts = [
		block( 'werewolf-gifts', 'Gifts' ),
		block( 'fera-gifts', 'Gifts' ),
		block( 'vampire-disciplines', 'Disciplines' ),
	];

	it( 'adds the slug to the label of a shared block whose name is repeated', () => {
		expect( blockLabel( 'Gifts', 'fera-gifts', new Map(), gifts ) ).toBe(
			'Gifts [fera-gifts]'
		);
	} );

	it( 'leaves a shared block with a one-off name as it is', () => {
		expect(
			blockLabel( 'Disciplines', 'vampire-disciplines', new Map(), gifts )
		).toBe( 'Disciplines' );
	} );

	it( 'still names the creature type of a block one type owns', () => {
		const owners = new Map( [ [ 'werewolf-gifts', [ 'Werewolf' ] ] ] );
		expect( blockLabel( 'Gifts', 'werewolf-gifts', owners, gifts ) ).toBe(
			'Gifts (Werewolf)'
		);
	} );

	it( 'adds the slug inside a group only for the names the group repeats', () => {
		expect( optionLabel( gifts[ 0 ], gifts ) ).toBe(
			'Gifts [werewolf-gifts]'
		);
		expect( optionLabel( gifts[ 2 ], gifts ) ).toBe( 'Disciplines' );
	} );
} );

describe( 'a block named for a creature type', () => {
	const catalog = [
		block( 'vampire-disciplines', 'Disciplines' ),
		block( 'werewolf-gifts', 'Gifts' ),
		block( 'fera-gifts', 'Gifts' ),
		block( 'fera-abilities', 'Abilities' ),
		block( 'darkages-vampire_disciplines', 'Disciplines (Dark Ages)' ),
		block( 'owbn-wraith_arcanoi', 'Arcanoi (OWBN)' ),
		block( 'vampire-gargoyle-powers', 'Gargoyle Powers' ),
		block( 'mortal-merits', 'Merits' ),
		block( 'met-derangements', 'Derangements' ),
		block( 'npc-quick-stats', 'NPC Quick Stats' ),
	];
	const typed = [
		stack( 'mortal', 'Mortal', [
			{ block_slug: 'mortal-merits' },
			{ block_slug: 'vampire-disciplines' },
			{ block_slug: 'werewolf-gifts' },
			{ block_slug: 'fera-gifts' },
			{ block_slug: 'met-derangements' },
		] ),
		stack( 'vampire', 'Vampire', [
			{ block_slug: 'vampire-disciplines' },
			{ block_slug: 'met-derangements' },
		] ),
		stack( 'werewolf', 'Werewolf', [
			{ block_slug: 'werewolf-gifts' },
			{ block_slug: 'met-derangements' },
		] ),
		stack( 'fera', 'Fera', [
			{ block_slug: 'fera-abilities' },
			{ block_slug: 'fera-gifts' },
		] ),
		stack( 'bete', 'Bete', [
			{ block_slug: 'fera-abilities' },
			{ block_slug: 'fera-gifts' },
		] ),
		stack( 'wraith', 'Wraith', [] ),
	];
	const on = new Set( typed.map( ( s ) => s.slug ) );

	function group( label: string ) {
		return groupBlocksByCreatureType( catalog, typed, on, LABELS )
			.find( ( g ) => g.label === label )
			?.blocks.map( ( b ) => b.slug );
	}

	it( 'files it under that creature type although another type also lists it', () => {
		expect( group( 'Vampire' ) ).toContain( 'vampire-disciplines' );
		expect( group( 'Werewolf' ) ).toContain( 'werewolf-gifts' );
		expect( group( LABELS.shared ) ).not.toContain( 'vampire-disciplines' );
		expect( group( LABELS.shared ) ).not.toContain( 'werewolf-gifts' );
	} );

	it( 'files a block two sibling types list under the type its slug names', () => {
		expect( group( 'Fera' ) ).toEqual( [ 'fera-abilities', 'fera-gifts' ] );
		expect( group( 'Bete' ) ).toBeUndefined();
	} );

	it( 'files a printing named for its edition, type and family under that type', () => {
		expect( group( 'Vampire' ) ).toContain(
			'darkages-vampire_disciplines'
		);
		expect( group( 'Wraith' ) ).toEqual( [ 'owbn-wraith_arcanoi' ] );
	} );

	it( 'files a block no template lists under the type its slug names', () => {
		expect( group( 'Vampire' ) ).toContain( 'vampire-gargoyle-powers' );
	} );

	it( 'lists a type template blocks first, then its other blocks by name', () => {
		expect( group( 'Vampire' ) ).toEqual( [
			'vampire-disciplines',
			'darkages-vampire_disciplines',
			'vampire-gargoyle-powers',
		] );
	} );

	it( 'keeps counting templates for a slug that names no creature type', () => {
		expect( group( LABELS.shared ) ).toEqual( [ 'met-derangements' ] );
		expect( group( LABELS.other ) ).toEqual( [ 'npc-quick-stats' ] );
	} );

	it( 'never lists a block twice', () => {
		const slugs = groupBlocksByCreatureType(
			catalog,
			typed,
			on,
			LABELS
		).flatMap( ( g ) => g.blocks.map( ( b ) => b.slug ) );
		expect( slugs.length ).toBe( new Set( slugs ).size );
		expect( slugs.length ).toBe( catalog.length );
	} );

	it( 'labels it with the creature type its slug names', () => {
		const owners = creatureTypesOfBlock( typed, catalog );
		expect(
			blockLabel( 'Disciplines', 'vampire-disciplines', owners, catalog )
		).toBe( 'Disciplines (Vampire)' );
		expect( blockLabel( 'Gifts', 'fera-gifts', owners, catalog ) ).toBe(
			'Gifts (Fera)'
		);
		expect(
			blockLabel(
				'Disciplines (Dark Ages)',
				'darkages-vampire_disciplines',
				owners,
				catalog
			)
		).toBe( 'Disciplines (Dark Ages) (Vampire)' );
		expect(
			blockLabel( 'Derangements', 'met-derangements', owners, catalog )
		).toBe( 'Derangements' );
	} );

	it( 'matches the longest creature type slug when one is a prefix of another', () => {
		const nested = [
			stack( 'kuei', 'Kuei', [] ),
			stack( 'kuei-jin', 'Kuei-Jin', [] ),
		];
		const owners = creatureTypesOfBlock( nested, [
			block( 'kuei-jin-rites', 'Rites' ),
		] );
		expect( owners.get( 'kuei-jin-rites' ) ).toEqual( [ 'Kuei-Jin' ] );
	} );
} );

describe( 'blockChoices', () => {
	const twoTypes = [
		stack( 'vampire', 'Vampire', [ { block_slug: 'vampire-abilities' } ] ),
		stack( 'werewolf', 'Werewolf', [
			{ block_slug: 'werewolf-abilities' },
		] ),
	];
	const catalog = [
		block( 'vampire-abilities', 'Abilities' ),
		block( 'werewolf-abilities', 'Abilities' ),
		block( 'met-derangements', 'Derangements' ),
		block( 'vampire-merits', 'Merits' ),
		block( 'vampire-merits-copy', 'Merits' ),
	];
	const on = new Set( [ 'vampire', 'werewolf' ] );

	function choices() {
		const grouped = groupBlocksByCreatureType(
			catalog,
			twoTypes,
			on,
			LABELS
		);
		return blockChoices(
			grouped,
			creatureTypesOfBlock( twoTypes, catalog ),
			catalog
		);
	}

	it( 'keeps the groups and names the creature type on each block it owns', () => {
		const { groups } = choices();
		expect( groups.map( ( g ) => g.label ) ).toEqual( [
			'Vampire',
			'Werewolf',
			'Other',
		] );
		expect( groups[ 0 ].options ).toContain( 'Abilities (Vampire)' );
		expect( groups[ 1 ].options ).toEqual( [ 'Abilities (Werewolf)' ] );
	} );

	it( 'gives every block its own label, even when two share a name', () => {
		const labels = choices().groups.flatMap( ( g ) => g.options );
		expect( labels.length ).toBe( catalog.length );
		expect( new Set( labels ).size ).toBe( labels.length );
	} );

	it( 'turns a chosen label back into its slug and a slug into its label', () => {
		const picked = choices();
		for ( const b of catalog ) {
			const label = picked.labelOf( b.slug );
			expect( label ).not.toBe( '' );
			expect( picked.slugOf( label ) ).toBe( b.slug );
		}
	} );

	it( 'answers nothing for a slug or label it does not know', () => {
		const picked = choices();
		expect( picked.labelOf( 'nope' ) ).toBe( '' );
		expect( picked.slugOf( 'Nope' ) ).toBeUndefined();
		expect( picked.labelOf( '' ) ).toBe( '' );
	} );

	it( 'leaves a block no type owns with its plain name', () => {
		expect( choices().labelOf( 'met-derangements' ) ).toBe(
			'Derangements'
		);
	} );

	it( 'does not repeat the creature type when the block name already says it', () => {
		const named = [
			block( 'vampire-identity', 'Vampire Identity' ),
			block( 'vampire-abilities', 'Abilities' ),
		];
		const types = [
			stack( 'vampire', 'Vampire', [
				{ block_slug: 'vampire-identity' },
				{ block_slug: 'vampire-abilities' },
			] ),
		];
		const grouped = groupBlocksByCreatureType(
			named,
			types,
			new Set( [ 'vampire' ] ),
			LABELS
		);
		const picked = blockChoices(
			grouped,
			creatureTypesOfBlock( types, named ),
			named
		);
		expect( picked.labelOf( 'vampire-identity' ) ).toBe(
			'Vampire Identity'
		);
		expect( picked.labelOf( 'vampire-abilities' ) ).toBe(
			'Abilities (Vampire)'
		);
		expect( picked.slugOf( 'Vampire Identity' ) ).toBe(
			'vampire-identity'
		);
	} );
} );
