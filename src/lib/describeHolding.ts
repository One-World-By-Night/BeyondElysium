/**
 * Formats a single held-state entry as a short human-readable string, e.g. "Iron Will ×5", "Auspex 1", "Necromancy:
 * Ash Path", "Generation: 9", "Willpower: 6 perm / 6 temp".
 */
import {
	withTradition,
	type HeldPower,
} from '../components/renderers/TieredPowerRenderer';

export type SectionTypeForHolding =
	| 'trait_list'
	| 'tiered_power'
	| 'resource_pool'
	| 'identity_field';

interface HeldTraitListEntry {
	name: string;
	count?: number;
}

interface HeldPoolValue {
	permanent?: number;
	temporary?: number;
}

/**
 * Describes one held trait_list entry: bare name, or "Name ×N" when held more than once.
 */
export function describeTraitListHolding( held: HeldTraitListEntry ): string {
	const count = held.count ?? 1;
	return count > 1 ? `${ held.name } ×${ count }` : held.name;
}

/**
 * Describes one held tiered_power entry: "Name: PowerName" for an Elder-and-above pick, "Name N" for a numbered
 * level, bare "Name" for neither.
 */
export function describeTieredPowerHolding( held: HeldPower ): string {
	let label = held.name;
	if ( held.power_name ) {
		label = `${ held.name }: ${ held.power_name }`;
	} else if ( held.level ) {
		label = `${ held.name } ${ held.level }`;
	}
	return withTradition( held, label );
}

/**
 * Describes one held resource_pool rating: "Name (permanent)".
 */
export function describeResourcePoolHolding(
	name: string,
	value: HeldPoolValue | number
): string {
	const permanent = typeof value === 'number' ? value : value.permanent ?? 0;
	return `${ name } (${ permanent })`;
}

/**
 * Describes one held identity_field value: "Name: value", or "Name: —" when unset.
 */
export function describeIdentityFieldHolding(
	name: string,
	value: unknown
): string {
	const display =
		value === null || value === undefined || value === ''
			? '—'
			: String( value );
	return `${ name }: ${ display }`;
}

/**
 * Dispatches on section type to the matching describe*Holding() function above.
 */
export function describeHolding(
	sectionType: SectionTypeForHolding,
	held: unknown
): string {
	switch ( sectionType ) {
		case 'trait_list':
			return describeTraitListHolding( held as HeldTraitListEntry );
		case 'tiered_power':
			return describeTieredPowerHolding( held as HeldPower );
		default:
			return String( held );
	}
}
