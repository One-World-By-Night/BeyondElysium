import { describe, expect, it } from 'vitest';
import { listBoxStyle } from './searchableSelectLayout';

const below = { top: 300, left: 70, width: 400, openUpward: false };
const above = { top: 840, left: 70, width: 400, openUpward: true };

describe( 'listBoxStyle', () => {
	it( 'hangs a downward list from the input with its bottom left open', () => {
		expect( listBoxStyle( below, 900, false, 0 ) ).toEqual( {
			position: 'fixed',
			top: 300,
			bottom: 'auto',
			left: 70,
			width: 400,
		} );
	} );

	it( 'stands an upward list on the input with its top released', () => {
		const style = listBoxStyle( above, 900, false, 0 );
		expect( style.top ).toBe( 'auto' );
		expect( style.bottom ).toBe( 60 );
		expect( style.position ).toBe( 'fixed' );
	} );

	it( 'never sets both edges to numbers, so a stylesheet inset cannot squash the list', () => {
		for ( const rect of [ below, above ] ) {
			const style = listBoxStyle( rect, 900, false, 0 );
			expect(
				typeof style.top === 'number' &&
					typeof style.bottom === 'number'
			).toBe( false );
		}
	} );

	it( 'gives a long list its own height and scrolling', () => {
		expect( listBoxStyle( below, 900, true, 280 ) ).toMatchObject( {
			height: 280,
			overflowY: 'auto',
		} );
		expect( listBoxStyle( below, 900, false, 280 ) ).not.toHaveProperty(
			'height'
		);
	} );
} );
