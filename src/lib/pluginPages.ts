/**
 * The plugin's provisioned front-end page slugs (`Page_Provisioner::PAGES`) and tab keys, in one place.
 */

export const PLUGIN_PAGE_SLUGS = {
	player: 'be-player',
	storyteller: 'be-storyteller',
	characterSheetPrint: 'character-sheet-print',
	verify: 'be-verify',
} as const;

/**
 * Tab keys for the My Chronicle page.
 */
export const PLAYER_TABS = {
	dashboard: 'dashboard',
	characters: 'characters',
	sheet: 'sheet',
	edit: 'edit',
	plots: 'plots',
	reports: 'reports',
	sendFile: 'send-file',
	proposeItem: 'propose-item',
	proposeFaction: 'propose-faction',
	whosWho: 'whos-who',
	whatIKnow: 'what-i-know',
	myGroups: 'my-groups',
	afterGameReport: 'after-game-report',
} as const;

/**
 * Tab keys for the Storyteller Toolkit page.
 */
export const STORYTELLER_TABS = {
	dashboard: 'dashboard',
	myQueue: 'my-queue',
	approvalQueue: 'approval-queue',
	plots: 'plots',
	boonLedger: 'boon-ledger',
	worldObjects: 'world-objects',
	gameNights: 'game-nights',
	releases: 'releases',
	downtime: 'downtime',
	factions: 'factions',
} as const;

/**
 * Builds the absolute URL of a provisioned plugin page from its slug.
 */
export function pluginPageUrl( slug: string ): string {
	const base = (
		window.beyondElysium?.homeUrl ?? `${ window.location.origin }/`
	).replace( /\/+$/, '' );
	return `${ base }/${ slug }/`;
}

/**
 * Base URL for one tab of the My Chronicle page, with no character/chronicle selection yet.
 */
export function playerTabUrl( tab: string ): string {
	return `${ pluginPageUrl( PLUGIN_PAGE_SLUGS.player ) }?tab=${ tab }`;
}

/**
 * Base URL for one tab of the Storyteller Toolkit page, with no chronicle selection yet.
 */
export function storytellerTabUrl( tab: string ): string {
	return `${ pluginPageUrl( PLUGIN_PAGE_SLUGS.storyteller ) }?tab=${ tab }`;
}

/**
 * The character sheet URL for one character.
 */
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

/**
 * The character editor URL for one character.
 */
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
 * The character creation URL for one chronicle.
 */
export function newCharacterUrl( gameSlug: string ): string {
	return `${ playerTabUrl(
		PLAYER_TABS.edit
	) }&game_slug=${ encodeURIComponent( gameSlug ) }`;
}

/**
 * The shared link Chronicle Setup offers for players to send a Grapevine file to a specific chronicle.
 */
export function sendFileLinkUrl( gameSlug: string ): string {
	return `${ playerTabUrl(
		PLAYER_TABS.sendFile
	) }&game_slug=${ encodeURIComponent( gameSlug ) }`;
}

/**
 * True when `pathname` is the print-canvas page.
 */
export function isPrintCanvasPath( pathname: string ): boolean {
	return pathname.includes( `/${ PLUGIN_PAGE_SLUGS.characterSheetPrint }` );
}

/**
 * Reads the current `?tab=` from the URL, or `defaultTab` when absent.
 */
export function readTabFromUrl( defaultTab: string ): string {
	return (
		new URLSearchParams( window.location.search ).get( 'tab' ) || defaultTab
	);
}

/**
 * Writes `tab` into the URL without a page reload.
 */
export function writeTabToUrl( tab: string ): void {
	const url = new URL( window.location.href );
	url.searchParams.set( 'tab', tab );
	window.history.replaceState( {}, '', url.toString() );
}

/**
 * Which chronicle a setup screen opens on: the one named in `?game=` when it is a real one.
 */
export function preselectedChronicle< T extends { slug: string } >(
	chronicles: T[]
): T | null {
	if ( chronicles.length === 0 ) {
		return null;
	}
	const wanted = new URLSearchParams( window.location.search ).get( 'game' );
	return (
		chronicles.find( ( c ) => c.slug === wanted ) ??
		chronicles.find( ( c ) => c.slug !== 'be-demo' ) ??
		chronicles[ 0 ]
	);
}

/**
 * Writes the chronicle on screen into `?game=` without a reload.
 */
export function writeGameToUrl( slug: string ): void {
	const url = new URL( window.location.href );
	url.searchParams.set( 'game', slug );
	window.history.replaceState( {}, '', url.toString() );
}
