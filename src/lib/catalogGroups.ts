/**
 * Turns a catalog's own `group`/`subgroup` fields into the sections a pick list renders
 * (1.2.9 U4).
 *
 * The problem this solves, measured against the real seeded catalog: a Fera player picks
 * a Gift from **865 unsorted names in one list**, and a Werewolf player from 510, while
 * every one of those items has carried its species or tribe since v0.21.14. The data was
 * always there; nothing ever read it.
 *
 * | Block | Items | `group` | `subgroup` | Ungrouped |
 * | --- | ---: | ---: | ---: | ---: |
 * | `fera-gifts` | 865 | 12 species | 65 factions | 0 |
 * | `mage-rotes` | 804 | 17 themes | - | 134 |
 * | `werewolf-gifts` | 510 | 29 breed/auspice/tribe | - | 0 |
 *
 * **Grouping is a sort, never a filter.** Every section is always present and always
 * searchable; `preferred` only changes the order. An out-of-type Gift is legal - LotW
 * Revised charges **+1** for one outside breed, auspice or tribe, and a surcharge means
 * purchasable, not forbidden. A Homid/Galliard/Fianna character may take a Get of Fenris
 * gift; it costs 4 instead of 3, and that is `Cost_Engine`'s job, never a picker's.
 *
 * Whether a *specific* out-of-tribe gift is appropriate - Black Spiral Dancer gifts being
 * the obvious case - is a Storyteller's approval call, not the UI's (Decision 057).
 */
import { __ } from '@wordpress/i18n';
import type { OptionGroup } from './searchableSelect';

/** Only the fields grouping reads; anything with a name and these two qualifies. */
export interface GroupableItem {
	name: string;
	group?: string;
	subgroup?: string;
}

/**
 * The heading for a section nested inside another - "Ananasi › Viskr". A single level of
 * headings rather than a nested model, because two levels of real nesting would need the
 * dropdown's row list, its virtualization arithmetic and its keyboard navigation all to
 * understand depth, for no gain a reader of the list can see.
 */
function nestedLabel( group: string, subgroup: string ): string {
	return `${ group } \u{203A} ${ subgroup }`;
}

/**
 * Sections a catalog's items by `group`, then by `subgroup` within each group.
 *
 * Returns an empty array when nothing in the catalog carries a group at all - the signal
 * to a caller that this block has no grouping to offer and should stay flat, rather than
 * rendering one pointless section holding everything.
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
				// The un-subgrouped remainder of a group sits under the group's own bare
				// name, which is where a reader expects it - Ananasi's 35 general Gifts
				// before Ananasi › Viskr's 13.
				label: subgroup === '' ? group : nestedLabel( group, subgroup ),
				options: names,
			} );
		}
	}

	if ( ungrouped.length > 0 ) {
		// `mage-rotes` has 134 of these. Named rather than left headingless, so a player
		// can see it is a gap in the data and not a section someone forgot to label; it
		// goes last because it is the least useful place to look, never because it is
		// less available. 1.3.0 fills them.
		sections.push( {
			label: __( 'Ungrouped', 'beyond-elysium' ),
			options: ungrouped,
		} );
	}

	return sections;
}
