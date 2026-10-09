/**
 * Pairs the blocks of a page's English and Portuguese renderings, so each English string on the website has the
 * Portuguese string that replaces it.
 *
 * A pair is either a whole block (the markup inside a paragraph, list item, heading or table cell, which the
 * website translates as one string) or a loose string (a piece of text or a translated attribute outside such a
 * block).
 */
const { JSDOM } = require( 'jsdom' );

const TEXT_NODE = 3;
const ELEMENT_NODE = 1;

// The elements the website translates as one whole block when they hold no other such element.
const BLOCK_TAGS = new Set( [
	'p',
	'div',
	'li',
	'ol',
	'ul',
	'h1',
	'h2',
	'h3',
	'h4',
	'h5',
	'h6',
	'h7',
	'body',
	'footer',
	'article',
	'main',
	'iframe',
	'section',
	'figure',
	'figcaption',
	'blockquote',
	'cite',
	'tr',
	'td',
	'th',
	'table',
	'tbody',
	'thead',
	'tfoot',
	'form',
	'label',
] );

// Headings are paired piece by piece: the website adds its own link inside each heading, so a heading is never one
// whole block there.
const HEADING_TAGS = new Set( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'h7' ] );

// The attributes outside a block whose values the website translates.
const TRANSLATED_ATTRIBUTES = [ 'alt', 'title', 'href', 'src' ];

/**
 * The element's children that carry something: elements, and text that is not only white space.
 *
 * @param {Node} node
 */
function significantChildren( node ) {
	return [ ...node.childNodes ].filter(
		( child ) =>
			child.nodeType === ELEMENT_NODE ||
			( child.nodeType === TEXT_NODE && child.textContent.trim() !== '' )
	);
}

/**
 * Whether an element has an element of its own inside it, such as a link or emphasis.
 *
 * @param {Element} element
 */
function hasMarkup( element ) {
	return element.children.length > 0;
}

/**
 * The text an element holds, as written: only its ends are trimmed, since the website matches the line breaks too.
 *
 * @param {Node} node
 */
function raw( node ) {
	return node.textContent.trim();
}

/**
 * The markup inside an element, with the quotes in its text written as entities the way the Markdown renderer writes
 * them, since that is what the page holds.
 *
 * @param {Element} element
 */
function markup( element ) {
	return element.innerHTML
		.split( /(<[^>]*>)/ )
		.map( ( part ) =>
			part.startsWith( '<' )
				? part
				: part.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' )
		)
		.join( '' );
}

/**
 * Whether an element holds another block element anywhere below it.
 *
 * @param {Element} element
 */
function holdsBlock( element ) {
	return [ ...element.querySelectorAll( '*' ) ].some( ( child ) =>
		BLOCK_TAGS.has( child.localName )
	);
}

/**
 * Whether the website translates this element as one whole block.
 *
 * @param {Element} element
 */
function isWholeBlock( element ) {
	return (
		BLOCK_TAGS.has( element.localName ) &&
		! HEADING_TAGS.has( element.localName ) &&
		! holdsBlock( element ) &&
		element.textContent.trim() !== ''
	);
}

/**
 * A link's address without its heading anchor.
 *
 * @param {string} href
 */
function withoutAnchor( href ) {
	return href.split( '#' )[ 0 ];
}

/**
 * Pairs two elements that have the same place in the page, adding to `pairs` and `problems`.
 *
 * @param {Element}     english
 * @param {Element}     portuguese
 * @param {string}      where
 * @param {Object[]}    pairs
 * @param {string[]}    problems
 * @param {Set<string>} apart      Whole blocks, by text, to pair piece by piece instead.
 */
function pairElements( english, portuguese, where, pairs, problems, apart ) {
	const left = significantChildren( english );
	const right = significantChildren( portuguese );
	if ( left.length !== right.length ) {
		problems.push( `${ where }: the markup differs` );
		return;
	}
	left.forEach( ( node, index ) => {
		const other = right[ index ];
		if ( node.nodeType !== other.nodeType ) {
			problems.push( `${ where }: the markup differs` );
			return;
		}
		if ( node.nodeType === TEXT_NODE ) {
			const original = raw( node );
			const translated = raw( other );
			if ( original !== translated ) {
				pairs.push( { kind: 'text', original, translated } );
			}
			return;
		}
		if ( node.localName !== other.localName ) {
			problems.push( `${ where }: the markup differs` );
			return;
		}
		if (
			isWholeBlock( node ) &&
			! apart.has( blockText( node.innerHTML ) )
		) {
			if ( ! isWholeBlock( other ) ) {
				problems.push( `${ where }: the markup differs` );
				return;
			}
			if ( hasMarkup( node ) || hasMarkup( other ) ) {
				const original = markup( node );
				const translated = markup( other );
				if ( original !== translated ) {
					pairs.push( { kind: 'block', original, translated } );
				}
			} else if ( raw( node ) !== raw( other ) ) {
				pairs.push( {
					kind: 'text',
					original: raw( node ),
					translated: raw( other ),
				} );
			}
			return;
		}
		for ( const name of TRANSLATED_ATTRIBUTES ) {
			if ( node.hasAttribute( name ) !== other.hasAttribute( name ) ) {
				problems.push( `${ where }: the markup differs` );
				return;
			}
			if ( ! node.hasAttribute( name ) ) {
				continue;
			}
			const original = node.getAttribute( name );
			const translated = other.getAttribute( name );
			if (
				name === 'href' &&
				withoutAnchor( original ) !== withoutAnchor( translated )
			) {
				problems.push( `${ where }: a link goes somewhere else` );
				return;
			}
			if ( original !== translated ) {
				pairs.push( { kind: name, original, translated } );
			}
		}
		pairElements( node, other, where, pairs, problems, apart );
	} );
}

