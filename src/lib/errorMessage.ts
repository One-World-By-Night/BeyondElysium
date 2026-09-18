/**
 * Surfaces the server's own REST error message directly, falling back to a caller-given
 * message when the caught value carries none - the exact same shape 30 components each
 * hand-rolled independently (some with a fixed fallback baked in, some parameterized)
 * until this was extracted (1.1.1 audit).
 */
export interface RestError {
	message?: string;
}

export function errorMessage( error: unknown, fallback: string ): string {
	if (
		typeof error === 'object' &&
		error !== null &&
		( error as RestError ).message
	) {
		return ( error as RestError ).message as string;
	}
	return fallback;
}
