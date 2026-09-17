/**
 * Type definitions for the public character-verification endpoint
 * (GX-7): resolving a short attestation code to what was attested
 * at export/signing time, plus whether it still matches the
 * character today.
 */

/**
 * The chronicle that issued an attestation.
 */
export interface VerifyIssuer {
	chronicle: string;
	slug: string;
	site: string;
}

/**
 * The stored snapshot an attestation certifies - captured once, at
 * issue time, never re-derived live.
 */
export interface VerifyAttested {
	name: string;
	stack: string;
	status: string;
	xp_earned: number;
	xp_unspent: number;
	sheet_hash: string;
}

/**
 * Live comparison of the attested snapshot against the character
 * today. Present only when the attestation is valid and not
 * revoked - a revoked attestation's response omits this entirely.
 */
export interface VerifyStillMatches {
	name: boolean;
	status: boolean;
	xp_earned: boolean;
	xp_unspent: boolean;
	sheet: boolean;
}

/**
 * The character-shaped response from `GET /be/v1/verify/{code}`.
 */
export interface VerifyCharacterResponse {
	valid: boolean;
	revoked: boolean;
	issuer: VerifyIssuer;
	issued_at: string;
	/** Never `'item'` - that value is reserved for `VerifyItemResponse`'s own discriminant. */
	kind: 'gex' | 'pdf' | 'transfer';
	attested: VerifyAttested;
	still_matches?: VerifyStillMatches;
	as_of?: string;
}

/**
 * Live comparison of an item's attested snapshot against the item
 * today (1.1.0 §3.13). Present only when the attestation is not
 * revoked.
 */
export interface VerifyItemStillMatches {
	holder: boolean;
	uses_left: boolean;
	expiry: boolean;
}

/**
 * An item's own live state, independent of whether it still matches
 * what was attested - an item can match its own printed snapshot and
 * still have since expired or run out of uses.
 */
export interface VerifyItemCurrent {
	expired: boolean;
	used_up: boolean;
}

/**
 * The item-shaped response from `GET /be/v1/verify/{code}` (1.1.0
 * §3.13) - public like a character's own: never the item's
 * description or powers, never a player's name. `kind` is always the
 * literal `'item'`, which never collides with a character response's
 * own `kind` values (`gex`/`pdf`/`transfer`), so the two shapes can be
 * told apart by that field alone.
 */
export interface VerifyItemResponse {
	kind: 'item';
	name: string;
	chronicle: string;
	issued_at: string;
	revoked: boolean;
	still_matches?: VerifyItemStillMatches;
	current?: VerifyItemCurrent;
}

/**
 * The full response from `GET /be/v1/verify/{code}`. An unknown,
 * expired, or malformed code never reaches this shape at all - the
 * request itself fails (a bare 404, no distinguishing detail).
 */
export type VerifyResponse = VerifyCharacterResponse | VerifyItemResponse;
