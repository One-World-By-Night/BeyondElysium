/**
 * Merges a chronicle's waiting transfers and player-sent Grapevine files into one newest-first list for the Import
 * page's Waiting for Review section.
 */
import type { Transfer } from '../types/transfer';
import type { Submission } from '../types/submission';

export type WaitingRow =
	| { kind: 'transfer'; at: string; row: Transfer }
	| { kind: 'submission'; at: string; row: Submission };

export function mergeWaitingRows(
	transfers: Transfer[],
	submissions: Submission[]
): WaitingRow[] {
	const merged: WaitingRow[] = [
		...transfers.map(
			( row ): WaitingRow => ( {
				kind: 'transfer',
				at: row.initiated_at,
				row,
			} )
		),
		...submissions.map(
			( row ): WaitingRow => ( {
				kind: 'submission',
				at: row.created_at,
				row,
			} )
		),
	];
	merged.sort( ( a, b ) => ( a.at < b.at ? 1 : -1 ) );
	return merged;
}
