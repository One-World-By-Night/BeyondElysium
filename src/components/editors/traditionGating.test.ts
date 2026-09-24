import { readFileSync } from 'fs';
import { join } from 'path';

/**
 * The Tradition field is Blood Magic only.
 */

const SOURCE = readFileSync(
	join( __dirname, 'TieredPowerEditor.tsx' ),
	'utf8'
);

/**
 * Strips JSX comment blocks.
 */
function withoutComments( src: string ): string {
	return src.replace( /\{\s*\/\*[\s\S]*?\*\/\s*\}/g, '' );
}

describe( 'Tradition is gated to blood_magic blocks', () => {
	const src = withoutComments( SOURCE );

	it( 'renders a Tradition input only inside a blood_magic gate', () => {
		const occurrences = [ ...src.matchAll( /'Tradition'/g ) ].map(
			( m ) => m.index ?? 0
		);
		expect( occurrences.length ).toBeGreaterThan( 0 );

		for ( const at of occurrences ) {
			const before = src.slice( 0, at );
			const lastGate = before.lastIndexOf( 'definition.blood_magic ??' );
			expect( lastGate ).toBeGreaterThan( -1 );

			const between = before.slice( lastGate );
			expect( between ).toMatch( /false\s*\)\s*&&\s*\(/ );
		}
	} );

	it( 'gates both surfaces: the desktop row and the Details modal', () => {
		const guards = [
			...src.matchAll(
				/definition\.blood_magic \?\?\s*false\s*\)\s*&&/g
			),
		];
		expect( guards.length ).toBeGreaterThanOrEqual( 2 );
	} );

	it( 'still keeps Remove reachable outside the gate', () => {
		// The remove button shares the row-detail container.
		expect( src ).toMatch( /be-tiered-power-editor__remove/ );
		const removeAt = src.indexOf( 'be-tiered-power-editor__remove' );
		const traditionAt = src.indexOf( "'Tradition'" );
		expect( removeAt ).toBeGreaterThan( traditionAt );
	} );
} );
