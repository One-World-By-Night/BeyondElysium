/**
 * Groups a block's held trait rows into nested group/subgroup buckets, driven by
 * per-item `group`/`subgroup` fields on the block's catalog definition rather than a
 * fixed category list. Exports `groupTraitsByField()` (also as the default export) and
 * the `NestedTraitGroup` shape it returns.
 */
import type { TraitListDefinition } from '../types';

export interface NestedTraitGroup<T> {
	group: string;
	subgroups: { subgroup: string | null; items: T[] }[];
}

/**
 * Groups `data` rows by the `group` and `subgroup` of the matching catalog item (looked
 * up by name in `definition.items`), falling back to "Other" for a row whose catalog
 * item has no group. Returns null when no catalog item declares a `group` at all, so
 * callers can fall back to their own default grouping. Groups and subgroups are each
 * sorted alphabetically in the result.
 */
export function groupTraitsByField<T extends { name: string }>(
	data: T[],
	definition: TraitListDefinition
): NestedTraitGroup<T>[] | null {
	if ( ! definition.items.some( ( item ) => !! item.group ) ) {
		return null;
	}

	const catalogByName = new Map( definition.items.map( ( item ) => [ item.name, item ] ) );
	const groups = new Map<string, Map<string, T[]>>();

	for ( const row of data ) {
		const catalogItem = catalogByName.get( row.name );
		const group = catalogItem?.group ?? 'Other';
		const subgroup = catalogItem?.subgroup ?? '';
		if ( ! groups.has( group ) ) {
			groups.set( group, new Map() );
		}
		const bySubgroup = groups.get( group ) as Map<string, T[]>;
		bySubgroup.set( subgroup, [ ...( bySubgroup.get( subgroup ) ?? [] ), row ] );
	}

	return [ ...groups.entries() ]
		.sort( ( a, b ) => a[ 0 ].localeCompare( b[ 0 ] ) )
		.map( ( [ group, bySubgroup ] ) => ( {
			group,
			subgroups: [ ...bySubgroup.entries() ]
				.sort( ( a, b ) => a[ 0 ].localeCompare( b[ 0 ] ) )
				.map( ( [ subgroup, items ] ) => ( { subgroup: subgroup || null, items } ) ),
		} ) );
}

export default groupTraitsByField;
