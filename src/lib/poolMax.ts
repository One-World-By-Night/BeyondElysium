/**
 * The ceiling a resource pool's dot tracker offers.
 */
import type { ResourcePoolValue } from './displayTemper';

/**
 * A pool's declared maximum when it has one.
 */
export function trackerMax(
	max: number | undefined,
	value: ResourcePoolValue
): number {
	if ( typeof max === 'number' ) {
		return max;
	}
	return Math.max( value.permanent + 1, value.temporary + 1, 10 );
}
