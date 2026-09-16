/**
 * Decides whether a character sheet shows one of its prose sections, Background or Notes.
 * The sheet page shows one whenever it has text; its "Include when printing" checkbox only
 * chooses what goes into a print. The print canvas page is itself the print, so there the
 * checkbox decides.
 */

/**
 * Whether a prose section renders: never without text, always on the sheet page, and on the
 * print canvas only when it was chosen for printing.
 */
export function showsProseSection(
	content: string | null | undefined,
	isPrintCanvas: boolean,
	includedInPrint: boolean
): boolean {
	if ( ! content ) {
		return false;
	}
	return ! isPrintCanvas || includedInPrint;
}
