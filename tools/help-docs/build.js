/**
 * Builds what goes to beyondelysium.com for every help page and guide: the English blocks, the screenshots each page
 * follows, and the Portuguese pairs, with the problems found on the way.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const {
	GUIDE_SLUGS,
	rewriteLinks,
	extractTitle,
	headingAnchors,
	convertBlocks,
} = require( './convert' );
const { planTranslations } = require( './pairing' );

/**
 * Every page that goes to the website: its slug, whether it is a guide, and where its English and Portuguese
 * Markdown live.
 *
 * @param {string} docsDir
 */
function sources( docsDir ) {
	const helpDir = path.join( docsDir, 'help' );
	const portugueseDir = path.join( docsDir, 'pt_BR' );
	const keys = fs
		.readdirSync( helpDir )
		.filter( ( file ) => file.endsWith( '.md' ) )
		.map( ( file ) => path.basename( file, '.md' ) );
	const help = keys.map( ( key ) => ( {
		slug: key,
		guide: false,
		english: path.join( helpDir, `${ key }.md` ),
		portuguese: path.join( portugueseDir, 'help', `${ key }.md` ),
	} ) );
	const guides = Object.entries( GUIDE_SLUGS ).map( ( [ base, slug ] ) => ( {
		slug,
		guide: true,
		english: path.join( docsDir, `${ base }.md` ),
		portuguese: path.join( portugueseDir, `${ base }.md` ),
	} ) );
	return { keys, pages: [ ...help, ...guides ] };
}

/**
 * The screenshots declared for a page, each with the block it follows.
 *
 * @param {string}   slug
 * @param {Object[]} blocks
 * @param {Object}   manifest
 * @param {string[]} problems
 */
function shotsFor( slug, blocks, manifest, problems ) {
	return ( manifest.pages[ slug ] || [] ).map( ( shot, index ) => {
		const afterBlock = blocks.findIndex(
			( block ) => block.heading === shot.heading
		);
		if ( afterBlock === -1 ) {
			problems.push( `${ slug }: no heading "${ shot.heading }"` );
		}
		return { number: index + 1, afterBlock, alt: shot.alt };
	} );
}

/**
 * Reads every page and pairs its English and Portuguese blocks.
 *
 * @param {string} docsDir The plugin's docs folder.
 * @return {{docs: Object[], unresolved: Object[], shotProblems: string[], conflicts: Object[]}} Each page, then what went wrong.
 */
function buildDocs( docsDir ) {
	const { keys, pages: sourcePages } = sources( docsDir );
	const allSlugs = new Set( keys );
	const manifest = JSON.parse(
		fs.readFileSync(
			path.join( docsDir, 'help', 'screenshots.json' ),
			'utf8'
		)
	);
	const shotProblems = [];
	const unresolved = [];

	const read = ( file ) => fs.readFileSync( file, 'utf8' );
	const hasPortuguese = ( page ) => fs.existsSync( page.portuguese );

	const anchors = { en: {}, pt_BR: {} };
	for ( const page of sourcePages ) {
		anchors.en[ page.slug ] = headingAnchors( read( page.english ) );
		if ( hasPortuguese( page ) ) {
			anchors.pt_BR[ page.slug ] = headingAnchors(
				read( page.portuguese )
			);
		}
	}

	const render = ( page, file, language ) => {
		const { rewritten, unresolved: missed } = rewriteLinks(
			read( file ),
			allSlugs,
			page.guide,
			anchors[ language ],
			page.slug
		);
		if ( missed.length ) {
			unresolved.push( {
				file: path.basename( file ),
				language,
				missed,
			} );
		}
		return {
			title: extractTitle( rewritten ),
			blocks: convertBlocks( rewritten ),
		};
	};

	const rendered = sourcePages.map( ( page ) => ( {
		page,
		english: render( page, page.english, 'en' ),
		portuguese: hasPortuguese( page )
			? render( page, page.portuguese, 'pt_BR' )
			: null,
	} ) );

	const plan = planTranslations(
		rendered
			.filter( ( entry ) => entry.portuguese )
			.map( ( entry ) => ( {
				slug: entry.page.slug,
				english: entry.english.blocks,
				portuguese: entry.portuguese.blocks,
			} ) )
	);
	const planned = new Map(
		plan.pages.map( ( result ) => [ result.slug, result ] )
	);

	const docs = rendered.map( ( entry ) => {
		const result = planned.get( entry.page.slug );
		const paired = result && result.problems.length === 0;
		return {
			slug: entry.page.slug,
			title: entry.english.title,
			blocks: entry.english.blocks.map( ( block ) => block.html ),
			shots: entry.page.guide
				? []
				: shotsFor(
						entry.page.slug,
						entry.english.blocks,
						manifest,
						shotProblems
					),
			portuguese: paired
				? { title: entry.portuguese.title, pairs: result.pairs }
				: null,
			unpaired: result
				? result.problems
				: [ 'no Portuguese translation' ],
		};
	} );

	return { docs, unresolved, shotProblems, conflicts: plan.conflicts };
}

module.exports = { buildDocs };
