/**
 * Diffs a character's sheet data against its pre-edit baseline and emits `Change` payloads shaped to match what the
 * server-side change engine reads.
 */

import type { SchemaBlock, TraitListDefinition } from '../types';
import { allowsMultiples, traitRowIdentity } from './traitIdentity';
import type { ChangeRequest, SheetData } from '../types/character';
import type { EditableTrait } from '../components/editors/TraitListEditor';
import type { EditableHeldPower } from '../components/editors/TieredPowerEditor';
import type { ResourcePoolValue } from './displayTemper';
import type { IdentityFieldValue } from '../components/editors/IdentityFieldEditor';

export type { SheetData };

/**
 * Pairs held rows across the two sheets, in two passes.
 */
function pairTraitRows(
	definition: TraitListDefinition,
	original: EditableTrait[],
	current: EditableTrait[]
): {
	pairs: Array< [ EditableTrait, EditableTrait ] >;
	added: EditableTrait[];
	removed: EditableTrait[];
} {
	const pairs: Array< [ EditableTrait, EditableTrait ] > = [];
	const origLeft = [ ...original ];
	const curLeft = [ ...current ];

	const take = (
		pool: EditableTrait[],
		match: ( row: EditableTrait ) => boolean
	) => {
		const at = pool.findIndex( match );
		return at === -1 ? null : pool.splice( at, 1 )[ 0 ];
	};

	for ( const row of [ ...origLeft ] ) {
		const identity = traitRowIdentity( definition, row );
		const partner = take(
			curLeft,
			( candidate ) =>
				traitRowIdentity( definition, candidate ) === identity
		);
		if ( partner ) {
			take( origLeft, ( candidate ) => candidate === row );
			pairs.push( [ row, partner ] );
		}
	}

	for ( const row of [ ...origLeft ] ) {
		const partner = take(
			curLeft,
			( candidate ) => candidate.name === row.name
		);
		if ( partner ) {
			take( origLeft, ( candidate ) => candidate === row );
			pairs.push( [ row, partner ] );
		}
	}

	return { pairs, added: curLeft, removed: origLeft };
}

/**
 * Diffs an original and current trait list into add/remove/modify change requests, matching entries by identity.
 */
function diffTraitList(
	blockSlug: string,
	definition: TraitListDefinition,
	original: EditableTrait[],
	current: EditableTrait[]
): ChangeRequest[] {
	const changes: ChangeRequest[] = [];
	const effectiveCurrent = current.filter( ( row ) => ! row._removed );

	const { pairs, added, removed } = pairTraitRows(
		definition,
		original,
		effectiveCurrent
	);

	for ( const [ o, c ] of pairs ) {
		const name = c.name;
		const changed: Partial< EditableTrait > = {};
		if ( ( o.count ?? 1 ) !== ( c.count ?? 1 ) ) {
			changed.count = c.count ?? 1;
		}
		if ( ( o.specialization ?? '' ) !== ( c.specialization ?? '' ) ) {
			changed.specialization = c.specialization;
		}
		if ( ( o.note ?? '' ) !== ( c.note ?? '' ) ) {
			changed.note = c.note;
		}
		if ( o.chosen_cost !== c.chosen_cost ) {
			changed.chosen_cost = c.chosen_cost;
		}
		if ( Object.keys( changed ).length > 0 ) {
			changes.push( {
				change_type: 'modify_trait',
				category: blockSlug,
				change_data: {
					block_slug: blockSlug,
					trait: { name, ...changed },
					previous: {
						name: o.name,
						count: o.count ?? 1,
						specialization: o.specialization,
						note: o.note,
						chosen_cost: o.chosen_cost,
					},
				},
			} );
		}
	}

	for ( const row of added ) {
		changes.push( {
			change_type: 'add_trait',
			category: blockSlug,
			change_data: {
				block_slug: blockSlug,
				trait: {
					name: row.name,
					count: row.count ?? 1,
					...( row.specialization
						? { specialization: row.specialization }
						: {} ),
					...( row.note ? { note: row.note } : {} ),
					...( row.custom ? { custom: true } : {} ),
					...( row.chosen_cost !== undefined
						? { chosen_cost: row.chosen_cost }
						: {} ),
				},
			},
		} );
	}

	// A removal must name WHICH holding is leaving wherever the label is part of what identifies it.
	for ( const row of removed ) {
		const labelled =
			allowsMultiples( definition, row.name ) && row.specialization
				? { specialization: row.specialization }
				: {};
		changes.push( {
			change_type: 'remove_trait',
			category: blockSlug,
			change_data: {
				block_slug: blockSlug,
				trait: { name: row.name, ...labelled },
			},
		} );
	}

	return changes;
}

