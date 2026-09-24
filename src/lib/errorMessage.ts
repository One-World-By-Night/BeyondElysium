/**
 * Surfaces the server's own REST error message directly, falling back to a caller-given message when the caught value
 * carries none.
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
