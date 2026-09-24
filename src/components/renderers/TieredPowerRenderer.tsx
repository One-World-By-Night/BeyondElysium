/**
 * Renders a tiered_power schema block (Disciplines, Gifts, Spheres, Arts, Arcanoi,...): the powers a character holds,
 * either as numeric levels or as named picks, depending on `displayMode`.
 */
import { __ } from '@wordpress/i18n';
import { localizedPowerName } from '../../lib/localizeName';
import { seamQualifier } from '../../lib/levelQualifier';
import { allLevels } from '../../lib/powerLevels';
import type {
	TieredPowerDefinition,
	TieredPower,
	PowerLevel,
} from '../../types';
import './TieredPowerRenderer.css';

/**
 * One tiered power a character holds.
 */
export interface HeldPower {
	name: string;
	level?: number;
	power_name?: string;
	/**
	 * Tier text for an entry with no catalog match.
	 */
	tier?: string;
	/**
	 * Sorcery tradition this entry belongs to (Necromancy, Sadhana,...).
	 */
	tradition?: string;
}

/**
 * Prefix a rendered label with the entry's tradition, when it carries one.
 */
export function withTradition( held: HeldPower, label: string ): string {
	return held.tradition ? `${ held.tradition }: ${ label }` : label;
}

export interface TieredPowerRendererProps {
	blockSlug: string;
	data: HeldPower[];
	definition: TieredPowerDefinition;
	displayMode?: 'named' | 'numeric';
}

function findPower(
	definition: TieredPowerDefinition,
	name: string
): TieredPower | undefined {
	return definition.powers.find( ( power ) => power.name === name );
}

function findLevel(
	power: TieredPower | undefined,
	level: number
): PowerLevel | undefined {
	return power?.levels.find( ( entry ) => entry.level === level );
}

/**
 * Maps a numbered rank (1=basic, 2=intermediate,...) to its tier name.
 */
export const TIER_FOR_RANK: Record< number, string > = {
	1: 'basic',
	2: 'intermediate',
	3: 'advanced',
	4: 'elder',
	5: 'master',
	6: 'ascended',
	7: 'methuselah',
};

/**
 * Every real power at a given rank on a family's ladder.
 */
export function findLevelsAtRank(
	power: TieredPower | undefined,
	rank: number
): PowerLevel[] {
	if ( ! power ) {
		return [];
	}
	const exact = power.levels.filter( ( entry ) => entry.level === rank );
	if ( exact.length > 0 ) {
		return exact;
	}
	const tier = TIER_FOR_RANK[ rank ];
	if ( ! tier ) {
		return [];
	}
	return power.levels.filter( ( entry ) => entry.tier === tier );
}

/**
 * Elder-and-above lookup: by the specific power's own name.
 */
function findByPowerName(
	power: TieredPower | undefined,
	powerName: string
): PowerLevel | undefined {
	return allLevels( power ).find(
		( entry ) => entry.power_name === powerName
	);
}

/**
 * A tier value that is a parser placeholder.
 */
const PLACEHOLDER_TIERS = new Set( [ '***', '', 'unknown' ] );

/**
 * Returns a tier safe to display, or undefined when it is a parser placeholder.
 */
export function displayableTier( tier?: string | null ): string | undefined {
	const trimmed = ( tier ?? '' ).trim();
	return PLACEHOLDER_TIERS.has( trimmed.toLowerCase() ) ? undefined : trimmed;
}

/**
 * Builds a named label for an Elder-and-above held power.
 */
export function elderLabel(
	definition: TieredPowerDefinition,
	held: HeldPower
): string {
	// Prefers a fresh catalog tier lookup.
	const selfNamed = held.power_name === held.name;

	const found = findByPowerName(
		findPower( definition, held.name ),
		held.power_name as string
	);
	const powerName = found ? localizedPowerName( found ) : held.power_name;

	const stem = selfNamed ? held.name : `${ held.name }: ${ powerName }`;

	if ( held.level != null ) {
		return `${ stem } ${ held.level }`;
	}
	const tier =
		displayableTier( found?.tier ) ??
		displayableTier( held.tier ) ??
		'elder';
	// On a family that is two ladders concatenated, the tier alone is ambiguous.
	const qualifier = seamQualifier(
		findPower( definition, held.name ),
		found
	);
	return qualifier
		? `${ stem } (${ tier } · ${ qualifier })`
		: `${ stem } (${ tier })`;
}

/**
 * Builds a numeric-mode label for one held power: delegates to `elderLabel()` for a named Elder-and-above pick, or
 * renders "Family {level}" for a plain numeric holding.
 */
export function numericLabel(
	definition: TieredPowerDefinition,
	held: HeldPower
): string {
	if ( held.power_name ) {
		return elderLabel( definition, held );
	}
	return held.level != null
		? `${ held.name } ${ held.level }`
		: `${ held.name } ?`;
}

/**
 * The named label for one held power.
 */
export function namedLabel(
	definition: TieredPowerDefinition,
	held: HeldPower,
	level?: number
): string {
	if ( held.power_name ) {
		return elderLabel( definition, held );
	}

	const powerLevel =
		level != null
			? findLevel( findPower( definition, held.name ), level )
			: undefined;
	return powerLevel
		? localizedPowerName( powerLevel )
		: numericLabel( definition, held );
}

/**
 * Builds the label list "named" mode shows for one held power: the single label for an Elder-and-above pick.
 */
export function namedModeRows(
	definition: TieredPowerDefinition,
	held: HeldPower
): string[] {
	if ( held.power_name ) {
		return [ namedLabel( definition, held, held.level ) ];
	}
	const power = findPower( definition, held.name );
	const rows: string[] = [];
	for ( let level = 1; level <= ( held.level ?? 0 ); level++ ) {
		const atRank = findLevelsAtRank( power, level );
		if ( atRank.length === 0 ) {
			rows.push( numericLabel( definition, held ) );
			continue;
		}
		for ( const entry of atRank ) {
			// One rung of a concatenated family can hold powers from both ladders.
			const label = localizedPowerName( entry );
			const qualifier = seamQualifier( power, entry );
			rows.push( qualifier ? `${ label } (${ qualifier })` : label );
		}
	}
	return rows;
}

/**
 * Renders the powers a character holds for a tiered_power block.
 */
export function TieredPowerRenderer( {
	blockSlug,
	data,
	definition,
	displayMode,
}: TieredPowerRendererProps ) {
	const mode = displayMode ?? 'numeric';

	if ( data.length === 0 ) {
		return (
			<div className="be-tiered-power" data-block-slug={ blockSlug }>
				<p className="be-tiered-power__empty">
					{ __( 'None', 'beyond-elysium' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="be-tiered-power" data-block-slug={ blockSlug }>
			<ul className="be-tiered-power__items">
				{ data.map( ( held, index ) => {
					if ( mode === 'numeric' ) {
						return (
							<li key={ `${ held.name }-${ index }` }>
								{ withTradition(
									held,
									numericLabel( definition, held )
								) }
							</li>
						);
					}
					// Named mode: every row stacks on its own line.
					const rows = namedModeRows( definition, held );
					return (
						<li key={ `${ held.name }-${ index }` }>
							<ul className="be-tiered-power__rank-rows">
								{ rows.map( ( row, rowIndex ) => (
									<li key={ rowIndex }>
										{ withTradition( held, row ) }
									</li>
								) ) }
							</ul>
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}

export default TieredPowerRenderer;
