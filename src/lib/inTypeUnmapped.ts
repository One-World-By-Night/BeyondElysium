import type { InTypeSource } from '../types';

/**
 * Whether a map source lets a value it does not list pass every family.
 */
export function passesUnmapped( source: InTypeSource | undefined ): boolean {
	return !! source && 'map' in source && source.unmapped === 'pass';
}

/**
 * A map source that passes, or stops passing, a value it does not list. Any other source comes back as it is.
 */
export function withUnmapped(
	source: InTypeSource,
	pass: boolean
): InTypeSource {
	if ( ! ( 'map' in source ) ) {
		return source;
	}
	const next = { ...source };
	delete next.unmapped;
	return pass ? { ...next, unmapped: 'pass' } : next;
}
