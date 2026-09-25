/**
 * Resolves `CrossBlockRef` lookups against a character's sheet data.
 */
import type {
	CrossBlockRef,
	NameLookup,
	ResourcePool,
	TemplateLayoutSection,
} from '../types';
import type { ResourcePoolValue } from './displayTemper';

/**
 * Reads the value at `ref.block_slug`/`ref.field` from a character's sheet data and returns it as a string.
 *
 * @return The resolved value as a string, or `null` if the block/field isn't present or
 *         hasn't been set yet (e.g. no Morality Path chosen).
 */
export function resolveCrossBlockValue(
	ref: CrossBlockRef,
	sheetData: Record< string, unknown >
): string | null {
	const blockData = sheetData[ ref.block_slug ];
	if (
		! blockData ||
		typeof blockData !== 'object' ||
		Array.isArray( blockData )
	) {
		return null;
	}

	const value = ( blockData as Record< string, unknown > )[ ref.field ];
	if ( value === null || value === undefined || value === '' ) {
		return null;
	}

	if ( typeof value === 'object' && 'permanent' in ( value as object ) ) {
		return String( ( value as ResourcePoolValue ).permanent );
	}

	return String( value );
}

/**
 * Builds a section's displayed title.
 */
export function resolveSectionTitle(
	section: TemplateLayoutSection,
	sheetData: Record< string, unknown >
): string {
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
 * Resolves a resource pool's displayed name: returns `pool.name` unless its `name_lookup` names it.
 */
export function resolvePoolName(
	pool: ResourcePool,
	sheetData: Record< string, unknown >
): string {
	if ( ! pool.name_lookup ) {
		return pool.name;
	}
	return resolveNameLookup( pool.name_lookup, sheetData ) ?? pool.name;
}

/**
 * The name a lookup gives for a character: its table's entry for the current value of `keyed_by`, else its `unmatched`
 * name when that value is set but not in the table, else whatever its `otherwise` lookup gives, else null.
 */
export function resolveNameLookup(
	lookup: NameLookup,
	sheetData: Record< string, unknown >
): string | null {
	const key = resolveCrossBlockValue( lookup.keyed_by, sheetData );
	if ( key !== null ) {
		const found = lookup.ignore_words
			? looseTableValue( lookup.table, key, lookup.ignore_words )
			: lookup.table[ key ];
		if ( found !== undefined ) {
			return found;
		}
		if ( lookup.unmatched !== undefined ) {
			return lookup.unmatched;
		}
	}
	return lookup.otherwise
		? resolveNameLookup( lookup.otherwise, sheetData )
		: null;
}

/**
 * A name reduced for loose comparison: lower case, a trailing parenthetical dropped, anything but letters and digits
 * read as a space, and the ignored words removed.
 */
export function looseName( name: string, ignoreWords: string[] ): string {
	const ignore = new Set( ignoreWords.map( ( word ) => word.toLowerCase() ) );
	return name
		.toLowerCase()
		.replace( /\s*\([^()]*\)\s*$/, '' )
		.split( /[^a-z0-9]+/ )
		.filter( ( word ) => word !== '' && ! ignore.has( word ) )
		.join( ' ' );
}

/**
 * The table entry whose key reads the same as `key` once both are reduced by `looseName()`.
 */
function looseTableValue(
	table: Record< string, string >,
	key: string,
	ignoreWords: string[]
): string | undefined {
	const wanted = looseName( key, ignoreWords );
	if ( wanted === '' ) {
		return undefined;
	}
	for ( const [ entry, value ] of Object.entries( table ) ) {
		if ( looseName( entry, ignoreWords ) === wanted ) {
			return value;
		}
	}
	return undefined;
}
