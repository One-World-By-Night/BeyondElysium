/**
 * Grid-layout math for rendering a template's sections in a CSS grid: the shared
 * 6-unit track count, each section's column span by its declared width, the
 * row-flow reading order sections should be laid out in, and whether a section starts
 * collapsed. Exports `LAYOUT_GRID_UNITS`, `spanFor()`, `sortedForFlow()`, and
 * `isSectionCollapsed()`.
 */
import type { TemplateLayoutSection } from '../types';

/** Grid track count for the layout grid; divides evenly into thirds and halves. */
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
 * Returns the number of grid columns a section should span, based on its declared
 * `width` of 'third', 'half', or 'full'. A section with no `width` set, or any other
 * width, defaults to 'third' - the same reading as the signed sheet (1.0.0-review F-077).
 */
export function spanFor(
	width: TemplateLayoutSection[ 'width' ] | string | null
): number {
	return SPAN[ ( width ?? 'third' ) as keyof typeof SPAN ] ?? SPAN.third;
}

/**
 * Returns a copy of `sections` sorted into row-flow reading order, first by `column`
 * and then by `order` within each column. This is the sequence CSS grid auto-flow lays
 * the sections into rows from.
 */
export function sortedForFlow(
	sections: TemplateLayoutSection[]
): TemplateLayoutSection[] {
	return [ ...sections ].sort(
		( a, b ) => a.column - b.column || a.order - b.order
	);
}

/**
 * Whether a section should currently render closed: the viewer's own toggle for this
 * section (`overrides`, keyed by `block_slug`) if they've clicked it, otherwise the
 * template's own `collapsed` flag. A section the template never set `collapsed` on
 * defaults to open, matching `collapsed`'s own schema default of `false`.
 */
export function isSectionCollapsed(
	section: Pick< TemplateLayoutSection, 'block_slug' | 'collapsed' >,
	overrides: Record< string, boolean >
): boolean {
	return overrides[ section.block_slug ] ?? !! section.collapsed;
}
