import { readdirSync, readFileSync, statSync } from 'fs';
import { join, relative } from 'path';
import ts from 'typescript';

/**
 * A table cell keeps its table-cell display. Giving a `td` or `th` another display takes it out of the table: the
 * browser wraps it in an anonymous cell, so a theme that borders or stripes cells draws the real one as a box that is
 * shorter than its row and offset from it.
 */

function files( dir: string, extension: string, out: string[] ): string[] {
	for ( const entry of readdirSync( dir ) ) {
		const full = join( dir, entry );
		if ( statSync( full ).isDirectory() ) {
			files( full, extension, out );
		} else if (
			entry.endsWith( extension ) &&
			! entry.includes( '.test.' )
		) {
			out.push( full );
		}
	}
	return out;
}

/**
 * The class names written on a `td` or `th` element in a component's source.
 */
function cellClasses( source: string ): string[] {
	const sourceFile = ts.createSourceFile(
		'component.tsx',
		source,
		ts.ScriptTarget.Latest,
		true,
		ts.ScriptKind.TSX
	);
	const found = new Set< string >();

	const visit = ( node: ts.Node ): void => {
		if (
			( ts.isJsxOpeningElement( node ) ||
				ts.isJsxSelfClosingElement( node ) ) &&
			[ 'td', 'th' ].includes( node.tagName.getText( sourceFile ) )
		) {
			for ( const attribute of node.attributes.properties ) {
				if (
					ts.isJsxAttribute( attribute ) &&
					attribute.name.getText( sourceFile ) === 'className' &&
					attribute.initializer
				) {
					for ( const match of attribute.initializer
						.getText( sourceFile )
						.matchAll( /[A-Za-z_][\w-]*/g ) ) {
						found.add( match[ 0 ] );
					}
				}
			}
		}
		ts.forEachChild( node, visit );
	};
	visit( sourceFile );
	return [ ...found ];
}

/**
 * The rules written outside any at-rule, each with its selector and body.
 */
function topLevelRules(
	css: string
): Array< { selector: string; body: string } > {
	const text = css.replace( /\/\*[\s\S]*?\*\//g, '' );
	const rules: Array< { selector: string; body: string } > = [];
	let depth = 0;
	let selector = '';
	let bodyStart = 0;
	let inAtRule = false;

	for ( let i = 0; i < text.length; i++ ) {
		const character = text[ i ];
		if ( character === '{' ) {
			if ( depth === 0 ) {
				inAtRule = selector.trim().startsWith( '@' );
				bodyStart = i + 1;
			}
			depth++;
		} else if ( character === '}' ) {
			depth--;
			if ( depth === 0 ) {
				if ( ! inAtRule ) {
					rules.push( {
						selector: selector.trim(),
						body: text.slice( bodyStart, i ),
					} );
				}
				selector = '';
			}
		} else if ( depth === 0 ) {
			selector += character;
		}
	}
	return rules;
}

/**
 * The selectors in a stylesheet that give a table cell a display of its own.
 */
function cellDisplayOffenders( css: string, classes: string[] ): string[] {
	const offenders: string[] = [];

	for ( const { selector, body } of topLevelRules( css ) ) {
		const display = body.match( /(?:^|[;\s])display\s*:\s*([^;}\s]+)/ );
		if ( ! display || [ 'table-cell', 'none' ].includes( display[ 1 ] ) ) {
			continue;
		}
		const compounds = selector.split( ',' ).map(
			( one ) =>
				one
					.trim()
					.split( /\s*[\s>+~]\s*/ )
					.pop() ?? ''
		);
		// A rule that makes the table and its rows the same display as its cells flattens the whole table.
		if (
			compounds.some( ( compound ) =>
				/^(table|tr)(?![\w-])/.test( compound )
			)
		) {
			continue;
		}

		for ( const one of selector.split( ',' ) ) {
			const compound =
				one
					.trim()
					.split( /\s*[\s>+~]\s*/ )
					.pop() ?? '';
			if ( compound.includes( '::' ) ) {
				continue;
			}
			const onCell =
				/^(td|th)(?![\w-])/.test( compound ) ||
				[ ...compound.matchAll( /\.([A-Za-z_][\w-]*)/g ) ].some(
					( match ) => classes.includes( match[ 1 ] )
				);
			if ( onCell ) {
				offenders.push(
					`${ one.trim() } { display: ${ display[ 1 ] } }`
				);
			}
		}
	}
	return offenders;
}

describe( 'cellDisplayOffenders', () => {
	it( 'flags a cell class or a td selector with a display that is not a table cell', () => {
		const css = `
			.pad { display: flex; }
			.row td { display: block; }
			.cell, .other { display: grid; }
		`;
		expect( cellDisplayOffenders( css, [ 'cell' ] ) ).toEqual( [
			'.row td { display: block }',
			'.cell { display: grid }',
		] );
	} );

	it( 'leaves a cell that is hidden, a table cell, or restyled inside an at-rule alone', () => {
		const css = `
			.cell { display: none; }
			.cell { display: table-cell; }
			@container be-table ( width < 960px ) {
				.cell { display: block; }
			}
		`;
		expect( cellDisplayOffenders( css, [ 'cell' ] ) ).toEqual( [] );
	} );

	it( 'leaves alone a rule that makes the table, its rows and its cells one display together', () => {
		const css = `
			.panel table,
			.panel tr,
			.panel td { display: block; }
		`;
		expect( cellDisplayOffenders( css, [] ) ).toEqual( [] );
	} );

	it( 'reads the classes a cell carries, however its attributes are written', () => {
		const source = `
			const row = (
				<tr>
					<td
						className="be-a"
						onClick={ () => go() }
					>
						x
					</td>
					<th className={ \`be-b \${ open ? 'be-c' : '' }\` }>y</th>
					<div className="be-not-a-cell" />
				</tr>
			);
		`;
		expect( cellClasses( source ) ).toEqual(
			expect.arrayContaining( [ 'be-a', 'be-b', 'be-c' ] )
		);
		expect( cellClasses( source ) ).not.toContain( 'be-not-a-cell' );
	} );
} );

describe( 'every table cell in a component stylesheet is a table cell', () => {
	const root = join( __dirname, '..' );

	it( 'finds the cells and stylesheets it checks', () => {
		const classes = files( root, '.tsx', [] ).flatMap( ( file ) =>
			cellClasses( readFileSync( file, 'utf8' ) )
		);
		expect( classes ).toContain( 'be-approval-queue__actions' );
		expect( files( root, '.css', [] ).length ).toBeGreaterThan( 20 );
	} );

	it( 'no class on a td or th, and no td or th selector, sets another display', () => {
		const classes = [
			...new Set(
				files( root, '.tsx', [] ).flatMap( ( file ) =>
					cellClasses( readFileSync( file, 'utf8' ) )
				)
			),
		];
		const offenders = files( root, '.css', [] ).flatMap( ( file ) =>
			cellDisplayOffenders( readFileSync( file, 'utf8' ), classes ).map(
				( offender ) => `${ relative( root, file ) } :: ${ offender }`
			)
		);

		expect( offenders ).toEqual( [] );
	} );
} );
