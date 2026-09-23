/**
 * Diffs a character's sheet data against its pre-edit baseline and emits `Change`
 * payloads shaped to match what the server-side change engine reads - a `block_slug`
 * plus a `trait`, `values`, or `fields` key depending on change type. Exports
 * `computeChanges()`, the entry point, plus a re-exported `SheetData` type; the
 * per-section-type diffing helpers are internal to this module.
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
 * Pairs held rows across the two sheets, in two passes (1.2.11 D88).
 *
 * Pass one matches rows of the same **identity** - the holding itself, which for an item that
 * allows multiples includes its label. Pass two pairs off whatever is left within each name,
 * in arrival order, which is what a relabel looks like: the identity moved, but it is still
 * the same holding being edited rather than one destroyed and another bought.
 *
 * Name-order pairing alone read "remove Retainers (John Doe)" as "relabel John to Sue, then
 * remove a Retainer" - and that removal named no label, so the engine deleted both holdings.
 * Identity pairing alone would read every relabel as a remove plus an add, refunding and
 * recharging a holding whose only change was its spelling.
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
 * Diffs an original and current trait list into add/remove/modify change requests, matching
 * entries by identity rather than array position so a reordered list produces no changes and
 * a holding that may legitimately be held twice is diffed as itself (`pairTraitRows`).
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
					/*
					 * Display-only, with one exception: `specialization` is how the server
					 * knows WHICH holding a relabel addresses, since the trait's own label is
					 * the new value (Trait_Identity::target_of, 1.2.11 D88). Everything else
					 * here is still only shown to the reviewer.
					 */
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

	// A removal must name WHICH holding is leaving wherever the label is part of what
	// identifies it - `{name}` alone is right for an ordinary trait and, on a multiples item,
	// would take every row of that name with it.
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
 * Identity key for one held tiered-power row: the family name alone for a plain
 * numbered holding (at most one per family), or name+power_name for an Elder-and-above
 * pick (Decision 037) - a family can hold several distinct Elder+ picks at once
 * (0.99.2-workflow.md: "you can have multiple powers at those levels"), so power_name
 * must be part of the identity rather than colliding on the shared family name. The
 * NUL separator can't appear in either field, so no real name can collide with it.
 */
function tieredPowerKey( row: EditableHeldPower ): string {
	return row.power_name ? `${ row.name }\u0000${ row.power_name }` : row.name;
}

/**
 * Diffs an original and current tiered-power list into add/remove/modify change
 * requests, matching entries by tieredPowerKey() rather than name alone - two rows
 * sharing a family name but naming different Elder-and-above picks are two independent
 * entries, never a "swap" of one into the other. A power's tradition is compared
 * alongside its level, and clearing a tradition is emitted as an explicit empty string
 * rather than an omitted key so the change is applied rather than dropped as a no-op.
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

	// Tradition is carried through verbatim and omitted when absent; power_name is always
	// included when present - it identifies which specific Elder-and-above pick this row
	// is, not a value that changes on an already-matched row (two different power_names
	// are two different keys above, never one row's power_name changing in place).
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
				// A cleared tradition is sent as an explicit empty string rather than an omitted key.
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
			// A removal must name which specific pick is leaving when the family holds
			// more than one - {name} alone (correct for a plain numbered holding) would
			// be ambiguous for an Elder-and-above pick with siblings under the same name.
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

/** Whether two resource-pool values have equal permanent and temporary amounts. */
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
 * Diffs an original and current map of resource-pool values, returning one
 * `modify_resource` change per pool whose permanent or temporary amount differs. A pool
 * missing from one side is compared against a zeroed default.
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

/** Whether two identity-field values are equal, comparing array values by content. */
function valuesEqual( a: IdentityFieldValue, b: IdentityFieldValue ): boolean {
	if ( Array.isArray( a ) || Array.isArray( b ) ) {
		return JSON.stringify( a ?? [] ) === JSON.stringify( b ?? [] );
	}
	return ( a ?? '' ) === ( b ?? '' );
}

/**
 * Diffs an original and current map of identity-field values, returning one
 * `modify_identity` change per field whose value differs. Array values are compared by
 * content rather than by reference.
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
 * Diffs every block a stack declares against its current values, dispatching to the
 * matching diff routine by the block's `section_type`. A block present in neither
 * `original` nor `current` produces no changes, and a block whose `section_type` isn't
 * recognized is skipped rather than throwing.
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
