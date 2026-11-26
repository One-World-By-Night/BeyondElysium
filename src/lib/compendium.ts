/**
 * How the compendium lists a section: entries grouped as the pickers group them, the ones the chronicle has written a
 * note on, what an entry costs and whether the character holds it.
 */
import { __, sprintf } from '@wordpress/i18n';
import { heldTraits } from './heldTraits';
import type { HeldTrait } from './heldTraits';
import type {
	CompendiumEntry,
	CompendiumFamily,
	CompendiumItem,
} from '../types/compendium';

export interface EntryGroup< T > {
	/**
	 * The group's name, or empty for entries that have none.
	 */
	group: string;
	subgroups: Array< { subgroup: string; entries: T[] } >;
}

/**
 * Whether an entry is a power family and not a list item.
 */
export function isFamily( entry: CompendiumEntry ): entry is CompendiumFamily {
	return 'levels' in entry;
}

/**
 * Entries under their group and then their subgroup, each in the order first met; entries with no group come first.
 */
export function groupEntries< T extends { group: string | null } >(
	entries: T[]
): Array< EntryGroup< T > > {
	const groups: Array< EntryGroup< T > > = [];
	for ( const entry of entries ) {
		const name = entry.group ?? '';
		const sub =
			( entry as unknown as { subgroup?: string | null } ).subgroup ?? '';
		let group = groups.find( ( g ) => g.group === name );
		if ( ! group ) {
			group = { group: name, subgroups: [] };
			groups.push( group );
		}
		let subgroup = group.subgroups.find( ( s ) => s.subgroup === sub );
		if ( ! subgroup ) {
			subgroup = { subgroup: sub, entries: [] };
			group.subgroups.push( subgroup );
		}
		subgroup.entries.push( entry );
	}
	return groups.sort(
		( a, b ) => ( a.group === '' ? -1 : 0 ) - ( b.group === '' ? -1 : 0 )
	);
}

/**
 * The entries the chronicle has written a note on.
 */
export function withNotes( entries: CompendiumEntry[] ): CompendiumEntry[] {
	return entries.filter( ( entry ) => !! entry.description );
}

/**
 * What the catalog says an entry costs: a plain number as XP, any other expression as written, nothing when it has none.
 */
export function costWords( cost: string | null | undefined ): string {
	if ( cost === null || cost === undefined || cost.trim() === '' ) {
		return '';
	}
	return /^\d+$/.test( cost.trim() )
		? sprintf(
				/* translators: %s: a number of experience points */
				__( '%s XP', 'beyond-elysium' ),
				cost.trim()
			)
		: cost;
}

/**
 * What a family costs to start: its first level's cost.
 */
export function familyCost( family: CompendiumFamily ): string {
	return costWords( family.levels[ 0 ]?.cost );
}

/**
 * Says how much of an entry the character holds: a count, a level, or just held; nothing when it does not hold it.
 */
export function heldWords( held: HeldTrait | undefined ): string {
	if ( ! held ) {
		return '';
	}
	if ( held.sectionType === 'tiered_power' ) {
		return held.rating !== null
			? sprintf(
					/* translators: %d: the level a character holds a power at */
					__( 'Held, level %d', 'beyond-elysium' ),
					held.rating
				)
			: __( 'Held', 'beyond-elysium' );
	}
	return held.rating !== null && held.rating > 1
		? sprintf(
				/* translators: %d: how many dots or points a character holds of a trait */
				__( 'Held x%d', 'beyond-elysium' ),
				held.rating
			)
		: __( 'Held', 'beyond-elysium' );
}

/**
 * The traits a character holds in one section, by name.
 */
export function heldInSection(
	section: { blockSlug: string; title: string; sectionType: string },
	sheetData: Record< string, unknown >
): Map< string, HeldTrait > {
	const byName = new Map< string, HeldTrait >();
	for ( const held of heldTraits( [ section ], sheetData ) ) {
		if ( ! held.powerName && ! byName.has( held.name ) ) {
			byName.set( held.name, held );
		}
	}
	return byName;
}

/**
 * An entry as the held-trait shape the trait panel opens on, held or not.
 */
export function asTrait(
	section: { blockSlug: string; title: string; sectionType: string },
	entry: CompendiumItem | CompendiumFamily
): HeldTrait {
	return {
		blockSlug: section.blockSlug,
		section: section.title,
		sectionType:
			section.sectionType === 'tiered_power'
				? 'tiered_power'
				: 'trait_list',
		name: entry.name,
		rating: null,
		shown: entry.name,
	};
}
