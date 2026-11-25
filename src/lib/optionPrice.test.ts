import {
	addedRowChange,
	pricingEnabled,
	priceBatches,
	priceHint,
	PRICE_BATCH_SIZE,
} from './optionPrice';
import type { ChangePreviewResult } from '../types/character';

function quote(
	overrides: Partial< ChangePreviewResult > = {}
): ChangePreviewResult {
	return {
		xp_cost: 3,
		approval_level: 'auto',
		approval_reason: null,
		priced: true,
		...overrides,
	};
}

describe( 'priceHint', () => {
	it( 'is the price in XP', () => {
		expect( priceHint( quote(), false ) ).toBe( '3 XP' );
		expect( priceHint( quote( { xp_cost: 0 } ), false ) ).toBe( '0 XP' );
		expect( priceHint( quote( { xp_cost: -2 } ), false ) ).toBe( '-2 XP' );
	} );

	it( 'says "from" when the cost is the player\'s to choose', () => {
		expect( priceHint( quote( { xp_cost: 2 } ), true ) ).toBe(
			'from 2 XP'
		);
	} );

	it( 'adds that a Storyteller approves it', () => {
		expect( priceHint( quote( { approval_level: 'st' } ), false ) ).toBe(
			'3 XP · Storyteller'
		);
		expect(
			priceHint( quote( { xp_cost: 2, approval_level: 'st' } ), true )
		).toBe( 'from 2 XP · Storyteller' );
	} );

	it( 'says a Storyteller prices an item nobody has priced', () => {
		expect(
			priceHint(
				quote( { priced: false, approval_level: 'st', xp_cost: 0 } ),
				false
			)
		).toBe( 'Storyteller prices it' );
	} );

	it( 'shows nothing for a change the server would refuse', () => {
		expect(
			priceHint(
				quote( {
					invalid: true,
					error: { code: 'held', message: 'Already held.' },
				} ),
				false
			)
		).toBeNull();
	} );

	it( 'treats a quote with no priced flag as priced', () => {
		expect( priceHint( quote( { priced: undefined } ), false ) ).toBe(
			'3 XP'
		);
	} );
} );

describe( 'priceBatches', () => {
	it( 'asks only for names not already known, each once', () => {
		expect(
			priceBatches( [ 'a', 'b', 'a', 'c' ], new Set( [ 'b' ] ) )
		).toEqual( [ [ 'a', 'c' ] ] );
	} );

	it( 'has nothing to ask when everything is known', () => {
		expect( priceBatches( [ 'a' ], new Set( [ 'a' ] ) ) ).toEqual( [] );
		expect( priceBatches( [], new Set() ) ).toEqual( [] );
	} );

	it( 'splits a long list into requests of at most the batch size', () => {
		const names = Array.from( { length: 85 }, ( _, i ) => `n${ i }` );
		const batches = priceBatches( names, new Set() );
		expect( PRICE_BATCH_SIZE ).toBe( 40 );
		expect( batches.map( ( batch ) => batch.length ) ).toEqual( [
			40, 40, 5,
		] );
		expect( batches.flat() ).toEqual( names );
	} );
} );

describe( 'addedRowChange', () => {
	const definition = { items: [ { name: 'Occult' } ] } as never;

	it( 'is the add a real pick of that trait would submit', () => {
		const change = addedRowChange(
			'trait_list',
			'vampire-abilities',
			definition,
			[ { name: 'Academics', count: 3 } ],
			[
				{ name: 'Academics', count: 3 },
				{ name: 'Occult', count: 1, custom: false },
			]
		);
		expect( change?.change_type ).toBe( 'add_trait' );
		expect( change?.change_data ).toMatchObject( {
			block_slug: 'vampire-abilities',
			trait: { name: 'Occult' },
		} );
	} );

	it( 'is a raise when the trait is already held', () => {
		const change = addedRowChange(
			'trait_list',
			'vampire-abilities',
			definition,
			[ { name: 'Occult', count: 2 } ],
			[ { name: 'Occult', count: 3 } ]
		);
		expect( change?.change_type ).toBe( 'modify_trait' );
	} );

	it( 'is a new power for a tiered_power block', () => {
		const change = addedRowChange(
			'tiered_power',
			'disciplines',
			{ powers: [] } as never,
			[],
			[ { name: 'Auspex', level: 1 } ]
		);
		expect( change?.change_type ).toBe( 'add_trait' );
		expect( change?.change_data ).toMatchObject( {
			block_slug: 'disciplines',
			trait: { name: 'Auspex' },
		} );
	} );

	it( 'is nothing when the pick changes nothing', () => {
		expect(
			addedRowChange(
				'trait_list',
				'vampire-abilities',
				definition,
				[ { name: 'Occult', count: 2 } ],
				[ { name: 'Occult', count: 2 } ]
			)
		).toBeNull();
	} );

	it( 'is nothing for a block type that has no add', () => {
		expect(
			addedRowChange(
				'resource_pool',
				'pools',
				{ pools: [] } as never,
				{},
				{}
			)
		).toBeNull();
	} );
} );

describe( 'pricingEnabled', () => {
	const player = { gameSlug: 'kony', characterId: 7 };

	it( "is on for a player editing a saved character's list", () => {
		expect( pricingEnabled( player ) ).toBe( true );
	} );

	it( 'is off for a Storyteller, whose own changes cost nothing', () => {
		expect( pricingEnabled( { ...player, isManager: true } ) ).toBe(
			false
		);
	} );

	it( 'is off for a character not saved yet', () => {
		expect( pricingEnabled( { gameSlug: 'kony' } ) ).toBe( false );
		expect( pricingEnabled( { gameSlug: 'kony', characterId: 0 } ) ).toBe(
			false
		);
		expect( pricingEnabled( { ...player, creating: true } ) ).toBe( false );
	} );

	it( 'is off for a list nobody can edit or that costs no XP', () => {
		expect( pricingEnabled( { ...player, readOnly: true } ) ).toBe( false );
		expect( pricingEnabled( { ...player, unpriced: true } ) ).toBe( false );
	} );

	it( 'is off outside a chronicle', () => {
		expect( pricingEnabled( { characterId: 7 } ) ).toBe( false );
	} );
} );
