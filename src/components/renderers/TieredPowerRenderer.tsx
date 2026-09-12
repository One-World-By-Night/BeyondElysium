/**
 * Renders a tiered_power schema block (Disciplines, Gifts, Spheres,
 * Arts, Arcanoi, ...): the powers a character holds, either as numeric
 * levels or as named picks, depending on `displayMode`. Also exports the
 * `HeldPower` type and the label-building helpers used to format each
 * held entry.
 */
import { __ } from '@wordpress/i18n';
import type { TieredPowerDefinition, TieredPower, PowerLevel } from '../../types';

/**
 * One tiered power a character holds. A numeric level 1-5 holding sets
 * `level` and leaves `power_name` unset. An Elder-and-above holding is a
 * specific named power: `power_name` is set and looked up by name, with
 * `level` normally unset. Both can be set together for an imported
 * holding whose power name isn't in this block's own catalog, carrying
 * its own level directly rather than one derived by catalog lookup.
 */
export interface HeldPower {
	name: string;
	level?: number;
	power_name?: string;
	/** Tier text for an entry with no catalog match; ignored when `level` is set or the power is found in the catalog. */
	tier?: string;
	/** Sorcery tradition this entry belongs to (Necromancy, Sadhana, ...); most tiered powers have none. */
	tradition?: string;
}

/**
 * Prefix a rendered label with the entry's tradition, when it carries one - Blood Magic's
 * own display rule (BE_PROCESS/0.99.2-workflow.md: "Tradition: PathName"). A plain power
 * with no tradition renders exactly as it always has.
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

function findPower( definition: TieredPowerDefinition, name: string ): TieredPower | undefined {
	return definition.powers.find( ( power ) => power.name === name );
}

function findLevel( power: TieredPower | undefined, level: number ): PowerLevel | undefined {
	return power?.levels.find( ( entry ) => entry.level === level );
}

/** Elder-and-above lookup: by the specific power's own name, not a number. */
function findByPowerName( power: TieredPower | undefined, powerName: string ): PowerLevel | undefined {
	return power?.levels.find( ( entry ) => entry.power_name === powerName );
}

/**
 * Builds a named label for an Elder-and-above held power: "Family: Power
 * (tier)" using the tier looked up from the catalog when the power is
 * found there, or "Family: Power {level}" when the entry carries its own
 * numbered level instead.
 */
export function elderLabel( definition: TieredPowerDefinition, held: HeldPower ): string {
	if ( held.level != null ) {
		return `${ held.name }: ${ held.power_name } ${ held.level }`;
	}
	// Prefers a fresh catalog tier lookup, then the entry's own stored tier, then 'elder'.
	const found = findByPowerName( findPower( definition, held.name ), held.power_name as string );
	return `${ held.name }: ${ held.power_name } (${ found?.tier ?? held.tier ?? 'elder' })`;
}

/**
 * Builds a numeric-mode label for one held power: delegates to
 * `elderLabel()` for a named Elder-and-above pick, or renders
 * "Family {level}" for a plain numeric holding.
 */
export function numericLabel( definition: TieredPowerDefinition, held: HeldPower ): string {
	if ( held.power_name ) {
		return elderLabel( definition, held );
	}
	return held.level != null ? `${ held.name } ${ held.level }` : `${ held.name } ?`;
}

/** The named label for one held power - a numeric level 1-5 looked up by number, or an
 * Elder-and-above power looked up by its own name, tagged with its real tier. Falls back
 * to whatever identifying text is available rather than ever rendering `undefined`. */
export function namedLabel( definition: TieredPowerDefinition, held: HeldPower, level?: number ): string {
	if ( held.power_name ) {
		return elderLabel( definition, held );
	}

	const powerLevel = level != null ? findLevel( findPower( definition, held.name ), level ) : undefined;
	return powerLevel ? powerLevel.power_name : numericLabel( definition, held );
}

/**
 * Builds the label list "named" mode shows for one held power: the single label for an
 * Elder-and-above pick (Decision 037) - it's already the one specific power chosen, there
 * is no stack beneath it to expand - or one label per rung from 1 up to the held level for
 * a plain numbered holding. Decision 037 governs pricing cumulativeness, not display: a
 * player who wants every named rung listed sees the whole stack either way, sequential
 * block or not.
 */
export function namedModeRows( definition: TieredPowerDefinition, held: HeldPower ): string[] {
	if ( held.power_name ) {
		return [ namedLabel( definition, held, held.level ) ];
	}
	const rows: string[] = [];
	for ( let level = 1; level <= ( held.level ?? 0 ); level++ ) {
		rows.push( namedLabel( definition, held, level ) );
	}
	return rows;
}

/**
 * Renders the powers a character holds for a tiered_power block. Numeric
 * mode always shows a single total per power, e.g. "Celerity 3". Named
 * mode lists every named rung up to the held level for a plain numbered
 * holding, or the one specific power's name for an Elder-and-above pick.
 * Renders "None" when nothing is held.
 */
export function TieredPowerRenderer( { blockSlug, data, definition, displayMode }: TieredPowerRendererProps ) {
	const mode = displayMode ?? 'numeric';

	if ( data.length === 0 ) {
		return (
			<div className="be-tiered-power" data-block-slug={ blockSlug }>
				<p className="be-tiered-power__empty">{ __( 'None', 'beyond-elysium' ) }</p>
			</div>
		);
	}

	return (
		<div className="be-tiered-power" data-block-slug={ blockSlug }>
			<ul className="be-tiered-power__items">
				{ data.map( ( held, index ) => {
					const label = mode === 'numeric'
						? numericLabel( definition, held )
						: namedModeRows( definition, held ).join( ', ' );
					return (
						<li key={ `${ held.name }-${ index }` }>
							{ withTradition( held, label ) }
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}

export default TieredPowerRenderer;
