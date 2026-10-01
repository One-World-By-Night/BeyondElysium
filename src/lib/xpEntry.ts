/**
 * Parses and tracks the per-character XP amounts typed into the Characters list's Apply XP column, builds the
 * apply-XP request from them, and folds the response back onto the held amounts.
 */

import { __, sprintf } from '@wordpress/i18n';
import type { XpApplyRequest, XpApplyResult } from '../types/character';

/**
 * The largest amount one row accepts, in either direction.
 */
export const XP_ENTRY_MAX = 10000;

/**
 * The smallest amount one row accepts.
 */
export const XP_ENTRY_MIN = -XP_ENTRY_MAX;

/**
 * A box with nothing meaningful typed into it - blank, or a literal zero.
 */
export interface XpEntryEmpty {
	kind: 'empty';
}

/**
 * A box holding text that isn't a whole number in range.
 */
export interface XpEntryInvalid {
	kind: 'invalid';
}

/**
 * A box holding a valid, non-zero whole number.
 */
export interface XpEntryAmount {
	kind: 'amount';
	amount: number;
}

export type XpEntryParse = XpEntryEmpty | XpEntryInvalid | XpEntryAmount;

/**
 * Reads a whole number out of a box's raw text, or null when it isn't one.
 */
function parseWholeNumber( raw: string ): number | null {
	const trimmed = raw.trim();
	if ( ! /^-?\d+$/.test( trimmed ) ) {
		return null;
	}
	return parseInt( trimmed, 10 );
}

/**
 * Reads one Apply XP box's raw text.
 */
export function parseXpEntry( raw: string ): XpEntryParse {
	if ( raw.trim() === '' ) {
		return { kind: 'empty' };
	}
	const amount = parseWholeNumber( raw );
	if ( amount === null ) {
		return { kind: 'invalid' };
	}
	if ( amount === 0 ) {
		return { kind: 'empty' };
	}
	if ( amount < XP_ENTRY_MIN || amount > XP_ENTRY_MAX ) {
		return { kind: 'invalid' };
	}
	return { kind: 'amount', amount };
}

/**
 * The hint shown under an invalid box.
 */
export function xpEntryHint(): string {
	return sprintf(
		/* translators: 1: the lowest amount accepted, 2: the highest amount accepted */
		__( 'Whole number from %1$s to %2$s', 'beyond-elysium' ),
		String( XP_ENTRY_MIN ),
		String( XP_ENTRY_MAX )
	);
}

/**
 * The largest amount a player's own XP request may ask for.
 */
export const XP_REQUEST_MAX = 10000;

/**
 * Reads a player's own XP-request amount box: a whole number from 1 to `XP_REQUEST_MAX`, never negative or zero.
 */
export function parseXpRequestAmount( raw: string ): XpEntryParse {
	if ( raw.trim() === '' ) {
		return { kind: 'empty' };
	}
	const amount = parseWholeNumber( raw );
	if ( amount === null || amount < 1 || amount > XP_REQUEST_MAX ) {
		return { kind: 'invalid' };
	}
	return { kind: 'amount', amount };
}

/**
 * The hint shown under an invalid XP-request amount box.
 */
export function xpRequestAmountHint(): string {
	return sprintf(
		/* translators: %s: the highest amount a request may ask for */
		__( 'Whole number from 1 to %s', 'beyond-elysium' ),
		String( XP_REQUEST_MAX )
	);
}

/**
 * Today's date in the viewer's own time zone, as `YYYY-MM-DD`.
 */
export function todayYmd( now: Date = new Date() ): string {
	const year = now.getFullYear();
	const month = String( now.getMonth() + 1 ).padStart( 2, '0' );
	const day = String( now.getDate() ).padStart( 2, '0' );
	return `${ year }-${ month }-${ day }`;
}

/**
 * The Apply XP bar's reason box, prefilled with today's date in the viewer's own time zone.
 */
export function defaultXpReason( now: Date = new Date() ): string {
	return sprintf(
		/* translators: %s: today's date in YYYY-MM-DD form */
		__( 'XP award, %s', 'beyond-elysium' ),
		todayYmd( now )
	);
}

/**
 * How many held boxes, across every page, hold a valid non-zero amount right now.
 */
export function countXpEntries( entries: Record< number, string > ): number {
	let count = 0;
	for ( const raw of Object.values( entries ) ) {
		if ( parseXpEntry( raw ).kind === 'amount' ) {
			count++;
		}
	}
	return count;
}

/**
 * Builds the apply-XP request from every held box that holds a valid amount, or null when there's nothing to send.
 */
export function buildXpApplyRequest(
	entries: Record< number, string >,
	reason: string
): XpApplyRequest | null {
	const trimmedReason = reason.trim();
	if ( trimmedReason === '' ) {
		return null;
	}

	const awards = Object.entries( entries ).reduce<
		XpApplyRequest[ 'awards' ]
	>( ( rows, [ id, raw ] ) => {
		const parsed = parseXpEntry( raw );
		if ( parsed.kind === 'amount' ) {
			rows.push( { character_id: Number( id ), amount: parsed.amount } );
		}
		return rows;
	}, [] );

	if ( awards.length === 0 ) {
		return null;
	}

	return { reason: trimmedReason, awards };
}

/**
 * The held entries and per-row notes after an apply-XP response: an applied row's box clears and its note says how
 * much was applied, a refused row keeps its amount and shows why, and each applied row's fresh totals come back for
 * the list to show.
 */
export interface XpApplyOutcome {
	entries: Record< number, string >;
	notes: Record< number, string >;
	totals: Record< number, { xp_earned: number; xp_unspent: number } >;
}

/**
 * Folds an apply-XP response back onto the held entries.
 */
export function applyXpResults(
	entries: Record< number, string >,
	results: XpApplyResult[]
): XpApplyOutcome {
	const nextEntries: Record< number, string > = { ...entries };
	const notes: Record< number, string > = {};
	const totals: XpApplyOutcome[ 'totals' ] = {};

	for ( const result of results ) {
		if ( result.applied ) {
			delete nextEntries[ result.character_id ];
			const amount = result.amount ?? 0;
			notes[ result.character_id ] = sprintf(
				/* translators: %s: the signed amount that was just applied, e.g. "+5" or "-3" */
				__( '%s applied', 'beyond-elysium' ),
				amount > 0 ? `+${ amount }` : String( amount )
			);
			if (
				result.xp_earned !== undefined &&
				result.xp_unspent !== undefined
			) {
				totals[ result.character_id ] = {
					xp_earned: result.xp_earned,
					xp_unspent: result.xp_unspent,
				};
			}
		} else {
			notes[ result.character_id ] =
				result.message ??
				__( 'Could not be applied.', 'beyond-elysium' );
		}
	}

	return { entries: nextEntries, notes, totals };
}
