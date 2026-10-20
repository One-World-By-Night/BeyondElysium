import {
	buildEditRequest,
	canMove,
	draftFor,
	inputFields,
	isMove,
	priceBoxKind,
	priceStart,
	priceTextValid,
	readField,
	usualPriceHint,
} from './queueEdit';
import type { PriceBasis, QueueChange } from '../types/character';

function change( overrides: Partial< QueueChange > = {} ): QueueChange {
	return {
		id: 1,
		character_id: 1,
		change_type: 'add_trait',
		category: 'vampire-rituals',
		change_data: {
			block_slug: 'vampire-rituals',
			trait: { name: 'Wax Ward', note: 'R1', count: 1, custom: true },
			cost_pending: true,
		},
		xp_cost: 0,
		status: 'pending',
		submitted_by: 1,
		reviewed_by: null,
		submitted_at: '2026-10-20 00:00:00',
		reviewed_at: null,
		notes: null,
		reason: null,
		character_name: 'Test Character',
		approval_level: 'st',
		submitted_by_name: null,
		review_token: 'token',
		editable_fields: [ 'block_slug', 'name', 'count', 'note' ],
		price_basis: {
			homebrew: true,
			per: 'pick',
			units: 1,
			negative: false,
			book_xp: null,
			current: null,
		},
		...overrides,
	};
}

const BOOK: PriceBasis = {
	homebrew: false,
	per: 'dot',
	units: 2,
	negative: false,
	book_xp: 6,
	current: null,
};

describe( 'inputFields', () => {
	it( 'lists the text boxes in the order the server names the fields, leaving the section list out', () => {
		expect( inputFields( change() ).map( ( f ) => f.key ) ).toEqual( [
			'name',
			'count',
			'note',
		] );
	} );

	it( 'draws a count as a number box and a name as a text box', () => {
		const kinds = Object.fromEntries(
			inputFields( change() ).map( ( f ) => [ f.key, f.kind ] )
		);
		expect( kinds ).toEqual( {
			name: 'text',
			count: 'number',
			note: 'text',
		} );
	} );

	it( 'is empty when the server names no editable field', () => {
		expect(
			inputFields( change( { editable_fields: undefined } ) )
		).toEqual( [] );
	} );
} );

describe( 'canMove', () => {
	it( 'is true when the server offers the section field', () => {
		expect( canMove( change() ) ).toBe( true );
		expect( canMove( change( { editable_fields: [ 'count' ] } ) ) ).toBe(
			false
		);
	} );
} );

describe( 'readField', () => {
	it( 'reads a trait field as text', () => {
		const c = change();
		expect( readField( c, 'name' ) ).toBe( 'Wax Ward' );
		expect( readField( c, 'count' ) ).toBe( '1' );
		expect( readField( c, 'note' ) ).toBe( 'R1' );
		expect( readField( c, 'specialization' ) ).toBe( '' );
	} );

	it( 'reads a pool rating whether the value is an object or a bare number', () => {
		const withObject = change( {
			change_type: 'modify_resource',
			change_data: {
				block_slug: 'b',
				values: { Willpower: { permanent: 7 } },
			},
		} );
		const bare = change( {
			change_type: 'modify_resource',
			change_data: { block_slug: 'b', values: { Willpower: 7 } },
		} );
		expect( readField( withObject, 'permanent' ) ).toBe( '7' );
		expect( readField( bare, 'permanent' ) ).toBe( '7' );
	} );

	it( "reads an identity change's single value and an experience change's amount and reason", () => {
		const identity = change( {
			change_type: 'modify_identity',
			change_data: { block_slug: 'b', fields: { Title: 'Regent' } },
		} );
		const xp = change( {
			change_type: 'xp_earn',
			change_data: { amount: 5, reason: 'Requested: Door duty' },
		} );
		expect( readField( identity, 'value' ) ).toBe( 'Regent' );
		expect( readField( xp, 'amount' ) ).toBe( '5' );
		expect( readField( xp, 'reason' ) ).toBe( 'Requested: Door duty' );
	} );
} );

describe( 'priceBoxKind', () => {
	it( 'is none without a basis, homebrew for an entry the book does not price, book otherwise', () => {
		expect( priceBoxKind( null ) ).toBe( 'none' );
		expect( priceBoxKind( undefined ) ).toBe( 'none' );
		expect( priceBoxKind( change().price_basis ) ).toBe( 'homebrew' );
		expect( priceBoxKind( BOOK ) ).toBe( 'book' );
	} );
} );