/**
 * Identity key for one held tiered-power row: the family name alone for a plain numbered holding (at most one per
 * family), or name+power_name for an Elder-and-above pick.
 */
function tieredPowerKey( row: EditableHeldPower ): string {
	return row.power_name ? `${ row.name }\u0000${ row.power_name }` : row.name;
}

/**
 * Diffs an original and current tiered-power list into add/remove/modify change requests, matching entries by
 * tieredPowerKey().
 */
function diffTieredPower(
	blockSlug: string,
	original: EditableHeldPower[],
	current: EditableHeldPower[]
): ChangeRequest[] {
	const changes: ChangeRequest[] = [];
	const effectiveCurrent = current.filter( ( row ) => ! row._removed );

	const origByKey = new Map(
		original.map( ( row ) => [ tieredPowerKey( row ), row ] )
	);
	const curByKey = new Map(
		effectiveCurrent.map( ( row ) => [ tieredPowerKey( row ), row ] )
	);
	const allKeys = new Set( [ ...origByKey.keys(), ...curByKey.keys() ] );

	// Tradition is carried through verbatim and omitted when absent.
	const traitOf = ( row: EditableHeldPower ) => {
		const trait: {
			name: string;
			level?: number;
			power_name?: string;
			tradition?: string;
		} = {
			name: row.name,
			level: row.level,
		};
		if ( row.power_name ) {
			trait.power_name = row.power_name;
		}
		if ( row.tradition ) {
			trait.tradition = row.tradition;
		}
		return trait;
	};

	for ( const key of allKeys ) {
		const o = origByKey.get( key );
		const c = curByKey.get( key );

		if ( o && c ) {
			if ( o.level !== c.level || o.tradition !== c.tradition ) {
				// A cleared tradition is sent as an explicit empty string.
				const trait = traitOf( c );
				if ( o.tradition && ! c.tradition ) {
					( trait as { tradition?: string } ).tradition = '';
				}
				changes.push( {
					change_type: 'modify_trait',
					category: blockSlug,
					change_data: {
						block_slug: blockSlug,
						trait,
						previous: traitOf( o ),
					},
				} );
			}
		} else if ( c ) {
			changes.push( {
				change_type: 'add_trait',
				category: blockSlug,
				change_data: { block_slug: blockSlug, trait: traitOf( c ) },
			} );
		} else if ( o ) {
			// A removal must name which specific pick is leaving when the family holds more than one.
			const trait: { name: string; power_name?: string } = {
				name: o.name,
			};
			if ( o.power_name ) {
				trait.power_name = o.power_name;
			}
			changes.push( {
				change_type: 'remove_trait',
				category: blockSlug,
				change_data: { block_slug: blockSlug, trait },
			} );
		}
	}

	return changes;
}

/**
 * Whether two resource-pool values have equal permanent and temporary amounts.
 */
function poolsEqual(
	a: ResourcePoolValue | undefined,
	b: ResourcePoolValue | undefined
): boolean {
	return (
		( a?.permanent ?? 0 ) === ( b?.permanent ?? 0 ) &&
		( a?.temporary ?? 0 ) === ( b?.temporary ?? 0 )
	);
}

