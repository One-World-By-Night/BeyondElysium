/**
 * Turns a catalog's own `group`/`subgroup` fields into the sections a pick list renders.
 */
import { __ } from '@wordpress/i18n';
import type { OptionGroup } from './searchableSelect';

/**
 * Only the fields grouping reads.
 */
export interface GroupableItem {
	name: string;
	group?: string;
	subgroup?: string;
}

/**
 * The heading for a section nested inside another.
 */
function nestedLabel( group: string, subgroup: string ): string {
	return `${ group } \u{203A} ${ subgroup }`;
}

/**
 * Sections a catalog's items by `group`.
 *
 * @param items     The catalog's own items, in catalog order.
 * @param preferred Group names to sort to the front, in the order given - a character's
 *                  own breed, auspice and tribe. Everything else keeps catalog order
 *                  behind them. Nothing is ever removed.
 */
export function groupCatalogItems(
	items: GroupableItem[],
	preferred: string[] = []
): OptionGroup[] {
	const grouped = new Map< string, Map< string, string[] > >();
	const ungrouped: string[] = [];

	for ( const item of items ) {
		const group = ( item.group ?? '' ).trim();
		if ( group === '' ) {
			ungrouped.push( item.name );
			continue;
		}
		const subgroup = ( item.subgroup ?? '' ).trim();
		if ( ! grouped.has( group ) ) {
			grouped.set( group, new Map() );
		}
		const subs = grouped.get( group ) as Map< string, string[] >;
		if ( ! subs.has( subgroup ) ) {
			subs.set( subgroup, [] );
		}
		( subs.get( subgroup ) as string[] ).push( item.name );
	}

	if ( grouped.size === 0 ) {
		return [];
	}

	// A preferred group keeps its catalog contents untouched; only its position moves.
	const order = Array.from( grouped.keys() );
	const rank = ( group: string ) => {
		const at = preferred.findIndex(
			( name ) => name.toLowerCase() === group.toLowerCase()
		);
		return at === -1 ? preferred.length : at;
	};
	order.sort( ( a, b ) => rank( a ) - rank( b ) );

	const sections: OptionGroup[] = [];
	for ( const group of order ) {
		const subs = grouped.get( group ) as Map< string, string[] >;
		for ( const [ subgroup, names ] of subs ) {
			sections.push( {
				// The un-subgrouped remainder of a group sits under the group's own bare name.
				label: subgroup === '' ? group : nestedLabel( group, subgroup ),
				options: names,
			} );
		}
	}

	if ( ungrouped.length > 0 ) {
		// `mage-rotes` has 134 of these.
		sections.push( {
			label: __( 'Ungrouped', 'beyond-elysium' ),
			options: ungrouped,
		} );
	}

	return sections;
}
