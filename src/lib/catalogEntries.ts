/**
 * What the Catalog tab says about sections and entries, and how an entry's draft becomes the request that saves it.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { CatalogDescription } from '../types';
import type {
	CatalogEntryFlag,
	CatalogFamilyEntry,
	CatalogInTypeMap,
	CatalogItemEntry,
	CatalogOrigin,
	CatalogSection,
} from '../types/catalog';

/**
 * A list of more entries than this folds each group shut.
 */
export const FOLD_ABOVE = 50;

export type CatalogFilter = 'all' | 'added' | 'changed' | 'flagged';

/**
 * The filters, in the order the tab lists them.
 */
export const CATALOG_FILTERS: CatalogFilter[] = [
	'all',
	'added',
	'changed',
	'flagged',
];

/**
 * A filter's name on screen.
 */
export function filterLabel( filter: CatalogFilter ): string {
	switch ( filter ) {
		case 'added':
			return __( 'Added here', 'beyond-elysium' );
		case 'changed':
			return __( 'Changed here', 'beyond-elysium' );
		case 'flagged':
			return __( 'Needs a look', 'beyond-elysium' );
		default:
			return __( 'All', 'beyond-elysium' );
	}
}

/**
 * The line under a section's pickers saying how it prices.
 */
export function priceLine( section: CatalogSection ): string {
	if ( 'dot' === section.priced_by ) {
		return __( 'Priced per dot.', 'beyond-elysium' );
	}
	if ( 'pick' === section.priced_by ) {
		return __( 'Priced per pick.', 'beyond-elysium' );
	}

	const costs = section.costs ?? {};
	const ranks = ( section.ranks ?? [] ).filter( ( rank ) => rank in costs );
	const priced = sprintf(
		/* translators: %s: ranks with their prices, such as "basic 3, intermediate 6" */
		__( 'Priced by rank: %s.', 'beyond-elysium' ),
		ranks.map( ( rank ) => `${ rank } ${ costs[ rank ] }` ).join( ', ' )
	);

	const modifiers = Object.values( section.out_of_type ?? {} );
	if (
		modifiers.length === 0 ||
		modifiers.some( ( m ) => m !== modifiers[ 0 ] )
	) {
		return priced;
	}
	return (
		priced +
		' ' +
		sprintf(
			/* translators: %s: the extra price out-of-clan, such as +1 */
			__( 'Out-of-clan %s.', 'beyond-elysium' ),
			modifiers[ 0 ]
		)
	);
}

/**
 * The name a list stores for an item's parts, as the list's name form writes it: "Group: Name (rank)".
 */
export function composedName(
	section: Pick< CatalogSection, 'name_form' >,
	parts: { name: string; group: string; tier: string }
): string {
	const name = parts.name.trim();
	if ( ! section.name_form ) {
		return name;
	}
	const group = parts.group.trim();
	const tier = parts.tier.trim();
	return `${ group ? group + ': ' : '' }${ name }${ tier ? ` (${ tier })` : '' }`;
}

/**
 * The badges an entry shows, in the order they read.
 */
export function entryBadges( entry: {
	origin: CatalogOrigin;
	flagged: boolean;
} ): string[] {
	const badges: string[] = [];
	if ( 'added' === entry.origin ) {
		badges.push( __( 'Added here', 'beyond-elysium' ) );
	}
	if ( 'changed' === entry.origin ) {
		badges.push( __( 'Changed here', 'beyond-elysium' ) );
	}
	if ( entry.flagged ) {
		badges.push( __( 'The book changed this', 'beyond-elysium' ) );
	}
	return badges;
}

/**
 * Whether an entry passes a filter and a search: the search matches any part of its name, in any case.
 */
export function entryShows(
	entry: {
		name: string;
		name_pt?: string | null;
		origin: CatalogOrigin;
		flagged: boolean;
	},
	filter: CatalogFilter,
	search: string
): boolean {
	const needle = search.trim().toLowerCase();
	if (
		needle !== '' &&
		! entry.name.toLowerCase().includes( needle ) &&
		! ( entry.name_pt ?? '' ).toLowerCase().includes( needle )
	) {
		return false;
	}
	switch ( filter ) {
		case 'added':
			return 'added' === entry.origin;
		case 'changed':
			return 'changed' === entry.origin;
		case 'flagged':
			return entry.flagged;
		default:
			return true;
	}
}

export interface ItemGroup {
	/**
	 * The group's name; empty for entries filed under none.
	 */
	name: string;
	entries: CatalogItemEntry[];
	/**
	 * Whether the group starts shut: every group does on a list of more than FOLD_ABOVE entries, until a search is typed.
	 */
	folded: boolean;
}

/**
 * Items by group in the order the catalog lists them, those the filter and search keep.
 */
export function groupItems(
	items: CatalogItemEntry[],
	filter: CatalogFilter,
	search: string
): ItemGroup[] {
	const folded = items.length > FOLD_ABOVE && search.trim() === '';
	const groups: ItemGroup[] = [];
	for ( const item of items ) {
		if ( ! entryShows( item, filter, search ) ) {
			continue;
		}
		let group = groups.find( ( each ) => each.name === item.group );
		if ( ! group ) {
			group = { name: item.group, entries: [], folded };
			groups.push( group );
		}
		group.entries.push( item );
	}
	return groups;
}

