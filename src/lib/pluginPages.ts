/**
 * The plugin's provisioned front-end page slugs (`Page_Provisioner::PAGES`) and tab
 * keys, in one place. page-consolidation-design.md collapsed ten page-kinds (each
 * duplicated per chronicle) down to four fixed pages: My Chronicle and Storyteller
 * Toolkit are each a tabbed shell around the same inner widgets that used to have
 * their own dedicated page, plus Character Sheet (Print) and Verify Character, both
 * kept standalone for real technical reasons (see that doc). No page here bakes in a
 * chronicle any more - `?game_slug=`/`?tab=`/`?character_id=` in the URL carry all of
 * it, read by `useChronicleSwitcher()` and the tab strip on each page.
 */

export const PLUGIN_PAGE_SLUGS = {
	player: 'be-player',
	storyteller: 'be-storyteller',
	characterSheetPrint: 'character-sheet-print',
	verify: 'be-verify',
} as const;

/** Tab keys for the My Chronicle page. Not in the tab strip: sendFile (F-122). */
export const PLAYER_TABS = {
	dashboard: 'dashboard',
	characters: 'characters',
	sheet: 'sheet',
	edit: 'edit',
	plots: 'plots',
	reports: 'reports',
	sendFile: 'send-file',
	proposeItem: 'propose-item',
} as const;

/** Tab keys for the Storyteller Toolkit page. */
export const STORYTELLER_TABS = {
	dashboard: 'dashboard',
	approvalQueue: 'approval-queue',
	plots: 'plots',
	boonLedger: 'boon-ledger',
	worldObjects: 'world-objects',
} as const;

/** Builds the absolute URL of a provisioned plugin page from its slug. */
export function pluginPageUrl( slug: string ): string {
	return `${ window.location.origin }/${ slug }/`;
}

/** Base URL for one tab of the My Chronicle page, with no character/chronicle selection yet. */
export function playerTabUrl( tab: string ): string {
	return `${ pluginPageUrl( PLUGIN_PAGE_SLUGS.player ) }?tab=${ tab }`;
}

/** Base URL for one tab of the Storyteller Toolkit page, with no chronicle selection yet. */
export function storytellerTabUrl( tab: string ): string {
	return `${ pluginPageUrl( PLUGIN_PAGE_SLUGS.storyteller ) }?tab=${ tab }`;
}

/** The character sheet URL for one character - the My Chronicle page's Sheet tab. */
export function characterSheetUrl(
	characterId: number,
	gameSlug: string
): string {
	return `${ playerTabUrl(
		PLAYER_TABS.sheet
	) }&character_id=${ characterId }&game_slug=${ encodeURIComponent(
		gameSlug
	) }`;
}

/** The character editor URL for one character - the My Chronicle page's Edit tab. */
export function characterEditorUrl(
	characterId: number,
	gameSlug: string
): string {
	return `${ playerTabUrl(
		PLAYER_TABS.edit
	) }&character_id=${ characterId }&game_slug=${ encodeURIComponent(
		gameSlug
	) }`;
}

/**
 * The character creation URL for one chronicle - the My Chronicle page's Edit
 * tab with no character_id, which CharacterEditor already treats as create
 * mode (admin-menu-consolidation-design.md's wp-admin "+ New Character" entry
 * point reuses this same front-end path rather than duplicating the editor).
 */
export function newCharacterUrl( gameSlug: string ): string {
	return `${ playerTabUrl(
		PLAYER_TABS.edit
	) }&game_slug=${ encodeURIComponent( gameSlug ) }`;
}

/**
 * The shared link Chronicle Setup offers for players to send a Grapevine
 * file to a specific chronicle (F-122) - opens Send a Grapevine File with
 * that chronicle already picked.
 */
export function sendFileLinkUrl( gameSlug: string ): string {
	return `${ playerTabUrl(
		PLAYER_TABS.sendFile
	) }&game_slug=${ encodeURIComponent( gameSlug ) }`;
}

/** True when `pathname` is the print-canvas page, which renders with no chrome and prints itself automatically. */
export function isPrintCanvasPath( pathname: string ): boolean {
	return pathname.includes( `/${ PLUGIN_PAGE_SLUGS.characterSheetPrint }` );
}

/** Reads the current `?tab=` from the URL, or `defaultTab` when absent. */
export function readTabFromUrl( defaultTab: string ): string {
	return (
		new URLSearchParams( window.location.search ).get( 'tab' ) || defaultTab
	);
}

/** Writes `tab` into the URL without a page reload, so a bookmark or refresh preserves it. */
export function writeTabToUrl( tab: string ): void {
	const url = new URL( window.location.href );
	url.searchParams.set( 'tab', tab );
	window.history.replaceState( {}, '', url.toString() );
}
