import { readFileSync } from 'fs';
import { join } from 'path';

/**
 * A My Plots row is a button holding a title and a date. A phone is narrower than a long title and its date side by
 * side, and the theme sets `white-space: nowrap` on buttons, so the row wraps and its title can break anywhere.
 */

const css = readFileSync(
	join( __dirname, 'MyPlotsFeed.css' ),
	'utf8'
).replace( /\/\*[\s\S]*?\*\//g, '' );

function body( selector: string ): string {
	const escaped = selector.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	const match = new RegExp( `${ escaped }\\s*\\{([^}]*)\\}` ).exec( css );
	return match ? match[ 1 ] : '';
}

describe( 'a My Plots row on a phone', () => {
	const row = body( '.be-my-plots__item-button.be-my-plots__item-button' );

	it( 'wraps its title and date onto separate lines when they do not fit', () => {
		expect( row ).toMatch( /flex-wrap\s*:\s*wrap/ );
	} );

	it( 'lets the text itself wrap despite the theme', () => {
		expect( row ).toMatch( /white-space\s*:\s*normal/ );
	} );

	it( 'lets a long title shrink and break', () => {
		const title = body( '.be-my-plots__title' );

		expect( title ).toMatch( /min-width\s*:\s*0/ );
		expect( title ).toMatch( /overflow-wrap\s*:\s*anywhere/ );
	} );
} );
