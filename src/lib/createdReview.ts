/**
 * The picks a Storyteller will look at on a character just created, kept for the sheet the builder opens on.
 */

export interface CreatedPick {
	name: string;
	reason: string | null;
}

const key = ( id: number | string ) => `be-created-review-${ id }`;

/**
 * Keeps the picks for a character; false when the browser refuses to store them.
 */
export function rememberCreatedReview(
	id: number | string,
	picks: CreatedPick[]
): boolean {
	try {
		window.sessionStorage.setItem( key( id ), JSON.stringify( picks ) );
		return true;
	} catch {
		return false;
	}
}

/**
 * The picks kept for a character, none when nothing was kept or it is unreadable.
 */
export function readCreatedReview( id: number | string ): CreatedPick[] {
	try {
		const stored = window.sessionStorage.getItem( key( id ) );
		const parsed: unknown = stored ? JSON.parse( stored ) : [];
		return Array.isArray( parsed )
			? parsed.filter(
					( pick ): pick is CreatedPick =>
						!! pick && typeof pick.name === 'string'
				)
			: [];
	} catch {
		return [];
	}
}