/**
 * Diffs an original and current map of resource-pool values, returning one `modify_resource` change per pool whose
 * permanent or temporary amount differs.
 */
function diffResourcePool(
	blockSlug: string,
	original: Record< string, ResourcePoolValue >,
	current: Record< string, ResourcePoolValue >
): ChangeRequest[] {
	const changes: ChangeRequest[] = [];
	const allPools = new Set( [
		...Object.keys( original ),
		...Object.keys( current ),
	] );

	for ( const poolName of allPools ) {
		if ( ! poolsEqual( original[ poolName ], current[ poolName ] ) ) {
			changes.push( {
				change_type: 'modify_resource',
				category: blockSlug,
				change_data: {
					block_slug: blockSlug,
					values: { [ poolName ]: current[ poolName ] },
				},
			} );
		}
	}

	return changes;
}

/**
 * Whether two identity-field values are equal, comparing array values by content.
 */
function valuesEqual( a: IdentityFieldValue, b: IdentityFieldValue ): boolean {
	if ( Array.isArray( a ) || Array.isArray( b ) ) {
		return JSON.stringify( a ?? [] ) === JSON.stringify( b ?? [] );
	}
	return ( a ?? '' ) === ( b ?? '' );
}

/**
 * Diffs an original and current map of identity-field values, returning one `modify_identity` change per field whose
 * value differs.
 */
function diffIdentityField(
	blockSlug: string,
	original: Record< string, IdentityFieldValue >,
	current: Record< string, IdentityFieldValue >
): ChangeRequest[] {
	const changes: ChangeRequest[] = [];
	const allFields = new Set( [
		...Object.keys( original ),
		...Object.keys( current ),
	] );

	for ( const fieldName of allFields ) {
		if ( ! valuesEqual( original[ fieldName ], current[ fieldName ] ) ) {
			changes.push( {
				change_type: 'modify_identity',
				category: blockSlug,
				change_data: {
					block_slug: blockSlug,
					fields: { [ fieldName ]: current[ fieldName ] },
				},
			} );
		}
	}

	return changes;
}

/**
 * Diffs every block a stack declares against its current values, dispatching to the matching diff routine by the
 * block's `section_type`.
 */
export function computeChanges(
	original: SheetData,
	current: SheetData,
	blocks: Record< string, SchemaBlock >
): ChangeRequest[] {
	const changes: ChangeRequest[] = [];

	for ( const [ blockSlug, block ] of Object.entries( blocks ) ) {
		switch ( block.section_type ) {
			case 'trait_list':
				changes.push(
					...diffTraitList(
						blockSlug,
						( block.definition as TraitListDefinition ) ?? {
							items: [],
						},
						( original[ blockSlug ] as
							| EditableTrait[]
							| undefined ) ?? [],
						( current[ blockSlug ] as
							| EditableTrait[]
							| undefined ) ?? []
					)
				);
				break;

			case 'tiered_power':
				changes.push(
					...diffTieredPower(
						blockSlug,
						( original[ blockSlug ] as
							| EditableHeldPower[]
							| undefined ) ?? [],
						( current[ blockSlug ] as
							| EditableHeldPower[]
							| undefined ) ?? []
					)
				);
				break;

			case 'resource_pool':
				changes.push(
					...diffResourcePool(
						blockSlug,
						( original[ blockSlug ] as
							| Record< string, ResourcePoolValue >
							| undefined ) ?? {},
						( current[ blockSlug ] as
							| Record< string, ResourcePoolValue >
							| undefined ) ?? {}
					)
				);
				break;

			case 'identity_field':
				changes.push(
					...diffIdentityField(
						blockSlug,
						( original[ blockSlug ] as
							| Record< string, IdentityFieldValue >
							| undefined ) ?? {},
						( current[ blockSlug ] as
							| Record< string, IdentityFieldValue >
							| undefined ) ?? {}
					)
				);
				break;

			default:
				break;
		}
	}

	return changes;
}
