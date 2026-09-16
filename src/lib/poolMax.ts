/**
 * The ceiling a resource pool's dot tracker offers (1.0.0-review F-079).
 */
import type { ResourcePoolValue } from './displayTemper';

/**
 * A pool's declared maximum when it has one. With none, ten dots, or one
 * more than the pool's highest value once it passes nine - so "+" is never
 * stuck against a ceiling the pool itself set.
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
