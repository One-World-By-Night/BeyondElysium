/**
 * What makes one held `trait_list` row the same holding as another.
 */
import type { TraitListDefinition } from '../types';

/**
 * The separator inside a composite identity.
 */
const SEPARATOR = '\u0000';

/**
 * One row or draft, as far as identity is concerned.
 */
export interface IdentifiableTrait {
	name: string;
	specialization?: string;
}

/**
 * Whether one catalog item may be held more than once, each holding labelled by its own specialization.
 */
export function allowsMultiples(
	definition: TraitListDefinition,
	name: string
): boolean {
	const item = definition.items?.find(
		( candidate ) => candidate.name === name
	);
	if ( item && typeof item.allow_multiples === 'boolean' ) {
		return item.allow_multiples;
	}
	return !! definition.allow_multiples;
}

/**
 * The identity of one held row: its `name` alone, or the name and its label joined by a NUL when the item is
 * multiples-capable.
 */
export function traitRowIdentity(
	definition: TraitListDefinition,
	row: IdentifiableTrait
): string {
	return allowsMultiples( definition, row.name )
		? `${ row.name }${ SEPARATOR }${ row.specialization ?? '' }`
		: row.name;
}

/**
 * Which label the editor should ask for, if any, for a drafted name on this block.
 */
export function labelPrompt(
	definition: TraitListDefinition,
	name: string
): 'specialization' | 'who_or_what' | null {
	if ( definition.has_specializations ) {
		return 'specialization';
	}
	if ( name !== '' && allowsMultiples( definition, name ) ) {
		return 'who_or_what';
	}
	return null;
}