export interface ItemDraft {
	name: string;
	group: string;
	tier: string;
	cost: string;
	description: CatalogDescription;
}

/**
 * The fields an item's pop-up starts from.
 */
export function itemDraft( entry: CatalogItemEntry | null ): ItemDraft {
	return {
		name: entry?.base_name ?? '',
		group: entry?.group ?? '',
		tier: entry?.tier ?? '',
		cost: entry?.cost ?? '',
		description: { ...( entry?.description ?? {} ) },
	};
}

/**
 * Whether two descriptions say the same.
 */
export function sameDescription(
	a: CatalogDescription | null | undefined,
	b: CatalogDescription | null | undefined
): boolean {
	return (
		( a?.reference ?? '' ) === ( b?.reference ?? '' ) &&
		( a?.description ?? '' ) === ( b?.description ?? '' ) &&
		( a?.source ?? '' ) === ( b?.source ?? '' )
	);
}

/**
 * The fields of an item that differ from the entry it was opened from, which is all a save sends; a book item sends only
 * its price and description. A new item sends everything it has.
 */
export function itemChanges(
	entry: CatalogItemEntry | null,
	draft: ItemDraft
): Record< string, unknown > {
	const changes: Record< string, unknown > = {};
	const book = entry !== null && 'added' !== entry.origin;
	const was = itemDraft( entry );

	if ( ! book ) {
		for ( const key of [ 'name', 'group', 'tier' ] as const ) {
			if ( null === entry || draft[ key ].trim() !== was[ key ] ) {
				if ( null === entry && draft[ key ].trim() === '' ) {
					continue;
				}
				changes[ key ] = draft[ key ].trim();
			}
		}
	}
	if ( null === entry || draft.cost.trim() !== was.cost ) {
		changes.cost = draft.cost.trim();
	}
	if (
		( null === entry && ! sameDescription( draft.description, {} ) ) ||
		( null !== entry &&
			! sameDescription( draft.description, was.description ) )
	) {
		changes.description = draft.description;
	}
	return changes;
}

export interface LevelDraft {
	level: number;
	tier: string;
	cost: string;
	power_name: string;
	description: CatalogDescription;
}

export interface PickDraft {
	rank: string;
	power_name: string;
	cost: string;
	description: CatalogDescription;
	origin: 'book' | 'added';
	held: boolean;
}

export interface FamilyDraft {
	name: string;
	description: CatalogDescription;
	levels: LevelDraft[];
	picks: PickDraft[];
	traditions: Record< string, string >;
}

/**
 * The fields a family's pop-up starts from: every rung of the section's ladder, named or not, and the family's picks.
 */
export function familyDraft(
	entry: CatalogFamilyEntry | null,
	section: CatalogSection
): FamilyDraft {
	const levels = ( section.ladder ?? [] ).map( ( rung ) => {
		const held = entry?.levels.find(
			( level ) => level.level === rung.level
		);
		return {
			level: rung.level,
			tier: rung.tier,
			cost: held?.cost ?? String( rung.cost ),
			power_name: held?.power_name ?? '',
			description: { ...( held?.description ?? {} ) },
		};
	} );
	const traditions: Record< string, string > = {};
	for ( const [ tradition, alias ] of Object.entries(
		entry?.traditions ?? {}
	) ) {
		traditions[ tradition ] = alias ?? '';
	}
	return {
		name: entry?.name ?? '',
		description: { ...( entry?.description ?? {} ) },
		levels,
		picks: ( entry?.picks ?? [] ).map( ( pick ) => ( {
			rank: pick.rank,
			power_name: pick.power_name,
			cost: pick.cost,
			description: { ...( pick.description ?? {} ) },
			origin: pick.origin,
			held: pick.in_use.characters + pick.in_use.changes > 0,
		} ) ),
		traditions,
	};
}

/**
 * A pick's price when none is typed: the section's cost for its rank.
 */
export function defaultPickCost(
	section: CatalogSection,
	rank: string
): string {
	const cost = section.costs?.[ rank ];
	return cost === undefined ? '' : String( cost );
}

/**
 * The fields of a family that differ from the entry it was opened from. A book family sends its description, its levels'
 * descriptions and its picks; a new family sends everything it has.
 */
