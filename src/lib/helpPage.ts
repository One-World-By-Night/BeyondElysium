/**
 * How the help panel reads a help page's links and headings.
 */

/**
 * The guides a help page can link into, served by `GET /docs/{slug}`.
 */
export const GUIDES = [
	'st-guide',
	'admin-guide',
	'player-guide',
	'rest-api',
] as const;

export type GuideSlug = ( typeof GUIDES )[ number ];

export type HelpTarget =
	| { kind: 'help'; key: string; anchor: string }
	| { kind: 'guide'; slug: GuideSlug; anchor: string }
	| { kind: 'section'; anchor: string }
	| { kind: 'external'; href: string };

/**
 * What a link in a help page points at, or null for a link the panel can't follow.
 */
export function helpTarget( href: string ): HelpTarget | null {
	if ( /^(https?:|mailto:)/i.test( href ) ) {
		return { kind: 'external', href };
	}
	if ( href.startsWith( '#' ) ) {
		return { kind: 'section', anchor: href.slice( 1 ) };
	}
	const match = href.match(
		/^(\.\.\/)?(?:help\/)?([a-z0-9-]+)\.md(?:#(.*))?$/
	);
	if ( ! match ) {
		return null;
	}
	const [ , up, name, anchor = '' ] = match;
	if ( ! up ) {
		return { kind: 'help', key: name, anchor };
	}
	return ( GUIDES as readonly string[] ).includes( name )
		? { kind: 'guide', slug: name as GuideSlug, anchor }
		: null;
}

/**
 * A heading's words without their Markdown: link text kept, emphasis and code marks dropped.
 */
export function headingText( markdown: string ): string {
	return markdown
		.replace( /!?\[([^\]]*)\]\([^)]*\)/g, '$1' )
		.replace( /[*_`~]/g, '' )
		.trim();
}

/**
 * A heading's anchor the way GitHub makes one.
 */
export function headingSlug( text: string ): string {
	return text
		.toLowerCase()
		.replace( /[^\p{L}\p{M}\p{N}\p{Pc}\- ]/gu, '' )
		.replace( / /g, '-' );
}

/**
 * The anchor of every heading in a Markdown document, in order.
 */
export function headingAnchors( markdown: string ): string[] {
	const seen = new Map< string, number >();
	const anchors: string[] = [];
	let fenced = false;
	for ( const line of markdown.split( '\n' ) ) {
		if ( /^\s*```/.test( line ) ) {
			fenced = ! fenced;
			continue;
		}
		const heading = ! fenced && line.match( /^#{1,6}\s+(.*?)\s*#*\s*$/ );
		if ( heading ) {
			anchors.push(
				uniqueAnchor( headingSlug( headingText( heading[ 1 ] ) ), seen )
			);
		}
	}
	return anchors;
}

/**
 * `base`, or `base-1`, `base-2`,... when `seen` has handed it out before.
 */
export function uniqueAnchor(
	base: string,
	seen: Map< string, number >
): string {
	const count = seen.get( base ) ?? 0;
	seen.set( base, count + 1 );
	return count ? `${ base }-${ count }` : base;
}

/**
 * A page's title, from its first `# ` heading.
 */
export function pageTitle( markdown: string ): string {
	const title = markdown.match( /^#\s+(.+)$/m );
	return title ? headingText( title[ 1 ] ) : '';
}

/**
 * The page without its `# ` title.
 */
export function withoutTitle( markdown: string ): string {
	return markdown.replace( /^\s*#\s+.*\n+/, '' );
}
