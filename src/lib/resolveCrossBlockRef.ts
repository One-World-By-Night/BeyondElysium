/**
 * Resolves `CrossBlockRef` lookups against a character's sheet data: reading a value
 * from another block/field pair, building a section's title from its configured title
 * refs, and resolving a resource pool's display name from a keyed lookup table. Exports
 * `resolveCrossBlockValue()`, `resolveSectionTitle()`, and `resolvePoolName()`.
 */
import type { CrossBlockRef, ResourcePool, TemplateLayoutSection } from '../types';
import type { ResourcePoolValue } from './displayTemper';

/**
 * Reads the value at `ref.block_slug`/`ref.field` from a character's sheet data and
 * returns it as a string. Handles both shapes block data can take: an identity field's
 * plain string or number, and a resource pool's `{permanent, temporary}` object, from
 * which only `permanent` is read.
 *
 * @return The resolved value as a string, or `null` if the block/field isn't present or
 *         hasn't been set yet (e.g. no Morality Path chosen).
 */
export function resolveCrossBlockValue( ref: CrossBlockRef, sheetData: Record<string, unknown> ): string | null {
	const blockData = sheetData[ ref.block_slug ];
	if ( ! blockData || typeof blockData !== 'object' || Array.isArray( blockData ) ) {
		return null;
	}

	const value = ( blockData as Record<string, unknown> )[ ref.field ];
	if ( value === null || value === undefined || value === '' ) {
		return null;
	}

	if ( typeof value === 'object' && 'permanent' in ( value as object ) ) {
		return String( ( value as ResourcePoolValue ).permanent );
	}

	return String( value );
}

/**
 * Builds a section's displayed title. Returns `title` alone when `title_refs` is unset
 * or any referenced value fails to resolve; otherwise returns `title` followed by every
 * resolved ref's value, space-joined.
 */
export function resolveSectionTitle( section: TemplateLayoutSection, sheetData: Record<string, unknown> ): string {
	if ( ! section.title_refs || section.title_refs.length === 0 ) {
		return section.title;
	}

	const resolved: string[] = [];
	for ( const ref of section.title_refs ) {
		const value = resolveCrossBlockValue( ref, sheetData );
		if ( value === null ) {
			return section.title;
		}
		resolved.push( value );
	}

	return [ section.title, ...resolved ].join( ' ' );
}

/**
 * Resolves a resource pool's displayed name: returns `pool.name` unless `name_lookup`
 * maps the current value of its `keyed_by` reference to an entry in `table`. Only the
 * displayed name changes - the pool's storage key (`pool.name` itself) is never
 * affected.
 */
export function resolvePoolName( pool: ResourcePool, sheetData: Record<string, unknown> ): string {
	if ( ! pool.name_lookup ) {
		return pool.name;
	}

	const key = resolveCrossBlockValue( pool.name_lookup.keyed_by, sheetData );
	if ( key === null ) {
		return pool.name;
	}

	return pool.name_lookup.table[ key ] ?? pool.name;
}
