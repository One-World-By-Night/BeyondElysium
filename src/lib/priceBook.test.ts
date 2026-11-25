import { PriceBook } from './priceBook';
import type {
	ChangePreviewResult,
	ChangeRequest,
	PreviewChangesResponse,
} from '../types/character';

function change( value: string ): ChangeRequest {
	return {
		change_type: 'add_trait',
		category: 'trait',
		change_data: { block_slug: 'abilities', trait: { name: value } },
	} as ChangeRequest;
}

function nameOf( request: ChangeRequest ): string {
	return ( request.change_data as unknown as { trait: { name: string } } )
		.trait.name;
}

function quoteFor( value: string ): ChangePreviewResult {
	return {
		xp_cost: value.length,
		approval_level: 'auto',
		approval_reason: null,
		priced: true,
	};
}

function book(
	overrides: Partial< ConstructorParameters< typeof PriceBook >[ 0 ] > = {}
) {
	const calls: string[][] = [];
	const preview = fakePreview( calls );
	return {
		calls,
		priceBook: new PriceBook( {
			preview,
			changeFor: change,
			isVariable: () => false,
			...overrides,
		} ),
	};
}

function fakePreview( calls: string[][] ) {
	return ( changes: ChangeRequest[] ): Promise< PreviewChangesResponse > => {
		const names = changes.map( nameOf );
		calls.push( names );
		return Promise.resolve( {
			results: names.map( quoteFor ),
			running_xp_unspent: 0,
		} );
	};
}

const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

describe( 'PriceBook', () => {
	it( 'has no hint until a price arrives, then the price', async () => {
		const { priceBook } = book();
		expect( priceBook.hint( 'Occult' ) ).toBeNull();
		priceBook.request( [ 'Occult' ] );
		await settle();
		expect( priceBook.hint( 'Occult' ) ).toBe( '6 XP' );
	} );

	it( 'asks for each name once', async () => {
		const { priceBook, calls } = book();
		priceBook.request( [ 'Occult', 'Etiquette' ] );
		priceBook.request( [ 'Occult' ] );
		await settle();
		priceBook.request( [ 'Occult', 'Etiquette' ] );
		await settle();
		expect( calls ).toEqual( [ [ 'Occult', 'Etiquette' ] ] );
	} );

	it( 'asks for no more than 40 names in a request', async () => {
		const { priceBook, calls } = book();
		const names = Array.from( { length: 85 }, ( _, i ) => `trait ${ i }` );
		priceBook.request( names );
		await settle();
		expect( calls.map( ( names2 ) => names2.length ) ).toEqual( [
			40, 40, 5,
		] );
	} );

	it( 'does not ask again for a name whose request is still out', async () => {
		const { priceBook, calls } = book();
		priceBook.request( [ 'Occult' ] );
		priceBook.request( [ 'Occult' ] );
		await settle();
		expect( calls ).toHaveLength( 1 );
	} );

	it( 'skips a name that has no change to price, without a request', async () => {
		const { priceBook, calls } = book( {
			changeFor: ( value ) =>
				value === 'Held' ? null : change( value ),
		} );
		priceBook.request( [ 'Held' ] );
		await settle();
		expect( calls ).toEqual( [] );
		expect( priceBook.hint( 'Held' ) ).toBeNull();
	} );

	it( 'reads a variable-cost item as "from"', async () => {
		const { priceBook } = book( {
			isVariable: ( value ) => value === 'Bond',
		} );
		priceBook.request( [ 'Bond', 'Occult' ] );
		await settle();
		expect( priceBook.hint( 'Bond' ) ).toBe( 'from 4 XP' );
		expect( priceBook.hint( 'Occult' ) ).toBe( '6 XP' );
	} );

	it( 'asks again after a reset', async () => {
		const { priceBook, calls } = book();
		priceBook.request( [ 'Occult' ] );
		await settle();
		priceBook.reset();
		expect( priceBook.hint( 'Occult' ) ).toBeNull();
		priceBook.request( [ 'Occult' ] );
		await settle();
		expect( calls ).toHaveLength( 2 );
	} );

	it( 'ignores a price that arrives after a reset', async () => {
		let release: ( value: PreviewChangesResponse ) => void = () =>
			undefined;
		const priceBook = new PriceBook( {
			preview: () =>
				new Promise< PreviewChangesResponse >( ( resolve ) => {
					release = resolve;
				} ),
			changeFor: change,
		} );
		priceBook.request( [ 'Occult' ] );
		priceBook.reset();
		release( {
			results: [ quoteFor( 'Occult' ) ],
			running_xp_unspent: 0,
		} );
		await settle();
		expect( priceBook.hint( 'Occult' ) ).toBeNull();
	} );

	it( 'shows no hint and does not retry when a request fails', async () => {
		const preview = failingPreview();
		const priceBook = new PriceBook( { preview, changeFor: change } );
		priceBook.request( [ 'Occult' ] );
		await settle();
		priceBook.request( [ 'Occult' ] );
		await settle();
		expect( priceBook.hint( 'Occult' ) ).toBeNull();
		expect( preview.calls ).toBe( 1 );
	} );

	it( 'tells a listener when a price arrives', async () => {
		const { priceBook } = book();
		let heard = 0;
		const stop = priceBook.subscribe( () => {
			heard += 1;
		} );
		priceBook.request( [ 'Occult' ] );
		await settle();
		expect( heard ).toBe( 1 );
		stop();
		priceBook.request( [ 'Etiquette' ] );
		await settle();
		expect( heard ).toBe( 1 );
	} );
} );

function failingPreview() {
	const fn = (): Promise< PreviewChangesResponse > => {
		fn.calls += 1;
		return Promise.reject( new Error( 'down' ) );
	};
	fn.calls = 0;
	return fn;
}
