/**
 * How long a catalog description's sections are as plain text, so a pop-up can warn when one runs long.
 */
import type { CatalogDescription } from '../types';

/**
 * A section of more plain-text characters than this reads as pasted book text rather than a short note.
 */
export const DESCRIPTION_SOFT_LIMIT = 500;

/**
 * The characters a block of HTML shows, without its tags.
 */
export function plainTextLength( html: string ): number {
	const body = new DOMParser().parseFromString( html, 'text/html' ).body;
	return ( body.textContent ?? '' ).replace( /\s+/g, ' ' ).trim().length;
}

/**
 * Whether any one section of a description runs past the soft limit.
 */
export function isLongDescription( description: CatalogDescription ): boolean {
	return [
		description.reference,
		description.description,
		description.source,
	].some(
		( section ) => plainTextLength( section ?? '' ) > DESCRIPTION_SOFT_LIMIT
	);
}
