/**
 * Gathers every page of a paginated list, for a picker that must offer
 * the whole list rather than its first page (1.0.0-review F-080).
 */

/** The most pages gathered - 10,000 rows at the routes' 100 a page. */
const MAX_PAGES = 100;

/**
 * Calls `fetchPage` for page 1, 2, ... until the gathered rows reach the
 * reported total or a page comes back empty.
 */
export async function everyPage< T >(
	fetchPage: ( page: number ) => Promise< { items: T[]; total: number } >
): Promise< T[] > {
	const all: T[] = [];
	for ( let page = 1; page <= MAX_PAGES; page++ ) {
		const { items, total } = await fetchPage( page );
		all.push( ...items );
		if ( items.length === 0 || all.length >= total ) {
			break;
		}
	}
	return all;
}
