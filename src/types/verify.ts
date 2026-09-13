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
 * The full response from `GET /be/v1/verify/{code}`. An unknown,
 * expired, or malformed code never reaches this shape at all - the
 * request itself fails (a bare 404, no distinguishing detail).
 */
export interface VerifyResponse {
	valid: boolean;
	revoked: boolean;
	issuer: VerifyIssuer;
	issued_at: string;
	kind: string;
	attested: VerifyAttested;
	still_matches?: VerifyStillMatches;
	as_of?: string;
}
