/**
 * How a pool's temporary points behave: the ordinary kind that spend down from the rating and regain up to it, none at
 * all, or a count that builds up past the rating.
 */
import type { ResourcePool } from '../types';
import type { ResourcePoolValue } from './displayTemper';

export type PoolKind = 'ordinary' | 'rating_only' | 'accumulates';

export function poolKind(
	pool: Pick< ResourcePool, 'rating_only' | 'accumulates' >
): PoolKind {
	if ( pool.rating_only === true ) {
		return 'rating_only';
	}
	return pool.accumulates === true ? 'accumulates' : 'ordinary';
}

/**
 * The most a build-up pool's temporary count reaches: its own maximum, or undefined when it has none.
 */
export function poolCeiling(
	pool: Pick< ResourcePool, 'max' >
): number | undefined {
	return typeof pool.max === 'number' ? pool.max : undefined;
}

/**
 * The value a pool shows: a pool with no temporary points shows its rating alone, whatever a sheet stored for the
 * temporary one.
 */
export function shownPoolValue(
	kind: PoolKind,
	value: ResourcePoolValue
): ResourcePoolValue {
	return kind === 'rating_only'
		? { ...value, temporary: value.permanent }
		: value;
}
