import { findTraitRowIndex, saveTraitDraft } from './TraitListEditor';
import { allowsMultiples, traitRowIdentity } from '../../lib/traitIdentity';
import type { EditableTrait } from './TraitListEditor';
import type { TraitListDefinition } from '../../types';

/**
 * A held row's identity is its `name` alone, unless the item (or, as a default, the block) carries `allow_multiples`,
 * in which case the specialization label is part of it.
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
 * A Backgrounds-shaped block where Retainers alone is genuinely repeatable.
 */
function backgrounds(): TraitListDefinition {
	return {
		items: [
			{ name: 'Retainers', cost: '1', allow_multiples: true },
			{ name: 'Generation', cost: '1' },
		],
		has_specializations: true,
	};
}

describe( 'allowsMultiples', () => {
	it( 'reads the item value when the item states one', () => {
		expect( allowsMultiples( backgrounds(), 'Retainers' ) ).toBe( true );
		expect( allowsMultiples( backgrounds(), 'Generation' ) ).toBe( false );
	} );

	it( 'falls back to the block default when the item says nothing', () => {
		expect(
			allowsMultiples( abilities( { allow_multiples: true } ), 'Brawl' )
		).toBe( true );
		expect( allowsMultiples( abilities(), 'Brawl' ) ).toBe( false );
	} );

	it( 'lets an item override a block that says the opposite', () => {
		const block = abilities( { allow_multiples: true } );
		block.items[ 0 ].allow_multiples = false;
		expect( allowsMultiples( block, 'Brawl' ) ).toBe( false );
	} );

	it( 'gives a custom entry the block default, since the catalog does not list it', () => {
		expect( allowsMultiples( abilities(), 'Homebrew Skill' ) ).toBe(
			false
		);
		expect(
			allowsMultiples(
				abilities( { allow_multiples: true } ),
				'Homebrew Skill'
			)
		).toBe( true );
	} );
} );

describe( 'traitRowIdentity', () => {
	it( 'ignores the label where the label is not part of the identity', () => {
		expect(
			traitRowIdentity( abilities(), {
				name: 'Brawl',
				specialization: 'Wrestling',
			} )
		).toBe(
			traitRowIdentity( abilities(), {
				name: 'Brawl',
				specialization: 'Boxing',
			} )
		);
	} );

	it( 'separates two labels where the item allows multiples', () => {
		expect(
			traitRowIdentity( backgrounds(), {
				name: 'Retainers',
				specialization: 'John Doe',
			} )
		).not.toBe(
			traitRowIdentity( backgrounds(), {
				name: 'Retainers',
				specialization: 'Sue Smith',
			} )
		);
	} );
} );

describe( 'findTraitRowIndex', () => {
	it( 'never merges into an atomic block, which is declared to append', () => {
		const rows: EditableTrait[] = [ { name: 'Brawl', count: 3 } ];
		expect(
			findTraitRowIndex( rows, abilities( { atomic: true } ), {
				name: 'Brawl',
			} )
		).toBe( -1 );
	} );

	it( 'never merges into a row already marked for removal', () => {
		const rows: EditableTrait[] = [
			{ name: 'Brawl', count: 3, _removed: true },
		];
		expect(
			findTraitRowIndex( rows, abilities(), { name: 'Brawl' } )
		).toBe( -1 );
	} );
} );

