/**
 * Type definitions for the public character-verification endpoint: resolving a short attestation code to what was
 * attested at export/signing time, plus whether it still matches the character today.
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
 * The stored snapshot an attestation certifies.
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
 * Live comparison of the attested snapshot against the character today.
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
	/**
	 * Never `'item'` - that value is reserved for `VerifyItemResponse`'s own discriminant.
	 */
	kind: 'gex' | 'pdf' | 'transfer';
	attested: VerifyAttested;
	still_matches?: VerifyStillMatches;
	as_of?: string;
}

/**
 * Live comparison of an item's attested snapshot against the item today.
 */
export interface VerifyItemStillMatches {
	holder: boolean;
	uses_left: boolean;
	expiry: boolean;
}

/**
 * An item's own live state, independent of whether it still matches what was attested.
 */
export interface VerifyItemCurrent {
	expired: boolean;
	used_up: boolean;
}

/**
 * The item-shaped response from `GET /be/v1/verify/{code}`.
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
 * The full response from `GET /be/v1/verify/{code}`.
 */
export type VerifyResponse = VerifyCharacterResponse | VerifyItemResponse;
