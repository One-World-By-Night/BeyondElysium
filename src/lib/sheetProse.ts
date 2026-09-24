/**
 * Decides whether a character sheet shows one of its prose sections, Background or Notes.
 */

/**
 * Whether a prose section renders: never without text, always on the sheet page, and on the print canvas only when it
 * was chosen for printing.
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
