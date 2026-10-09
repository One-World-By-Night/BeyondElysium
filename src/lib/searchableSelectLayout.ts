/**
 * Where a searchable select's dropdown list sits on the page.
 */
import type { CSSProperties } from 'react';

export interface ListRect {
	top: number;
	left: number;
	width: number;
	openUpward: boolean;
}

/**
 * The inline style of the fixed-position list: hung from the input's bottom edge, or standing on its top edge when
 * there is more room above. The edge it does not use is released with `auto` so no stylesheet inset reaches it.
 */
export function listBoxStyle(
	rect: ListRect,
	viewportHeight: number,
	virtualized: boolean,
	listHeight: number
): CSSProperties {
	return {
		position: 'fixed',
		top: rect.openUpward ? 'auto' : rect.top,
		bottom: rect.openUpward ? viewportHeight - rect.top : 'auto',
		left: rect.left,
		width: rect.width,
		...( virtualized ? { height: listHeight, overflowY: 'auto' } : {} ),
	};
}
