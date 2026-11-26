/**
 * The views of My Chronicle's Character section, which of them a viewer may open, and the one a person last used.
 */

/**
 * The views, in the order the view row lists them.
 */
export const CHARACTER_VIEWS = [
	'play',
	'sheet',
	'story',
	'history',
	'edit',
] as const;

export type CharacterView = ( typeof CHARACTER_VIEWS )[ number ];

/**
 * The tab key of the whole section; each view's own key opens the section at that view.
 */
export const CHARACTER_SECTION = 'character';

const STORAGE_KEY = 'be-character-view';

/**
 * Whether a tab key names one of the section's views.
 */
export function isCharacterView( key: string ): key is CharacterView {
	return ( CHARACTER_VIEWS as readonly string[] ).includes( key );
}

/**
 * Whether a tab key opens the Character section, by its own key or by a view's.
 */
export function isCharacterTab( key: string ): boolean {
	return key === CHARACTER_SECTION || isCharacterView( key );
}

/**
 * The navigation key a tab belongs to: the section's for any of its views, otherwise the tab's own.
 */
export function navKey( tab: string ): string {
	return isCharacterTab( tab ) ? CHARACTER_SECTION : tab;
}

/**
 * The views a viewer may open on a character, in row order. Sheet and History need only the sheet; Play, Story and
 * Edit need the character's own player or a manager.
 */
export function viewsFor( canEdit: boolean ): CharacterView[] {
	return CHARACTER_VIEWS.filter(
		( view ) => view === 'sheet' || view === 'history' || canEdit
	);
}

/**
 * The view to show for a tab: the tab's own view when the viewer may open it, otherwise the view last used when they
 * may open that, otherwise Sheet.
 */
export function resolveView(
	tab: string,
	last: string | null,
	allowed: CharacterView[]
): CharacterView {
	if ( isCharacterView( tab ) && allowed.includes( tab ) ) {
		return tab;
	}
	if (
		last !== null &&
		isCharacterView( last ) &&
		allowed.includes( last )
	) {
		return last;
	}
	return 'sheet';
}

type ReadableStorage = Pick< Storage, 'getItem' >;
type WritableStorage = Pick< Storage, 'setItem' >;

/**
 * The view last used on this device, or null when none was, storage is blocked, or it holds something else.
 */
export function readLastView(
	storage: ReadableStorage | null = browserStorage()
): CharacterView | null {
	try {
		const stored = storage?.getItem( STORAGE_KEY ) ?? null;
		return stored !== null && isCharacterView( stored ) ? stored : null;
	} catch {
		return null;
	}
}

/**
 * Remembers the view just opened, for this device. Storage that is blocked or full loses it quietly.
 */
export function rememberView(
	view: CharacterView,
	storage: WritableStorage | null = browserStorage()
): void {
	try {
		storage?.setItem( STORAGE_KEY, view );
	} catch {
		// A blocked or full store only forgets the choice.
	}
}

function browserStorage(): Storage | null {
	try {
		return window.localStorage;
	} catch {
		return null;
	}
}
