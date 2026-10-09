/**
 * Asks once per chronicle whether it is a demo, however many controls on the page want to know.
 */
const known = new Map< string, Promise< boolean > >();

/**
 * Whether a chronicle is a demo. The first call for a chronicle makes the request; every later call shares its
 * answer. A request that fails reads as "not a demo".
 */
export function isDemoChronicle(
	gameSlug: string,
	fetchStatus: ( gameSlug: string ) => Promise< { on: boolean } >
): Promise< boolean > {
	let pending = known.get( gameSlug );
	if ( ! pending ) {
		pending = fetchStatus( gameSlug )
			.then( ( status ) => !! status.on )
			.catch( () => false );
		known.set( gameSlug, pending );
	}
	return pending;
}

/**
 * Forgets every answer.
 */
export function resetDemoStatusLookup(): void {
	known.clear();
}
