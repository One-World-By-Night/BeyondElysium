/**
 * The Approval Queue's ticked changes, each kept with the review token of
 * the version the Storyteller ticked. A change ticked on another page is
 * no longer among the rows on screen, so its token has to travel with the
 * tick - without one, a change edited since would be approved as edited
 * (1.0.0-review F-101).
 */

/** Change id to the review token it was ticked with. */
export type QueueSelection = ReadonlyMap< number, string | undefined >;

/**
 * Ticks a change with the token it was shown with, or unticks it. Returns
 * a new selection; the one given is left as it was.
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
 * What a batch approval sends: every ticked id, and each one's token when
 * it had one.
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
