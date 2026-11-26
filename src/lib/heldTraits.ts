/**
 * The traits and powers a character holds, listed for the Play view's search and opened in the trait panel.
 */

export interface HeldSection {
	blockSlug: string;
	title: string;
	sectionType: string;
}

export interface HeldTrait {
	blockSlug: string;
	section: string;
	sectionType: 'trait_list' | 'tiered_power';
	/**
	 * The trait's own name, or a power's family.
	 */
	name: string;
	/**
	 * A power held as a pick: the pick's own name.
	 */
	powerName?: string;
	/**
	 * A list's count, or a power's level; null when it has none.
	 */
	rating: number | null;
	shown: string;
}

function numberOrNull( value: unknown ): number | null {
	if ( typeof value !== 'number' && typeof value !== 'string' ) {
		return null;
	}
	if ( typeof value === 'string' && value.trim() === '' ) {
		return null;
	}
	const parsed = Math.trunc( Number( value ) );
	return Number.isFinite( parsed ) ? parsed : null;
}

/**
 * Every trait and power a sheet holds in the given sections, in section order. `label` turns a stored name into the one
 * the sheet shows (a translated catalog name, for one).
 */
export function heldTraits(
	sections: HeldSection[],
	sheetData: Record< string, unknown >,
	exclude: string[] = [],
	label: ( blockSlug: string, name: string ) => string = ( _slug, name ) =>
		name
): HeldTrait[] {
	const held: HeldTrait[] = [];
	for ( const section of sections ) {
		if (
			exclude.includes( section.blockSlug ) ||
			( section.sectionType !== 'trait_list' &&
				section.sectionType !== 'tiered_power' )
		) {
			continue;
		}
		const rows = sheetData[ section.blockSlug ];
		if ( ! Array.isArray( rows ) ) {
			continue;
		}
		for ( const row of rows ) {
			if ( typeof row !== 'object' || row === null ) {
				continue;
			}
			const stored = row as Record< string, unknown >;
			if ( typeof stored.name !== 'string' || stored.name === '' ) {
				continue;
			}
			const name = label( section.blockSlug, stored.name );
			if ( section.sectionType === 'trait_list' ) {
				const rating = numberOrNull( stored.count );
				held.push( {
					blockSlug: section.blockSlug,
					section: section.title,
					sectionType: 'trait_list',
					name: stored.name,
					rating,
					shown:
						rating !== null && rating > 1
							? `${ name } x${ rating }`
							: name,
				} );
				continue;
			}
			const rating = numberOrNull( stored.level );
			const pick =
				typeof stored.power_name === 'string' &&
				stored.power_name !== ''
					? stored.power_name
					: undefined;
			held.push( {
				blockSlug: section.blockSlug,
				section: section.title,
				sectionType: 'tiered_power',
				name: stored.name,
				powerName: pick,
				rating,
				shown: pick
					? `${ pick } (${ name })`
					: rating !== null
						? `${ name } ${ rating }`
						: name,
			} );
		}
	}
	return held;
}

/**
 * The held traits whose name, pick or section contains every word typed, for a few results at most.
 */
export function searchTraits(
	held: HeldTrait[],
	query: string,
	limit = 20
): HeldTrait[] {
	const words = query.toLowerCase().split( /\s+/ ).filter( Boolean );
	if ( words.length === 0 ) {
		return [];
	}
	return held
		.filter( ( trait ) => {
			const haystack =
				`${ trait.shown } ${ trait.name } ${ trait.powerName ?? '' } ${ trait.section }`.toLowerCase();
			return words.every( ( word ) => haystack.includes( word ) );
		} )
		.slice( 0, limit );
}
