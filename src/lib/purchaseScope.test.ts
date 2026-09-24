import {
	PURCHASE_AREAS,
	purchaseScopeChange,
	readPurchaseScope,
} from './purchaseScope';

describe( 'readPurchaseScope', () => {
	it( 'reads every area as off when nothing is stored', () => {
		const off = {
			abilities: false,
			backgrounds: false,
			merits_flaws: false,
		};

		expect( readPurchaseScope( undefined ) ).toEqual( off );
		expect( readPurchaseScope( null ) ).toEqual( off );
		expect( readPurchaseScope( 'abilities' ) ).toEqual( off );
	} );

	it( 'reads an area as on only when it is stored as true', () => {
		expect(
			readPurchaseScope( {
				abilities: true,
				backgrounds: 'true',
				merits_flaws: 1,
			} )
		).toEqual( {
			abilities: true,
			backgrounds: false,
			merits_flaws: false,
		} );
	} );

	it( 'ignores an area it does not know', () => {
		expect(
			readPurchaseScope( { combat: true, abilities: true } )
		).toEqual( {
			abilities: true,
			backgrounds: false,
			merits_flaws: false,
		} );
	} );

	it( 'lets each area be on with the others off, in any combination', () => {
		for ( const area of PURCHASE_AREAS ) {
			const read = readPurchaseScope( { [ area ]: true } );
			expect( Object.values( read ).filter( Boolean ) ).toHaveLength( 1 );
			expect( read[ area ] ).toBe( true );
		}
	} );
} );

describe( 'purchaseScopeChange', () => {
	it( 'saves only the area being switched', () => {
		expect( purchaseScopeChange( 'abilities', true ) ).toEqual( {
			purchase_scope: { abilities: true },
		} );
		expect( purchaseScopeChange( 'merits_flaws', false ) ).toEqual( {
			purchase_scope: { merits_flaws: false },
		} );
	} );
} );