export function familyChanges(
	entry: CatalogFamilyEntry | null,
	draft: FamilyDraft,
	section: CatalogSection
): Record< string, unknown > {
	const changes: Record< string, unknown > = {};
	const was = familyDraft( entry, section );
	const book = entry !== null && 'added' !== entry.origin;

	if ( ! book && draft.name.trim() !== was.name ) {
		changes.name = draft.name.trim();
	}
	if (
		( null === entry && ! sameDescription( draft.description, {} ) ) ||
		( null !== entry &&
			! sameDescription( draft.description, was.description ) )
	) {
		changes.description = draft.description;
	}

	const levels: Array< Record< string, unknown > > = [];
	for ( const level of draft.levels ) {
		const before = was.levels.find( ( row ) => row.level === level.level );
		const row: Record< string, unknown > = { level: level.level };
		if (
			! book &&
			level.power_name.trim() !== ( before?.power_name ?? '' )
		) {
			row.power_name = level.power_name.trim();
		}
		if ( ! sameDescription( level.description, before?.description ) ) {
			row.description = level.description;
		}
		if ( Object.keys( row ).length > 1 ) {
			levels.push( row );
		}
	}
	if ( levels.length > 0 ) {
		changes.levels = levels;
	}

	const key = ( pick: { rank: string; power_name: string } ) =>
		`${ pick.rank }|${ pick.power_name.trim().toLowerCase() }`;
	const before = new Map(
		was.picks.map( ( pick ) => [ key( pick ), pick ] )
	);
	const picksChanged =
		draft.picks.length !== was.picks.length ||
		draft.picks.some( ( pick ) => {
			const old = before.get( key( pick ) );
			return (
				! old ||
				old.cost.trim() !== pick.cost.trim() ||
				! sameDescription( old.description, pick.description )
			);
		} );
	if ( picksChanged ) {
		changes.picks = draft.picks.map( ( pick ) => {
			const old = before.get( key( pick ) );
			const row: Record< string, unknown > = {
				rank: pick.rank,
				power_name: pick.power_name.trim(),
				cost: pick.cost.trim(),
			};
			if ( ! sameDescription( old?.description, pick.description ) ) {
				row.description = pick.description;
			}
			return row;
		} );
	}

	const ticked = Object.keys( draft.traditions ).sort();
	const tickedBefore = Object.keys( was.traditions ).sort();
	if (
		ticked.join( '|' ) !== tickedBefore.join( '|' ) ||
		ticked.some(
			( t ) => draft.traditions[ t ].trim() !== was.traditions[ t ]
		)
	) {
		const sent: Record< string, string | null > = {};
		for ( const tradition of ticked ) {
			sent[ tradition ] = draft.traditions[ tradition ].trim() || null;
		}
		changes.traditions = sent;
	}
	return changes;
}

/**
 * The in-clan boxes a section offers: each map its test reads, the keys that can be ticked, and which of them hold the
 * family.
 */
export function inClanBoxes(
	section: CatalogSection,
	entry: CatalogFamilyEntry | null
): Array< CatalogInTypeMap & { ticked: string[] } > {
	return section.in_type.maps.map( ( map ) => ( {
		...map,
		ticked: entry?.in_clan[ map.map ] ?? [],
	} ) );
}

/**
 * The sentence for a test that needs nothing set, built from the clans it names ("Caitiff and Pander choose their own"),
 * or null when the section has none.
 */
export function chosenNote( section: CatalogSection ): string | null {
	const names = [
		...new Set( section.in_type.chosen.flatMap( ( c ) => c.is ) ),
	];
	if ( names.length === 0 ) {
		return null;
	}
	const list =
		names.length === 1
			? names[ 0 ]
			: sprintf(
					/* translators: 1: the names before the last, 2: the last name */
					__( '%1$s and %2$s', 'beyond-elysium' ),
					names.slice( 0, -1 ).join( ', ' ),
					names[ names.length - 1 ]
				);
	return sprintf(
		/* translators: %s: clans that pick their own in-clan list, such as "Caitiff and Pander" */
		__( '%s choose their own.', 'beyond-elysium' ),
		list
	);
}

/**
 * One flagged change as the pop-up reads it: which field it is on and the three values (the chronicle's, the book's now
 * and the book's when the change was made).
 */
export interface FlagParts {
	/**
	 * What the change is on: "Price", "Description", or the field's own name.
	 */
	field: string;
	yours: string;
	now: string;
	was: string;
	/**
	 * Whether the book dropped the entry the change sits in.
	 */
	removed: boolean;
}

/**
 * A stored value in a sentence: text and numbers as they are, a description as "a description", nothing as "nothing".
 */
export function valueText( value: unknown, isSet: boolean | null ): string {
	if (
		isSet === false ||
		value === null ||
		value === undefined ||
		value === ''
	) {
		return __( 'nothing', 'beyond-elysium' );
	}
	if ( typeof value === 'object' ) {
		return __( 'a different description', 'beyond-elysium' );
	}
	return String( value );
}

/**
 * Reads a flag for the pop-up.
 */
export function flagParts( flag: CatalogEntryFlag ): FlagParts {
	const last = flag.path[ flag.path.length - 1 ];
	const step = Array.isArray( last ) ? String( last[ 0 ] ) : String( last );
	let field = step;
	if ( step === 'cost' ) {
		field = __( 'Price', 'beyond-elysium' );
	} else if ( step === 'description' ) {
		field = __( 'Description', 'beyond-elysium' );
	}
	return {
		field,
		yours: valueText( flag.yours, flag.yours_set ),
		now: valueText( flag.now, flag.now_set ),
		was: valueText( flag.was, flag.was_set ),
		removed: flag.removed,
	};
}
