/**
 * The Approval Queue's ticked changes, each kept with the review token of the version the Storyteller ticked.
 */

/**
 * Change id to the review token it was ticked with.
 */
export type QueueSelection = ReadonlyMap< number, string | undefined >;

/**
 * Ticks a change with the token it was shown with, or unticks it.
 */
export function toggleSelection(
	selection: QueueSelection,
	id: number,
	token: string | undefined
): QueueSelection {
	const next = new Map( selection );
	if ( next.has( id ) ) {
		next.delete( id );
	} else {
		next.set( id, token );
	}
	return next;
}

/**
 * What a batch approval sends: every ticked id, and each one's token when it had one.
 */
export function batchApproval( selection: QueueSelection ): {
	ids: number[];
	tokens: Record< number, string >;
} {
	const tokens: Record< number, string > = {};
	for ( const [ id, token ] of selection ) {
		if ( token ) {
			tokens[ id ] = token;
		}
	}
	return { ids: [ ...selection.keys() ], tokens };
}
