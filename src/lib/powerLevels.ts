/**
 * Reads a `tiered_power` family's levels out of the three containers the 1.2.10 schema
 * splits them into - `levels` (the declared ladder), `elder` (picks, keyed by rank) and
 * `overflow` (ladder-rank levels beyond the ladder).
 *
 * **TypeScript twin of `Services\Power_Levels`** (PHP), kept deliberately identical so the
 * character sheet and the signed PDF cannot disagree about which powers a family has - the
 * same twin discipline `Trait_Display`/`Power_Display` and their `src/lib` counterparts have
 * followed since the signed-sheet work.
 *
 * **Why this exists.** The split is the D68 fix and it must stay a split: the stepper reads
 * the ladder, the pick list reads the picks. But a *renderer* legitimately wants every named
 * power regardless of container - it has a held `power_name` and needs that level's real tier.
 * Before this helper, both renderers searched `levels` alone, so the moment a block was
 * seeded in the split shape every pick above `elder` silently lost its rank and rendered as
 * the hardcoded `'elder'` fallback: measured on the real catalog, `Celerity: Zephyr`
 * (ascended) and `Animalism: Stampede` (master) both printed "(elder)" on screen and in the
 * signed PDF. Same defect class as D77, which was the pricing half of the identical mistake.
 *
 * So: `allLevels()` for "every name this family has", `ladderLevels()` for "the rating". A
 * consumer reading `power.levels` directly is a consumer that has picked one and meant the
 * other.
 */
import type { PowerLevel, TieredPower } from '../types';

/**
 * Every level in a family, ladder then picks then overflow.
 *
 * Rank keys inside `elder` are deliberately **flattened here and only here** - a caller that
 * needs to know which rank a pick belongs to must read `elder` itself, because merging those
 * two depths is what caused D68 in the first place. This function is for callers that
 * genuinely do not care.
 */
export function allLevels( power: TieredPower | undefined ): PowerLevel[] {
	if ( ! power ) {
		return [];
	}
	const picks = Object.values( power.elder ?? {} ).flat();
	return [ ...( power.levels ?? [] ), ...picks, ...( power.overflow ?? [] ) ];
}

/** The declared ladder alone - the rungs a rating counts. Never picks, never overflow. */
export function ladderLevels( power: TieredPower | undefined ): PowerLevel[] {
	return power?.levels ?? [];
}

/**
 * The rank a named pick sits under, read from `elder`'s own keys rather than from the
 * level's `tier` - the keys are the authority, since `elder` is the section and the keys
 * inside it are the ranks. Undefined when the name is not a pick in this family.
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
