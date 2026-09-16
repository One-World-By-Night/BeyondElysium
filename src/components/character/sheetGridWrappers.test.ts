import { readFileSync } from 'fs';
import { join } from 'path';

/**
 * Owner, 2026-09-15: the sheet came out "4 columns here, 1 there". Print groups Physical, Social,
 * and Mental Traits into three columns, and the wrappers that grouping needs sat in the on-screen
 * grid as one narrow track each - three sixths of a row for six sections meant to fill two rows
 * of thirds. On screen every wrapper between the grid and its sections has to step aside, and
 * each section keeps the place its template gives it, whatever wrapper holds it.
 */
const css = readFileSync( join( __dirname, 'CharacterSheet.css' ), 'utf8' );
const tsx = readFileSync( join( __dirname, 'CharacterSheet.tsx' ), 'utf8' );

/** The stylesheet without comments and without its `@media print` block. */
function screenRules(): string {
	const source = css.replace( /\/\*[\s\S]*?\*\//g, '' );
	const start = source.indexOf( '@media print' );
	if ( start === -1 ) {
		return source;
	}
	let depth = 0;
	let end = source.indexOf( '{', start );
	for ( let i = end; i < source.length; i++ ) {
		if ( source[ i ] === '{' ) {
			depth++;
		} else if ( source[ i ] === '}' ) {
			depth--;
			if ( depth === 0 ) {
				end = i;
				break;
			}
		}
	}
	return source.slice( 0, start ) + source.slice( end + 1 );
}

/** The declarations every rule naming `className` sets on screen, run together. */
function declarationsFor( className: string, rules: string ): string {
	const found: string[] = [];
	const rule = /([^{}]+)\{([^{}]*)\}/g;
	let match: RegExpExecArray | null;
	while ( ( match = rule.exec( rules ) ) !== null ) {
		const selectors = match[ 1 ].split( ',' ).map( ( s ) => s.trim() );
		if ( selectors.includes( `.${ className }` ) ) {
			found.push( match[ 2 ] );
		}
	}
	return found.join( ';' );
}

test( 'every wrapper around the sheet sections leaves the on-screen grid to the sections', () => {
	const wrappers = [
		...tsx.matchAll(
			/className="(be-character-sheet__attribute[a-z-]*)"/g
		),
	].map( ( m ) => m[ 1 ] );
	expect( wrappers ).toEqual( [
		'be-character-sheet__attributes',
		'be-character-sheet__attribute-column',
	] );

	const rules = screenRules();
	for ( const wrapper of wrappers ) {
		expect( declarationsFor( wrapper, rules ) ).toMatch(
			/display:\s*contents/
		);
	}
} );

test( 'each section keeps its template place in the grid, whichever wrapper holds it', () => {
	expect( tsx ).toMatch(
		/gridColumn:\s*`span \$\{\s*spanFor\(\s*section\.width\s*\)\s*\}`,\s*order:\s*sections\.indexOf\(\s*section\s*\)/
	);
} );

test( 'print still lays the three trait columns side by side', () => {
	const print = css
		.replace( /\/\*[\s\S]*?\*\//g, '' )
		.slice(
			css.replace( /\/\*[\s\S]*?\*\//g, '' ).indexOf( '@media print' )
		);
	expect(
		declarationsFor( 'be-character-sheet__attribute-column', print )
	).toMatch(
		/display:\s*block[\s\S]*float:\s*left|float:\s*left[\s\S]*display:\s*block/
	);
} );
