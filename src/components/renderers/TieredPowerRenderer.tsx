/**
 * Renders a tiered_power schema block (Disciplines, Gifts, Spheres,
 * Arts, Arcanoi, ...): the powers a character holds, either as numeric
 * levels or as named picks, depending on `displayMode`. Also exports the
 * `HeldPower` type and the label-building helpers used to format each
 * held entry.
 */
import { __ } from '@wordpress/i18n';
import { localizedPowerName } from '../../lib/localizeName';
import type {
	TieredPowerDefinition,
	TieredPower,
	PowerLevel,
} from '../../types';
import './TieredPowerRenderer.css';

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
 * own display rule (BE_PROCESS/releases/0.99.2-workflow.md: "Tradition: PathName"). A plain power
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
 * Maps a numbered rank (1=basic, 2=intermediate, ...) to its tier name. Mirrors
 * `Cost_Engine::tier_for_rank()`/`Database\Seeder::TIER_RANKS` on the PHP side and
 * `Power_Display::tier_for_rank()`, its own exact twin - duplicated per-file rather
 * than shared, matching this codebase's established precedent for this one lookup.
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
 * Every real power at a given rank on a family's ladder - normally the single item
 * whose own `level` matches exactly, but D66 (1.2.5-design-workflow.md §A2: "anything
 * where more than one power exist on the same level... always show all") leaves
 * `level: null` on every item when several share one tier, so falls back to matching
 * by the rank's tier instead. Never rolled up to one entry - a caller that needs a
 * single name is a caller from before this fix existed. Exported for
 * `TieredPowerEditor.tsx`'s own `maxLevel()`/`levelName()`, which need the identical
 * rank-from-tier logic rather than a third copy of it.
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

/** Elder-and-above lookup: by the specific power's own name, not a number. */
function findByPowerName(
	power: TieredPower | undefined,
	powerName: string
): PowerLevel | undefined {
	return power?.levels.find( ( entry ) => entry.power_name === powerName );
}

/**
 * A tier value that is a parser placeholder rather than a real rank. The importer
 * writes `***` when it cannot map a raw trait onto the catalog, and that sentinel
 * reached players verbatim - a real sheet rendered "Combination: Sawafi Form (***)"
 * (owner-reported live, 2026-09-21). 1,637 production holdings carry it. These are
 * never real ranks and must never be shown as one.
 */
const PLACEHOLDER_TIERS = new Set( [ '***', '', 'unknown' ] );

/** Returns a tier safe to display, or undefined when it is a parser placeholder. */
export function displayableTier( tier?: string | null ): string | undefined {
	const trimmed = ( tier ?? '' ).trim();
	return PLACEHOLDER_TIERS.has( trimmed.toLowerCase() ) ? undefined : trimmed;
}

/**
 * Builds a named label for an Elder-and-above held power: "Family: Power
 * (tier)" using the tier looked up from the catalog when the power is
 * found there, or "Family: Power {level}" when the entry carries its own
 * numbered level instead. A placeholder tier falls through to `elder`
 * rather than printing the sentinel.
 */
export function elderLabel(
	definition: TieredPowerDefinition,
	held: HeldPower
): string {
	// Prefers a fresh catalog tier lookup, then the entry's own stored tier, then 'elder' -
	// the same lookup also backs the localized power name below (i18n-pt-br-design.md); the
	// family name (held.name, e.g. "Celerity") has no translation in this pass and is never
	// swapped, only the specific power's own name.
	const found = findByPowerName(
		findPower( definition, held.name ),
		held.power_name as string
	);
	const powerName = found ? localizedPowerName( found ) : held.power_name;

	if ( held.level != null ) {
		return `${ held.name }: ${ powerName } ${ held.level }`;
	}
	const tier =
		displayableTier( found?.tier ) ??
		displayableTier( held.tier ) ??
		'elder';
	return `${ held.name }: ${ powerName } (${ tier })`;
}

/**
 * Builds a numeric-mode label for one held power: delegates to
 * `elderLabel()` for a named Elder-and-above pick, or renders
 * "Family {level}" for a plain numeric holding.
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
 * The named label for one held power - a numeric level 1-5 looked up by number, or an
 * Elder-and-above power looked up by its own name, tagged with its real tier. Falls back
 * to whatever identifying text is available rather than ever rendering `undefined`.
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
 * Builds the label list "named" mode shows for one held power: the single label for an
 * Elder-and-above pick (Decision 037) - it's already the one specific power chosen, there
 * is no stack beneath it to expand - or one label per rung from 1 up to the held level for
 * a plain numbered holding. Decision 037 governs pricing cumulativeness, not display: a
 * player who wants every named rung listed sees the whole stack either way, sequential
 * block or not.
 *
 * D66 (1.2.5-design-workflow.md §A2, owner: "anything where more than one power exist on
 * the same level... always show all") - a rung tied between several named alternatives
 * (`level: null` on all of them) pushes every one of their names, never rolled up to one
 * entry; MET disciplines/gifts/etc. genuinely grant every power at a rank a character has
 * reached, not a single chosen pick, so this matches the real rule, not just the display.
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
			rows.push( localizedPowerName( entry ) );
		}
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
					// Named mode: every row stacks on its own line rather than joining
					// into one comma-separated string, so a rank tied between many named
					// alternatives (A2: "never roll up") stacks into cards instead of
					// wrapping or scrolling sideways at phone width.
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
