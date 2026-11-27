/**
 * What the creation rules hand a new character without the player asking: the starting value of a pool (Rage by
 * auspice, Gnosis by breed, Willpower, Conviction by creed) and an entry the rules grant (Wraith's Status). A value the
 * player changed is never written over.
 */
import type { CreationTally } from '../types/character';

export interface GivenEntry {
	section: string;
	label: string;
	value?: number;
}

/**
 * What the rules have already given this build: the pool starts last written and the grants already added.
 */
export interface GivenMemory {
	starts: Record< string, GivenEntry >;
	grants: Record< string, GivenEntry >;
}

export const NO_GIVENS: GivenMemory = { starts: {}, grants: {} };

type Sheet = Record< string, unknown >;

function heldPermanent(
	sheet: Sheet,
	slug: string,
	pool: string
): number | undefined {
	const block = sheet[ slug ];
	if ( ! block || typeof block !== 'object' || Array.isArray( block ) ) {
		return undefined;
	}
	const value = ( block as Record< string, unknown > )[ pool ];
	if ( value && typeof value === 'object' && 'permanent' in value ) {
		return Number( ( value as { permanent: unknown } ).permanent );
	}
	return typeof value === 'number' ? value : undefined;
}

/**
 * The sheet and memory after the rules' starts and grants are applied for a tally. The same objects come back when
 * nothing changed.
 */
export function applyGivens(
	sheet: Sheet,
	tally: CreationTally,
	memory: GivenMemory
): { sheet: Sheet; memory: GivenMemory } {
	let nextSheet = sheet;
	let nextMemory = memory;

	// A target with several `when` rules takes the one that applies.
	const starts = new Map< string, { value: number | null; label: string } >();
	for ( const step of tally.steps ) {
		if ( step.kind !== 'start' ) {
			continue;
		}
		const seen = starts.get( step.target );
		if ( step.applies && step.value !== null ) {
			starts.set( step.target, {
				value: step.value,
				label: step.target_label,
			} );
		} else if ( ! seen ) {
			starts.set( step.target, {
				value: null,
				label: step.target_label,
			} );
		}
	}

	for ( const [ target, rule ] of starts ) {
		const [ slug, pool ] = target.split( /\.(.+)/ );
		const current = heldPermanent( nextSheet, slug, pool );
		const previous = nextMemory.starts[ target ]?.value;

		if ( rule.value !== null ) {
			if ( current !== undefined && current !== previous ) {
				continue;
			}
			if ( current !== rule.value ) {
				nextSheet = {
					...nextSheet,
					[ slug ]: {
						...( ( nextSheet[ slug ] as Record<
							string,
							unknown
						> ) ?? {} ),
						[ pool ]: {
							permanent: rule.value,
							temporary: rule.value,
						},
					},
				};
			}
			if ( previous !== rule.value ) {
				nextMemory = {
					...nextMemory,
					starts: {
						...nextMemory.starts,
						[ target ]: {
							section: slug,
							label: rule.label,
							value: rule.value,
						},
					},
				};
			}
		} else if ( previous !== undefined ) {
			if ( current === previous ) {
				const block = {
					...( ( nextSheet[ slug ] as Record< string, unknown > ) ??
						{} ),
				};
				delete block[ pool ];
				nextSheet = { ...nextSheet, [ slug ]: block };
			}
			const kept = { ...nextMemory.starts };
			delete kept[ target ];
			nextMemory = { ...nextMemory, starts: kept };
		}
	}

	for ( const entry of tally.grants_missing ) {
		const key = [
			entry.section,
			entry.name,
			entry.power_name ?? '',
			entry.level ?? '',
			entry.count ?? '',
		].join( '|' );
		if ( nextMemory.grants[ key ] ) {
			continue;
		}
		const row: Record< string, unknown > = { name: entry.name };
		if ( entry.level !== undefined ) {
			row.level = entry.level;
		}
		if ( entry.count !== undefined ) {
			row.count = entry.count;
		}
		if ( entry.power_name !== undefined ) {
			row.power_name = entry.power_name;
		}
		const held = nextSheet[ entry.section ];
		nextSheet = {
			...nextSheet,
			[ entry.section ]: [
				...( Array.isArray( held ) ? held : [] ),
				row,
			],
		};
		nextMemory = {
			...nextMemory,
			grants: {
				...nextMemory.grants,
				[ key ]: {
					section: entry.section,
					label: entry.power_name ?? entry.name,
					value: entry.level ?? entry.count,
				},
			},
		};
	}

	return { sheet: nextSheet, memory: nextMemory };
}

/**
 * The lines "Given by the rules" shows on a step: each start and grant that lands in one of its sections.
 */
export function givenLines(
	memory: GivenMemory,
	sections: string[]
): string[] {
	return [
		...Object.values( memory.starts ),
		...Object.values( memory.grants ),
	]
		.filter( ( entry ) => sections.includes( entry.section ) )
		.map( ( entry ) =>
			entry.value === undefined
				? entry.label
				: `${ entry.label } ${ entry.value }`
		);
}
