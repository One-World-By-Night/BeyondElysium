import { readFileSync, readdirSync } from 'fs';
import { join, relative } from 'path';
import { parse } from '@babel/parser';
import type { ParserPlugin } from '@babel/parser';
import postcss from 'postcss';

/**
 * Comments describe what the code is. No TypeScript, JavaScript or stylesheet comment names a version, decision,
 * finding, plan step, process document or date; the rules are shared with
 * `tests/unit/CommentsDescribeTheCodeTest.php`.
 */
const ROOT = join( __dirname, '../..' );

interface Rule {
	description: string;
	pattern: string;
	flags: string;
}

const RULES: Array< { description: string; expression: RegExp } > = (
	JSON.parse(
		readFileSync(
			join( ROOT, 'tests/support/comment-reference-rules.json' ),
			'utf-8'
		)
	).rules as Rule[]
 ).map( ( rule ) => ( {
	description: rule.description,
	expression: new RegExp( rule.pattern, rule.flags ),
} ) );

const SKIP_DIRS = [ 'node_modules', 'build', 'dist', 'vendor' ];

function walk( dir: string, extensions: RegExp ): string[] {
	return readdirSync( dir, { withFileTypes: true } ).flatMap( ( entry ) => {
		const path = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			return SKIP_DIRS.includes( entry.name )
				? []
				: walk( path, extensions );
		}
		return extensions.test( entry.name ) ? [ path ] : [];
	} );
}

type Comment = [ number, string ];

function scriptComments( path: string ): Comment[] {
	const plugins: ParserPlugin[] = path.endsWith( '.ts' )
		? [ 'typescript' ]
		: [ 'typescript', 'jsx' ];
	const ast = parse( readFileSync( path, 'utf-8' ), {
		sourceType: 'unambiguous',
		plugins,
	} );
	return ( ast.comments ?? [] ).map( ( comment ) => [
		comment.loc?.start.line ?? 0,
		comment.value,
	] );
}

function styleComments( path: string ): Comment[] {
	const found: Comment[] = [];
	postcss
		.parse( readFileSync( path, 'utf-8' ) )
		.walkComments( ( comment ) => {
			found.push( [ comment.source?.start?.line ?? 0, comment.text ] );
		} );
	return found;
}

const files = [
	...walk( join( ROOT, 'src' ), /\.(tsx?|css)$/ ),
	...walk( join( ROOT, 'bin' ), /\.js$/ ),
	...[ '.eslintrc.js', '.stylelintrc.js', 'webpack.config.js' ].map(
		( name ) => join( ROOT, name )
	),
];

describe( 'comments describe the code', () => {
	it( 'name no version, decision, finding, step, process document or date', () => {
		const offences: string[] = [];

		for ( const file of files ) {
			const comments = file.endsWith( '.css' )
				? styleComments( file )
				: scriptComments( file );

			for ( const [ line, text ] of comments ) {
				for ( const { description, expression } of RULES ) {
					const match = expression.exec( text );
					if ( match ) {
						offences.push(
							`${ relative(
								ROOT,
								file
							) }:${ line }: ${ description } - "${ match[ 0 ] }"`
						);
					}
				}
			}
		}

		expect( files.length ).toBeGreaterThan( 200 );
		expect( offences ).toEqual( [] );
	} );
} );