describe( 'priceStart and usualPriceHint', () => {
	it( 'starts at the price already set, or empty', () => {
		expect( priceStart( { ...BOOK, current: 4 } ) ).toBe( '4' );
		expect( priceStart( { ...BOOK, current: 0 } ) ).toBe( '0' );
		expect( priceStart( BOOK ) ).toBe( '' );
		expect( priceStart( null ) ).toBe( '' );
	} );

	it( 'names the book price without a sign', () => {
		expect( usualPriceHint( BOOK ) ).toBe( 'Usual price: 6 XP' );
		expect( usualPriceHint( { ...BOOK, book_xp: -6 } ) ).toBe(
			'Usual price: 6 XP'
		);
	} );
} );

describe( 'priceTextValid', () => {
	it( 'accepts blank and whole numbers up to 500, and nothing else', () => {
		expect( priceTextValid( '' ) ).toBe( true );
		expect( priceTextValid( ' ' ) ).toBe( true );
		expect( priceTextValid( '0' ) ).toBe( true );
		expect( priceTextValid( '500' ) ).toBe( true );
		expect( priceTextValid( '501' ) ).toBe( false );
		expect( priceTextValid( '2.5' ) ).toBe( false );
		expect( priceTextValid( 'free' ) ).toBe( false );
	} );
} );

describe( 'buildEditRequest', () => {
	it( 'sends only what changed, a price of 0 as 0, and the unit the box showed', () => {
		const c = change();
		const draft = { ...draftFor( c ), price: '0' };
		draft.fields = { ...draft.fields, note: 'R1, learned from the Regent' };

		expect( buildEditRequest( c, draft ) ).toEqual( {
			review_token: 'token',
			fields: { note: 'R1, learned from the Regent' },
			price: 0,
			price_per: 'pick',
		} );
	} );

	it( 'sends a count as a number, and keeps text the server must judge', () => {
		const c = change();
		const draft = draftFor( c );
		draft.fields = { ...draft.fields, count: '3' };
		expect( buildEditRequest( c, draft ).fields ).toEqual( { count: 3 } );

		draft.fields = { ...draft.fields, count: 'many' };
		expect( buildEditRequest( c, draft ).fields ).toEqual( {
			count: 'many',
		} );
	} );

	it( 'sends no price per when the box is empty, and a null price clears one', () => {
		const c = change();
		const request = buildEditRequest( c, draftFor( c ) );
		expect( request.price ).toBeNull();
		expect( request ).not.toHaveProperty( 'price_per' );
		expect( request ).not.toHaveProperty( 'fields' );
		expect( request ).not.toHaveProperty( 'review_notes' );
	} );

	it( 'prices a book price for the whole change', () => {
		const c = change( { price_basis: BOOK } );
		const draft = { ...draftFor( c ), price: '2' };
		expect( buildEditRequest( c, draft ) ).toMatchObject( {
			price: 2,
			price_per: 'change',
		} );
	} );

	it( 'sends the note only when it was touched, and an emptied note as an empty string', () => {
		const c = change( { review_notes: 'Keep this' } );
		const draft = draftFor( c );
		expect( buildEditRequest( c, draft ) ).not.toHaveProperty(
			'review_notes'
		);

		expect(
			buildEditRequest( c, { ...draft, note: 'Changed' } ).review_notes
		).toBe( 'Changed' );
		expect(
			buildEditRequest( c, { ...draft, note: '' } ).review_notes
		).toBe( '' );
	} );

	it( 'sends a move on its own, with no price', () => {
		const c = change();
		const draft = { ...draftFor( c ), price: '4' };
		draft.fields = {
			...draft.fields,
			block_slug: 'vampire-combo-disciplines',
		};

		expect( isMove( c, draft ) ).toBe( true );
		const request = buildEditRequest( c, draft );
		expect( request.fields ).toEqual( {
			block_slug: 'vampire-combo-disciplines',
		} );
		expect( request ).not.toHaveProperty( 'price' );
		expect( request ).not.toHaveProperty( 'price_per' );
	} );

	it( 'does not call an unchanged section a move', () => {
		const c = change();
		expect( isMove( c, draftFor( c ) ) ).toBe( false );
		expect( buildEditRequest( c, draftFor( c ) ).fields ).toBeUndefined();
	} );

	it( 'sends no price for a change that is not a priced purchase', () => {
		const c = change( {
			price_basis: null,
			editable_fields: [ 'amount', 'reason' ],
			change_data: { amount: 5, reason: 'x' },
		} );
		const draft = draftFor( c );
		draft.fields = { ...draft.fields, amount: '3' };
		const request = buildEditRequest( c, draft );
		expect( request.fields ).toEqual( { amount: 3 } );
		expect( request ).not.toHaveProperty( 'price' );
	} );
} );
