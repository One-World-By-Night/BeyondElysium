import { readdirSync, readFileSync, statSync } from 'fs';
import { join } from 'path';

/**
 * A `position: sticky; bottom: 0` element is pinned over the page it belongs to, so
 * anything that makes it tall covers content the user is trying to read. D74 was this:
 * `CharacterEditor`'s pending-changes panel had one `<li>` per queued change and no
 * height cap, so 7-8 changes obscured 37-68% of the viewport and cut into the trait
 * editors above (owner-reported live, 2026-09-21, while editing their own sheet).
 *
 * It was the second instance, not the first - `PlotManager`'s action bar hit the same
 * pattern earlier and got a `padding-bottom` reserve, which fixes short-page flow
 * reservation but does nothing about a tall bar. Meanwhile eight other panels in this
 * codebase already pair `max-height` with `overflow-y`, so the fix was well established
 * and simply never applied here.
 *
 * This scans the real stylesheet tree so a third instance fails the build instead of
 * shipping. Same approach as `gridCollapse.test.ts` and `cssImports.test.ts`, which
 * guard other defects that exist only in markup and CSS.
 */

function findCssFiles( dir: string, out: string[] ): void {
	for ( const entry of readdirSync( dir ) ) {
		const full = join( dir, entry );
		if ( statSync( full ).isDirectory() ) {
			findCssFiles( full, out );
			continue;
		}
		if ( entry.endsWith( '.css' ) ) {
			out.push( full );
		}
	}
}

/** Strips comments so a mention inside one can't satisfy or trip an assertion. */
function withoutComments( css: string ): string {
	return css.replace( /\/\*[\s\S]*?\*\//g, '' );
}

/**
 * Splits a stylesheet into its rule bodies, keyed by selector. Good enough for flat
 * component CSS; this codebase nests only inside media queries, whose inner rules are
 * captured as their own bodies by the same pass.
 */
function ruleBodies(
	css: string
): Array< { selector: string; body: string } > {
	const out: Array< { selector: string; body: string } > = [];
	const re = /([^{}]+)\{([^{}]*)\}/g;
	let m: RegExpExecArray | null;
	while ( ( m = re.exec( css ) ) !== null ) {
		out.push( { selector: m[ 1 ].trim(), body: m[ 2 ] } );
	}
	return out;
}

const files: string[] = [];
findCssFiles( join( __dirname, '..' ), files );

describe( 'a sticky bottom bar always caps its own height (D74)', () => {
	it( 'finds stylesheets to scan', () => {
		expect( files.length ).toBeGreaterThan( 20 );
	} );

	it( 'every `position: sticky` + `bottom: 0` rule carries a height cap', () => {
		const offenders: string[] = [];

		for ( const file of files ) {
			const css = withoutComments( readFileSync( file, 'utf8' ) );
			for ( const { selector, body } of ruleBodies( css ) ) {
				const sticky = /position\s*:\s*sticky/.test( body );
				const pinnedToBottom = /bottom\s*:\s*0(?:px|rem|%)?\s*;/.test(
					body
				);
				if ( ! sticky || ! pinnedToBottom ) {
					continue;
				}
				const capped =
					/max-height\s*:/.test( body ) &&
					/overflow-y\s*:\s*(auto|scroll)/.test( body );
				if ( ! capped ) {
					offenders.push(
						`${ file.split( '/src/' ).pop() } :: ${ selector }`
					);
				}
			}
		}

		expect( offenders ).toEqual( [] );
	} );
} );
