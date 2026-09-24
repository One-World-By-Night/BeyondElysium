/**
 * Grid-layout math for rendering a template's sections in a CSS grid.
 */
import type { TemplateLayoutSection } from '../types';

/**
 * Grid track count for the layout grid.
 */
export const LAYOUT_GRID_UNITS = 6;

const SPAN: Record<
	NonNullable< TemplateLayoutSection[ 'width' ] >,
	number
> = {
	third: 2,
	half: 3,
	full: 6,
};

/**
 * Returns the number of grid columns a section should span, based on its declared `width` of 'third', 'half', or
 * 'full'.
 */
export function spanFor(
	width: TemplateLayoutSection[ 'width' ] | string | null
): number {
	return SPAN[ ( width ?? 'third' ) as keyof typeof SPAN ] ?? SPAN.third;
}

/**
 * Returns a copy of `sections` sorted into row-flow reading order, first by `column` and then by `order` within each
 * column.
 */
export function sortedForFlow(
	sections: TemplateLayoutSection[]
): TemplateLayoutSection[] {
	return [ ...sections ].sort(
		( a, b ) => a.column - b.column || a.order - b.order
	);
}

/**
 * Whether a section should currently render closed: the viewer's own toggle for this section (`overrides`, keyed by
 * `block_slug`) if they've clicked it.
 */
export function isSectionCollapsed(
	section: Pick< TemplateLayoutSection, 'block_slug' | 'collapsed' >,
	overrides: Record< string, boolean >
): boolean {
	return overrides[ section.block_slug ] ?? !! section.collapsed;
}
