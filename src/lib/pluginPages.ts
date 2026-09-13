/**
 * The plugin's provisioned front-end page slugs (`Page_Provisioner::PAGES`), in one
 * place. Before this, five call sites each hardcoded the same URL as a separate string
 * literal (mobile-sheet-design.md §4.3) - a latent bug for a future rename or typo, since
 * nothing would catch four of five getting updated and one not. No behaviour change: every
 * function here builds exactly the URL its call site already built by hand.
 */

export const PLUGIN_PAGE_SLUGS = {
	characterSheet: 'character-sheet',
	characterSheetPrint: 'character-sheet-print',
	characterEditor: 'character-editor',
} as const;

/** Builds the absolute URL of a provisioned plugin page from its slug. */
export function pluginPageUrl( slug: string ): string {
	return `${ window.location.origin }/${ slug }/`;
}

/** The read-only character sheet URL for one character. */
export function characterSheetUrl( characterId: number, gameSlug: string ): string {
	return `${ pluginPageUrl( PLUGIN_PAGE_SLUGS.characterSheet ) }?character_id=${ characterId }&game_slug=${ encodeURIComponent( gameSlug ) }`;
}

/** The character editor URL for one character. */
export function characterEditorUrl( characterId: number, gameSlug: string ): string {
	return `${ pluginPageUrl( PLUGIN_PAGE_SLUGS.characterEditor ) }?character_id=${ characterId }&game_slug=${ encodeURIComponent( gameSlug ) }`;
}

/** True when `pathname` is the print-canvas page, which renders with no chrome and prints itself automatically. */
export function isPrintCanvasPath( pathname: string ): boolean {
	return pathname.includes( `/${ PLUGIN_PAGE_SLUGS.characterSheetPrint }` );
}