/**
 * Pairs one page's blocks. A page that does not pair is refused whole: no pair is returned for it.
 *
 * @param {{html: string, heading: string|null}[]} english
 * @param {{html: string, heading: string|null}[]} portuguese
 * @param {Set<string>}                            apart      Whole blocks, by text, to pair piece by piece instead.
 * @return {{pairs: Object[], problems: string[]}} The pairs, or the reasons the page does not pair.
 */
function pairBlocks( english, portuguese, apart = new Set() ) {
	if ( english.length !== portuguese.length ) {
		return {
			pairs: [],
			problems: [
				`${ english.length } blocks in English, ${ portuguese.length } in Portuguese`,
			],
		};
	}

	const pairs = [];
	const problems = [];
	english.forEach( ( block, index ) => {
		const where = `block ${ index + 1 }${
			block.heading ? ` (${ block.heading })` : ''
		}`;
		const left = JSDOM.fragment( block.html );
		const right = JSDOM.fragment( portuguese[ index ].html );
		const holderLeft = left.ownerDocument.createElement( 'div' );
		const holderRight = right.ownerDocument.createElement( 'div' );
		holderLeft.append( left );
		holderRight.append( right );
		pairElements( holderLeft, holderRight, where, pairs, problems, apart );
	} );

	return problems.length ? { pairs: [], problems } : { pairs, problems };
}

/**
 * What a whole block says once its markup is gone, the way the website matches a block to a page.
 *
 * @param {string} html
 */
function blockText( html ) {
	return JSDOM.fragment( `<div>${ html }</div>` )
		.firstChild.textContent.replace( /\s+/g, ' ' )
		.trim();
}

/**
 * The English strings that more than one Portuguese text answers to: the website keeps one translation for each
 * string, whatever kind of string it is, and one for each block of words.
 *
 * @param {{kind: string, original: string, translated: string}[]} pairs
 */
function conflicts( pairs ) {
	const groups = new Map();
	const add = ( key, label, pair ) => {
		if ( ! groups.has( key ) ) {
			groups.set( key, { original: label, versions: new Map() } );
		}
		groups
			.get( key )
			.versions.set(
				`${ pair.original }\u0001${ pair.translated }`,
				pair
			);
	};
	for ( const pair of pairs ) {
		add( `string\u0000${ pair.original }`, pair.original, pair );
		if ( pair.kind === 'block' || pair.kind === 'text' ) {
			const same =
				pair.kind === 'text'
					? pair.original.replace( /\s+/g, ' ' )
					: blockText( pair.original );
			add( `words\u0000${ same }`, same, pair );
		}
	}
	const found = [];
	const reported = new Set();
	for ( const group of groups.values() ) {
		const versions = [ ...group.versions.values() ];
		const translations = new Set(
			versions.map( ( version ) => version.translated )
		);
		const markups = new Set(
			versions.map( ( version ) => version.original )
		);
		if ( translations.size > 1 || markups.size > 1 ) {
			const signature = versions
				.map(
					( version ) =>
						`${ version.original }\u0001${ version.translated }`
				)
				.sort()
				.join( '\u0002' );
			if ( ! reported.has( signature ) ) {
				reported.add( signature );
				found.push( {
					original: group.original,
					versions: versions.map( ( version ) => ( {
						original: version.original,
						translated: version.translated,
					} ) ),
				} );
			}
		}
	}
	return found;
}

/**
 * Pairs with the same string and translation merged into one.
 *
 * @param {{kind: string, original: string, translated: string}[]} pairs
 */
function unique( pairs ) {
	const seen = new Set();
	return pairs.filter( ( pair ) => {
		const key = [ pair.kind, pair.original, pair.translated ].join(
			'\u0000'
		);
		if ( seen.has( key ) ) {
			return false;
		}
		seen.add( key );
		return true;
	} );
}

/**
 * Pairs every page together. A whole block whose text also stands in a link, a heading or a cell with the same words
 * but other markup is paired piece by piece, so one translation fits wherever the text appears. What still has two
 * translations is returned as a conflict.
 *
 * @param {{slug: string, english: Object[], portuguese: Object[]}[]} pages
 * @return {{pages: Object[], conflicts: Object[]}} Each page with its pairs or problems, and the strings left in conflict.
 */
function planTranslations( pages ) {
	const first = pages.flatMap(
		( page ) => pairBlocks( page.english, page.portuguese ).pairs
	);
	const apart = new Set(
		conflicts( first )
			.filter(
				( conflict ) =>
					new Set(
						conflict.versions.map( ( version ) =>
							blockText( version.translated )
						)
					).size === 1
			)
			.map( ( conflict ) => conflict.original )
	);

	const planned = pages.map( ( page ) => ( {
		slug: page.slug,
		...pairBlocks( page.english, page.portuguese, apart ),
	} ) );
	const every = unique( planned.flatMap( ( page ) => page.pairs ) );
	return { pages: planned, conflicts: conflicts( every ) };
}

module.exports = { pairBlocks, planTranslations, conflicts, unique, blockText };
