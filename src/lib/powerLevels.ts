/**
 * Reads a `tiered_power` family's levels out of its three containers: `levels` (the declared ladder), `elder` (picks,
 * keyed by rank) and `overflow` (ladder-rank levels beyond the ladder). The TypeScript twin of `Power_Levels`.
 */
import type { PowerLevel, TieredPower } from '../types';

/**
 * Every level in a family, ladder then picks then overflow.
 */
export function allLevels( power: TieredPower | undefined ): PowerLevel[] {
	if ( ! power ) {
		return [];
	}
	const picks = Object.values( power.elder ?? {} ).flat();
	return [ ...( power.levels ?? [] ), ...picks, ...( power.overflow ?? [] ) ];
}

/**
 * The declared ladder alone.
 */
export function ladderLevels( power: TieredPower | undefined ): PowerLevel[] {
	return power?.levels ?? [];
}

/**
 * The rank a named pick sits under, read from `elder`'s own keys.
 */
export function pickRank(
	power: TieredPower | undefined,
	powerName: string
): string | undefined {
	for ( const [ rank, levels ] of Object.entries( power?.elder ?? {} ) ) {
		if ( levels.some( ( level ) => level.power_name === powerName ) ) {
			return rank;
		}
	}
	return undefined;
}
