/**
 * Turns a help page's Markdown into the Gutenberg block HTML beyondelysium.com's BetterDocs "docs" post type holds,
 * one block per top-level Markdown element.
 */
const { marked } = require( 'marked' );

const SITE = 'https://beyondelysium.com';

// The four top-level guides live one directory up from docs/help/.
const GUIDE_SLUGS = {
	'admin-guide': 'admin-guide',
	'player-guide': 'player-guide',
	'st-guide': 'storyteller-guide',
	'rest-api': 'rest-api-reference',
};

/**
 * A heading's words without their Markdown.
 *
 * @param {string} markdown
 */
function headingWords( markdown ) {
	return markdown
		.replace( /!?\[([^\]]*)\]\([^)]*\)/g, '$1' )
		.replace( /[*_`~]/g, '' )
		.trim();
}

/**
 * A heading's anchor the way GitHub makes one.
 *
 * @param {string} text
 */
function headingSlug( text ) {
	return text
		.toLowerCase()
		.replace( /[^\p{L}\p{M}\p{N}\p{Pc}\- ]/gu, '' )
		.replace( / /g, '-' );
}

/**
 * The anchor of every heading in a Markdown document, in order.
 *
 * @param {string} markdown
 */
function headingAnchors( markdown ) {
	const seen = new Map();
	return marked
		.lexer( markdown )
		.filter( ( token ) => token.type === 'heading' )
		.map( ( token ) => {
			const base = headingSlug( headingWords( token.text ) );
			const count = seen.get( base ) ?? 0;
			seen.set( base, count + 1 );
			return count ? `${ base }-${ count }` : base;
		} );
}

/**
 * The id the website gives the heading at this place in a page: BetterDocs numbers every heading of a page in order.
 *
 * @param {number} index
 */
function headingId( index ) {
	return `${ index }-toc-title`;
}

/**
 * Resolves a source file's own markdown links to live site URLs, and each heading anchor to the id the website gives
 * that heading.
 *
 * @param {string}                    markdown
 * @param {Set<string>}               allSlugs
 * @param {boolean}                   isGuide
 * @param {Object<string, string[]>=} anchors  Each site page's heading anchors, by page slug.
 * @param {string=}                   ownSlug  The page being rewritten, for links to its own headings.
 */
function rewriteLinks(
	markdown,
	allSlugs,
	isGuide = false,
	anchors = {},
	ownSlug = ''
) {
	const unresolved = [];
	const idOf = ( slug, anchor ) => {
		const index = ( anchors[ slug ] || [] ).indexOf( anchor );
		return index === -1 ? null : headingId( index );
	};
	const rewritten = markdown
		.replace(
			/\]\((\.\.\/|help\/)?([a-z0-9-]+)\.md(#[^)]*)?\)/g,
			( full, prefix, base, anchor ) => {
				const wantsGuide = isGuide ? ! prefix : prefix === '../';
				let slug;
				if ( wantsGuide ) {
					slug = GUIDE_SLUGS[ base ];
				} else {
					slug = allSlugs.has( base ) ? base : undefined;
				}
				if ( ! slug ) {
					unresolved.push( full );
					return full;
				}
				if ( ! anchor ) {
					return `](${ SITE }/docs/${ slug }/)`;
				}
				const id = idOf( slug, anchor.slice( 1 ) );
				if ( id === null ) {
					unresolved.push( full );
					return full;
				}
				return `](${ SITE }/docs/${ slug }/#${ id })`;
			}
		)
		.replace( /\]\(#([^)\s]+)\)/g, ( full, anchor ) => {
			const id = ownSlug ? idOf( ownSlug, anchor ) : null;
			if ( id === null ) {
				unresolved.push( full );
				return full;
			}
			return `](#${ id })`;
		} );
	return { rewritten, unresolved };
}

/**
 * A page's title, from its first `# ` heading.
 *
 * @param {string} markdown
 */
function extractTitle( markdown ) {
	const m = markdown.match( /^#\s+(.+)$/m );
	return m ? m[ 1 ].trim() : null;
}

/**
 * Renders one top-level marked token to its own HTML, wrapped as the matching Gutenberg block comment.
 *
 * @param {Object} token
 */
function renderBlock( token ) {
	if ( token.type === 'heading' ) {
		const inline = marked.parseInline( token.text );
		const level = token.depth;
		return `<!-- wp:heading {"level":${ level }} -->\n<h${ level } class="wp-block-heading">${ inline }</h${ level }>\n<!-- /wp:heading -->`;
	}
	if ( token.type === 'paragraph' ) {
		const html = marked.parser( [ token ] ).trim();
		return `<!-- wp:paragraph -->\n${ html }\n<!-- /wp:paragraph -->`;
	}
	if ( token.type === 'list' ) {
		const html = marked.parser( [ token ] ).trim();
		const tag = token.ordered ? 'ol' : 'ul';
		const withClass = html.replace(
			new RegExp( `^<${ tag }>` ),
			`<${ tag } class="wp-block-list">`
		);
		return `<!-- wp:list${
			token.ordered ? ' {"ordered":true}' : ''
		} -->\n${ withClass }\n<!-- /wp:list -->`;
	}
	if ( token.type === 'table' ) {
		const html = marked.parser( [ token ] ).trim();
		return `<!-- wp:table -->\n<figure class="wp-block-table">${ html }</figure>\n<!-- /wp:table -->`;
	}
	if ( token.type === 'space' ) {
		return null;
	}
	const html = marked.parser( [ token ] ).trim();
	return `<!-- wp:html -->\n${ html }\n<!-- /wp:html -->`;
}

/**
 * The page's blocks in order, each with its Markdown heading words when it is a heading.
 *
 * @param {string} markdown
 */
function convertBlocks( markdown ) {
	return marked
		.lexer( markdown )
		.map( ( token ) => ( {
			html: renderBlock( token ),
			heading:
				token.type === 'heading' ? headingWords( token.text ) : null,
		} ) )
		.filter( ( block ) => block.html );
}

/**
 * The page as one string of Gutenberg block HTML.
 *
 * @param {string} markdown
 */
function convert( markdown ) {
	return convertBlocks( markdown )
		.map( ( block ) => block.html )
		.join( '\n\n' );
}

module.exports = {
	SITE,
	GUIDE_SLUGS,
	rewriteLinks,
	extractTitle,
	headingWords,
	headingAnchors,
	headingId,
	convertBlocks,
	convert,
};