describe( 'saveTraitDraft - adding', () => {
	it( 'keeps one row when the same Ability is bought twice under different focus labels', () => {
		const first = saveTraitDraft(
			[],
			abilities(),
			{ name: 'Brawl', count: 5, specialization: 'Wrestling' },
			null
		);
		const second = saveTraitDraft(
			first,
			abilities(),
			{ name: 'Brawl', count: 2, specialization: 'Boxing' },
			null
		);

		expect( second ).toHaveLength( 1 );
		expect( second[ 0 ].count ).toBe( 7 );
		// The holding keeps the label it already carried; a second focus does not rewrite it.
		expect( second[ 0 ].specialization ).toBe( 'Wrestling' );
	} );

	it( 'adopts a label when the held row has none', () => {
		const rows = saveTraitDraft(
			[ { name: 'Brawl', count: 3 } ],
			abilities(),
			{ name: 'Brawl', count: 1, specialization: 'Wrestling' },
			null
		);

		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toMatchObject( {
			count: 4,
			specialization: 'Wrestling',
		} );
	} );

	it( 'gives two rows for two Retainers, which are two real purchases', () => {
		const first = saveTraitDraft(
			[],
			backgrounds(),
			{ name: 'Retainers', count: 3, specialization: 'John Doe' },
			null
		);
		const second = saveTraitDraft(
			first,
			backgrounds(),
			{ name: 'Retainers', count: 2, specialization: 'Sue Smith' },
			null
		);

		expect( second ).toHaveLength( 2 );
		expect( second.map( ( row ) => row.count ) ).toEqual( [ 3, 2 ] );
		expect( second.map( ( row ) => row.specialization ) ).toEqual( [
			'John Doe',
			'Sue Smith',
		] );
	} );

	it( 'still raises the same Retainer rather than holding them twice', () => {
		const rows = saveTraitDraft(
			[ { name: 'Retainers', count: 3, specialization: 'John Doe' } ],
			backgrounds(),
			{ name: 'Retainers', count: 1, specialization: 'John Doe' },
			null
		);

		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].count ).toBe( 4 );
	} );

	it( 'applies the block default to an item that states nothing of its own', () => {
		const block = abilities( { allow_multiples: true } );
		const rows = saveTraitDraft(
			[ { name: 'Brawl', count: 5, specialization: 'Wrestling' } ],
			block,
			{ name: 'Brawl', count: 2, specialization: 'Boxing' },
			null
		);

		expect( rows ).toHaveLength( 2 );
	} );

	it( 'lets an item overriding a permissive block collapse back to one row', () => {
		const block = abilities( { allow_multiples: true } );
		block.items[ 0 ].allow_multiples = false;
		const rows = saveTraitDraft(
			[ { name: 'Brawl', count: 5, specialization: 'Wrestling' } ],
			block,
			{ name: 'Brawl', count: 2, specialization: 'Boxing' },
			null
		);

		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].count ).toBe( 7 );
	} );

	it( 'appends on an atomic block, where a repeat really is a second entry', () => {
		const rows = saveTraitDraft(
			[ { name: 'Catlike Balance', count: 1 } ],
			abilities( { atomic: true } ),
			{ name: 'Catlike Balance', count: 1 },
			null
		);

		expect( rows ).toHaveLength( 2 );
	} );
} );

describe( 'saveTraitDraft - merging a note (F2, 1.3.2.1)', () => {
	it( 'adopts the drafted note when the merge target had none', () => {
		const rows = saveTraitDraft(
			[ { name: 'Retainers', count: 3, specialization: 'Bob' } ],
			backgrounds(),
			{
				name: 'Retainers',
				count: 1,
				specialization: 'Bob',
				note: 'Ex-mercenary',
			},
			null
		);

		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].note ).toBe( 'Ex-mercenary' );
	} );

	it( 'keeps the merge target note when both rows have one', () => {
		const rows = saveTraitDraft(
			[
				{
					name: 'Retainers',
					count: 3,
					specialization: 'Bob',
					note: 'Loyal since 2019',
				},
			],
			backgrounds(),
			{
				name: 'Retainers',
				count: 1,
				specialization: 'Bob',
				note: 'Ex-mercenary',
			},
			null
		);

		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].note ).toBe( 'Loyal since 2019' );
	} );
} );

describe( 'saveTraitDraft - editing', () => {
	it( 'merges into the pre-existing target when an edited label collides', () => {
		const rows = saveTraitDraft(
			[
				{ name: 'Retainers', count: 3, specialization: 'John Doe' },
				{ name: 'Retainers', count: 2, specialization: 'Sue Smith' },
			],
			backgrounds(),
			{ name: 'Retainers', count: 2, specialization: 'John Doe' },
			1
		);

		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toMatchObject( {
			count: 5,
			specialization: 'John Doe',
		} );
	} );

	it( 'relabels in place when nothing collides', () => {
		const rows = saveTraitDraft(
			[ { name: 'Brawl', count: 5, specialization: 'Wrestling' } ],
			abilities(),
			{ name: 'Brawl', count: 5, specialization: 'Boxing' },
			0
		);

		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].specialization ).toBe( 'Boxing' );
	} );

	it( 'leaves two same-named rows alone when an edit changes nothing about identity', () => {
		// Two rows of one name are not a state this editor can now create.
		const rows = saveTraitDraft(
			[
				{ name: 'Retainers', count: 3 },
				{ name: 'Retainers', count: 2 },
			],
			abilities(),
			{ name: 'Retainers', count: 4 },
			1
		);

		expect( rows ).toHaveLength( 2 );
		expect( rows.map( ( row ) => row.count ) ).toEqual( [ 3, 4 ] );
	} );
} );
