/**
 * A character's sheet style as the CSS custom properties its sheet root carries.
 */
import type { CSSProperties } from 'react';
import type { SheetStyle } from '../types/character';
import { readableTextFor } from './colorContrast';

/**
 * A chosen background brings every surface on the sheet that paints its own - the summary row, the action picker,
 * notices - onto that background, and supplies a readable text color when none was chosen. A chosen text color
 * becomes the label and dot color too.
 */
export function sheetStyleVars( style: SheetStyle ): CSSProperties {
	const vars: Record< string, string > = {};
	const background = style.background_color || null;
	const text =
		style.text_color ||
		( background ? readableTextFor( background ) : null );

	if ( style.font_family ) {
		vars[ '--be-sheet-font' ] = style.font_family;
	}
	if ( style.accent_color ) {
		vars[ '--be-sheet-accent' ] = style.accent_color;
	}
	if ( background ) {
		vars[ '--be-sheet-bg' ] = background;
		vars[ '--be-sheet-surface' ] = background;
		vars[ '--be-sheet-header-bg' ] = 'transparent';
		vars[ '--be-sheet-notice-bg' ] = 'transparent';
	}
	if ( text ) {
		vars[ '--be-sheet-text' ] = text;
		vars[ '--be-sheet-label' ] = text;
		vars[ '--be-sheet-dot-filled' ] = text;
		vars[ '--be-sheet-dot-line' ] = text;
		vars[ '--be-sheet-header-bg' ] = 'transparent';
	}
	if ( style.background_image_url ) {
		vars[ '--be-sheet-bg-image' ] = `url(${ JSON.stringify(
			style.background_image_url
		) })`;
	}
	return vars as CSSProperties;
}
