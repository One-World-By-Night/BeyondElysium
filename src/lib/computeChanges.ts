/**
 * Diffs a character's sheet data against its pre-edit baseline and emits `Change`
 * payloads shaped to match what the server-side change engine reads - a `block_slug`
 * plus a `trait`, `values`, or `fields` key depending on change type. Exports
 * `computeChanges()`, the entry point, plus a re-exported `SheetData` type; the
 * per-section-type diffing helpers are internal to this module.
 */

import type { SchemaBlock } from '../types';
import type { ChangeRequest, SheetData } from '../types/character';
import type { EditableTrait } from '../components/editors/TraitListEditor';
import type { EditableHeldPower } from '../components/editors/TieredPowerEditor';
import type { ResourcePoolValue } from './displayTemper';
import type { IdentityFieldValue } from '../components/editors/IdentityFieldEditor';

export type { SheetData };

/** Groups rows into a map keyed by `name`, preserving each row's order within its group. */
function groupByName<T extends { name: string }>( rows: T[] ): Map<string, T[]> {
	const groups = new Map<string, T[]>();
	for ( const row of rows ) {
		const bucket = groups.get( row.name ) ?? [];
		bucket.push( row );
		groups.set( row.name, bucket );
	}
	return groups;
}

/**
 * Diffs an original and current trait list into add/remove/modify change requests,
 * matching entries by name rather than array position so a reordered list produces no
 * changes. Duplicate names are paired off in arrival order via per-name queues rather
 * than colliding on a single map key.
 */
function diffTraitList( blockSlug: string, original: EditableTrait[], current: EditableTrait[] ): ChangeRequest[] {
	const changes: ChangeRequest[] = [];
	const effectiveCurrent = current.filter( ( row ) => ! row._removed );

	const origByName = groupByName( original );
	const curByName = groupByName( effectiveCurrent );
	const allNames = new Set( [ ...origByName.keys(), ...curByName.keys() ] );

	for ( const name of allNames ) {
		const origQueue = origByName.get( name ) ?? [];
		const curQueue = curByName.get( name ) ?? [];
		const pairCount = Math.min( origQueue.length, curQueue.length );

		for ( let i = 0; i < pairCount; i++ ) {
			const o = origQueue[ i ];
			const c = curQueue[ i ];
			const changed: Partial<EditableTrait> = {};
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
						// Display-only: the change-apply step only reads `.trait`, not this key.
						previous: { name, count: o.count ?? 1, specialization: o.specialization, note: o.note, chosen_cost: o.chosen_cost },
					},
				} );
			}
		}

		for ( let i = pairCount; i < curQueue.length; i++ ) {
			const row = curQueue[ i ];
			changes.push( {
				change_type: 'add_trait',
				category: blockSlug,
				change_data: {
					block_slug: blockSlug,
					trait: {
						name,
						count: row.count ?? 1,
						...( row.specialization ? { specialization: row.specialization } : {} ),
						...( row.note ? { note: row.note } : {} ),
						...( row.custom ? { custom: true } : {} ),
						...( row.chosen_cost !== undefined ? { chosen_cost: row.chosen_cost } : {} ),
					},
				},
			} );
		}

		for ( let i = pairCount; i < origQueue.length; i++ ) {
			changes.push( {
				change_type: 'remove_trait',
				category: blockSlug,
				change_data: { block_slug: blockSlug, trait: { name } },
			} );
		}
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
function diffTieredPower( blockSlug: string, original: EditableHeldPower[], current: EditableHeldPower[] ): ChangeRequest[] {
	const changes: ChangeRequest[] = [];
	const effectiveCurrent = current.filter( ( row ) => ! row._removed );

	const origByKey = new Map( original.map( ( row ) => [ tieredPowerKey( row ), row ] ) );
	const curByKey = new Map( effectiveCurrent.map( ( row ) => [ tieredPowerKey( row ), row ] ) );
	const allKeys = new Set( [ ...origByKey.keys(), ...curByKey.keys() ] );

	// Tradition is carried through verbatim and omitted when absent; power_name is always
	// included when present - it identifies which specific Elder-and-above pick this row
	// is, not a value that changes on an already-matched row (two different power_names
	// are two different keys above, never one row's power_name changing in place).
	const traitOf = ( row: EditableHeldPower ) => {
		const trait: { name: string; level?: number; power_name?: string; tradition?: string } = {
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
			const trait: { name: string; power_name?: string } = { name: o.name };
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
function poolsEqual( a: ResourcePoolValue | undefined, b: ResourcePoolValue | undefined ): boolean {
	return ( a?.permanent ?? 0 ) === ( b?.permanent ?? 0 ) && ( a?.temporary ?? 0 ) === ( b?.temporary ?? 0 );
}

/**
 * Diffs an original and current map of resource-pool values, returning one
 * `modify_resource` change per pool whose permanent or temporary amount differs. A pool
 * missing from one side is compared against a zeroed default.
 */
function diffResourcePool(
	blockSlug: string,
	original: Record<string, ResourcePoolValue>,
	current: Record<string, ResourcePoolValue>
): ChangeRequest[] {
	const changes: ChangeRequest[] = [];
	const allPools = new Set( [ ...Object.keys( original ), ...Object.keys( current ) ] );

	for ( const poolName of allPools ) {
		if ( ! poolsEqual( original[ poolName ], current[ poolName ] ) ) {
			changes.push( {
				change_type: 'modify_resource',
				category: blockSlug,
				change_data: { block_slug: blockSlug, values: { [ poolName ]: current[ poolName ] } },
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
	original: Record<string, IdentityFieldValue>,
	current: Record<string, IdentityFieldValue>
): ChangeRequest[] {
	const changes: ChangeRequest[] = [];
	const allFields = new Set( [ ...Object.keys( original ), ...Object.keys( current ) ] );

	for ( const fieldName of allFields ) {
		if ( ! valuesEqual( original[ fieldName ], current[ fieldName ] ) ) {
			changes.push( {
				change_type: 'modify_identity',
				category: blockSlug,
				change_data: { block_slug: blockSlug, fields: { [ fieldName ]: current[ fieldName ] } },
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
	blocks: Record<string, SchemaBlock>
): ChangeRequest[] {
	const changes: ChangeRequest[] = [];

	for ( const [ blockSlug, block ] of Object.entries( blocks ) ) {
		switch ( block.section_type ) {
			case 'trait_list':
				changes.push(
					...diffTraitList(
						blockSlug,
						( original[ blockSlug ] as EditableTrait[] | undefined ) ?? [],
						( current[ blockSlug ] as EditableTrait[] | undefined ) ?? []
					)
				);
				break;

			case 'tiered_power':
				changes.push(
					...diffTieredPower(
						blockSlug,
						( original[ blockSlug ] as EditableHeldPower[] | undefined ) ?? [],
						( current[ blockSlug ] as EditableHeldPower[] | undefined ) ?? []
					)
				);
				break;

			case 'resource_pool':
				changes.push(
					...diffResourcePool(
						blockSlug,
						( original[ blockSlug ] as Record<string, ResourcePoolValue> | undefined ) ?? {},
						( current[ blockSlug ] as Record<string, ResourcePoolValue> | undefined ) ?? {}
					)
				);
				break;

			case 'identity_field':
				changes.push(
					...diffIdentityField(
						blockSlug,
						( original[ blockSlug ] as Record<string, IdentityFieldValue> | undefined ) ?? {},
						( current[ blockSlug ] as Record<string, IdentityFieldValue> | undefined ) ?? {}
					)
				);
				break;

			default:
				break;
		}
	}

	return changes;
}
