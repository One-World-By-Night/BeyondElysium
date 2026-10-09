import { describe, expect, it } from 'vitest';
import api from './client';

describe( 'reports().pdfUrl() conditions', () => {
	it( 'omits the conditions param when none are given', () => {
		const url = api.reports( 'kony' ).pdfUrl( 'item-cards' );
		expect( url ).not.toContain( 'conditions=' );
	} );

	it( 'serializes a conditions array as JSON in the query string', () => {
		const url = api.reports( 'kony' ).pdfUrl( 'item-cards', {
			conditions: [
				{ field: 'name', operator: 'equals', value: 'Locket' },
			],
		} );
		const params = new URL( url ).searchParams;
		expect( JSON.parse( params.get( 'conditions' ) ?? '[]' ) ).toEqual( [
			{ field: 'name', operator: 'equals', value: 'Locket' },
		] );
	} );
} );

describe( 'reports().pdfUrl() objectId', () => {
	it( 'omits object_id when none is given', () => {
		const url = api.reports( 'kony' ).pdfUrl( 'item-cards' );
		expect( url ).not.toContain( 'object_id=' );
	} );

	it( 'scopes an item card to one object by id', () => {
		const url = api
			.reports( 'kony' )
			.pdfUrl( 'item-cards', { objectId: 42 } );
		expect( url ).toContain( '/reports/item-cards/pdf' );
		expect( new URL( url ).searchParams.get( 'object_id' ) ).toBe( '42' );
		expect( url ).not.toContain( 'conditions=' );
	} );

	it( 'scopes a location card the same way', () => {
		const url = api.reports( 'kony' ).pdfUrl( 'location-cards', {
			objectId: 7,
		} );
		expect( url ).toContain( '/reports/location-cards/pdf' );
		expect( new URL( url ).searchParams.get( 'object_id' ) ).toBe( '7' );
	} );
} );
