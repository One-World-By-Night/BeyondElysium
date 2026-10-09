/**
 * Sheet sections for the blocks a character holds on a creature type that allows any block.
 */
import type { ResolvedStack, TemplateLayoutSection } from '../types';

/**
 * Whether a creature type may hold any schema block in the catalog, not only the sections its template lists.
 */
export function allowsAnyBlock(
	stack: ResolvedStack | null | undefined
): boolean {
	return !! stack?.stack?.stack_definition?.any_block;
}

/**
 * A layout's sections followed by one full-width section for every block the character holds, or has just had added,
 * that the layout does not place - on a creature type that allows any block. Any other creature type's sections come
 * back unchanged.
 */
export function withHeldBlockSections(
	sections: TemplateLayoutSection[],
	stack: ResolvedStack | null | undefined,
	sheetData: Record< string, unknown >,
	addedSlugs: string[] = []
): TemplateLayoutSection[] {
	if ( ! stack || ! allowsAnyBlock( stack ) ) {
		return sections;
	}

	const placed = new Set( sections.map( ( s ) => s.block_slug ) );
	const column =
		sections.length === 0
			? 1
			: Math.max( ...sections.map( ( s ) => s.column ) ) + 1;
	const extra: TemplateLayoutSection[] = [];
	for ( const slug of [ ...Object.keys( sheetData ), ...addedSlugs ] ) {
		const block = stack.blocks[ slug ];
		if ( ! block || placed.has( slug ) ) {
			continue;
		}
		placed.add( slug );
		extra.push( {
			block_slug: slug,
			column,
			order: extra.length + 1,
			title: block.name,
			display: null,
			collapsed: false,
			width: 'full',
		} );
	}
	return extra.length === 0 ? sections : [ ...sections, ...extra ];
}
