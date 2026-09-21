import { readFileSync } from 'fs';
import { join } from 'path';

/**
 * The Tradition field is Blood Magic only. It is meaningless on a Discipline, Gift,
 * Art or Arcanos - none of those are taught by a sorcery paradigm - yet every
 * tiered_power row rendered one, because only `needsTradition` (which controls the
 * *required* styling) ever consulted `definition.blood_magic`. The input itself was
 * gated on `! readOnly` alone, so a real player editing Auspex, Dominate, Fortitude
 * and Potence saw a "Tradition" box on all four (owner-reported live, 2026-09-21,
 * with a screenshot of `vampire-disciplines`).
 *
 * There is no React render harness in this repo, so this scans the source the way
 * `gridCollapse.test.ts` and `cssImports.test.ts` already do for defects that only
 * exist in markup. It asserts both surfaces stay gated: the desktop row and the
 * phone-width Details modal.
 */

const SOURCE = readFileSync(
	join( __dirname, 'TieredPowerEditor.tsx' ),
	'utf8'
);

/** Strips JSX comment blocks so a mention inside a comment can't satisfy an assertion. */
function withoutComments( src: string ): string {
	return src.replace( /\{\s*\/\*[\s\S]*?\*\/\s*\}/g, '' );
}

describe( 'Tradition is gated to blood_magic blocks', () => {
	const src = withoutComments( SOURCE );

	it( 'renders a Tradition input only inside a blood_magic gate', () => {
		// Every `placeholder`/`label` carrying the Tradition string must be preceded,
		// somewhere above it in the same render, by a blood_magic guard.
		const occurrences = [ ...src.matchAll( /'Tradition'/g ) ].map(
			( m ) => m.index ?? 0
		);
		expect( occurrences.length ).toBeGreaterThan( 0 );

		for ( const at of occurrences ) {
			const before = src.slice( 0, at );
			const lastGate = before.lastIndexOf( 'definition.blood_magic ??' );
			expect( lastGate ).toBeGreaterThan( -1 );

			// The gate must be a render guard - `&& (` - not merely the aria-required
			// prop, which reads the same flag but never controls whether it mounts.
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
		// The remove button shares the row-detail container; gating the whole
		// container would take Remove away from every non-blood-magic block.
		expect( src ).toMatch( /be-tiered-power-editor__remove/ );
		const removeAt = src.indexOf( 'be-tiered-power-editor__remove' );
		const traditionAt = src.indexOf( "'Tradition'" );
		expect( removeAt ).toBeGreaterThan( traditionAt );
	} );
} );
