/**
 * Grid-layout math for rendering a template's sections in a CSS grid: the shared
 * 6-unit track count, each section's column span by its declared width, and the
 * row-flow reading order sections should be laid out in. Exports `LAYOUT_GRID_UNITS`,
 * `spanFor()`, and `sortedForFlow()`.
 */
import type { TemplateLayoutSection } from '../types';

/** Grid track count for the layout grid; divides evenly into thirds and halves. */
export const LAYOUT_GRID_UNITS = 6;

const SPAN: Record<NonNullable<TemplateLayoutSection[ 'width' ]>, number> = {
	third: 2,
	half: 3,
	full: 6,
};

/**
 * Returns the number of grid columns a section should span, based on its declared
 * `width` of 'third', 'half', or 'full'. A section with no `width` set defaults to
 * 'third'.
 */
export function spanFor( width: TemplateLayoutSection[ 'width' ] ): number {
	return SPAN[ width ?? 'third' ];
}

/**
 * Returns a copy of `sections` sorted into row-flow reading order, first by `column`
 * and then by `order` within each column. This is the sequence CSS grid auto-flow lays
 * the sections into rows from.
 */
export function sortedForFlow( sections: TemplateLayoutSection[] ): TemplateLayoutSection[] {
	return [ ...sections ].sort( ( a, b ) => a.column - b.column || a.order - b.order );
}
