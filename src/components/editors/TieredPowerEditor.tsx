/**
 * TieredPowerEditor renders the held-power list for a tiered_power block - leveled
 * catalogs such as Disciplines, Arcanoi, or Gifts. 1.2.10 replaces this component
 * outright (owner ruling, 2026-09-21 - "That is NOT a fix. That is the PROBLEM"):
 * `TieredPower` no longer carries one flat `levels[]` array that means both "ladder
 * rung" and "above-ladder pick" at once (D68 - a stepper set to 5, viewed as a
 * checklist, read as five levels removed and offered a refund for XP never spent).
 *
 * The catalog now declares three separate containers (`src/types/index.ts`):
 *   - `levels`  - the numbered ladder ONLY, one entry per rung, `sum(_meta.ladder)`
 *                 rungs (fallback 5 when a block predates `_meta`).
 *   - `elder`   - above-ladder (and, for Wraith, below-ladder Innate) picks, keyed
 *                 by rank. Never flattened into `levels` - that is the bug this
 *                 release exists to remove.
 *   - `overflow`- ladder-rank levels beyond the declared ladder (D67's concatenated
 *                 families, still awaiting a human ruling in 1.3.1). Never a rung,
 *                 never offered here, never counted in the rating.
 *
 * Two controls, not one (1.2.10-design-workflow.md §B): the stepper/checklist below
 * drives the ladder rating and can never reach a pick; the "Elder-and-above" list
 * below it reads `elder` only, grouped by rank, with no counts, no progress
 * affordance, and no rank rendered as unavailable because a neighbour is empty -
 * holding two Master powers and zero Elder ones is legal.
 *
 * The tradition field is a free-text input with datalist suggestions for every
 * tiered_power block, not just Blood Magic (0.99.2-workflow.md). For a
 * `blood_magic`-flagged block, adding a power requires a paradigm up front - the
 * picker shows every tradition the whole block offers, the power's own teaching
 * traditions first, never narrowed to just them (1.1.0 D5: that narrowing is exactly
 * what made Hunter's Wind untakeable as Dur An Ki). A held power with no paradigm
 * (older data) still shows "Choose paradigm", never forced - a Storyteller reviewing
 * the change is the actual check (Decision 057's UI-affordance pattern).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import SearchableSelect from '../shared/SearchableSelect';
import Modal from '../shared/Modal';
import { usePowerDisplayMode } from '../../lib/powerDisplayMode';
import { seamQualifier } from '../../lib/levelQualifier';
import {
	elderLabel,
	findLevelsAtRank,
	displayableTier,
	type HeldPower,
} from '../renderers/TieredPowerRenderer';
import {
	moveUp,
	moveDown,
	moveTo,
	reorderErrorMessage,
} from '../../lib/reorderArray';
import type { OptionGroup } from '../../lib/searchableSelect';
import api from '../../api/client';
import type { TieredPowerDefinition } from '../../types';
import './TieredPowerEditor.css';

/** Marked for removal rather than deleted outright; removal is applied when changes are submitted. */
export interface EditableHeldPower extends HeldPower {
	_removed?: boolean;
	/** Set when this row was entered as free text rather than chosen from the catalog. */
	custom?: boolean;
}

/** A blood_magic block's picked-but-not-yet-confirmed add, awaiting a paradigm (1.1.0 D5). */
interface PendingAdd {
	name: string;
	isCustom?: boolean;
	powerName?: string;
	/** Set only for an elder-and-above pick - the rank it was found under, stored on the row for display (see pickRankOf()'s own doc comment). */
	rank?: string;
}

export interface TieredPowerEditorProps {
	blockSlug: string;
	data: EditableHeldPower[];
	definition: TieredPowerDefinition;
	onChange: ( blockSlug: string, nextData: EditableHeldPower[] ) => void;
	/** Returns the XP cost for a held power at its current level; omitted callers show no cost. */
	costFor?: ( power: EditableHeldPower ) => number | null;
	readOnly?: boolean;
	/** Needed only for a player_order block's "Save order" call (1.1.0 D4). */
	gameSlug?: string;
	characterId?: number;
}

/**
 * The stepper/checklist ceiling when a block predates `_meta` entirely. Every real
 * ladder measured so far (1.2.10-design-workflow.md §A) is `2/2/1 = 5`, so this is a
 * genuine fallback, not a guess dressed up as one.
 */
const DEFAULT_LADDER_CEILING = 5;

/**
 * The number of rungs the declared ladder actually has: `sum(_meta.ladder)`, or
 * `DEFAULT_LADDER_CEILING` only when the block carries no `_meta.ladder` **at all** (S3 -
 * the seeder has not yet re-emitted this block against the declared-JSON shape).
 *
 * **1.3.2 fix.** A block that declares `ladder: {}` explicitly - a genuinely pick-only
 * track such as Werewolf/Fera Gifts (`reference/CATALOG-JSON-FORMAT.md` §4.2, "a pick-only
 * track... says so by declaring `ladder` as an explicit empty object") - must ceiling at
 * **0**, not 5: every power on that block is bought by name from `elder`, never rated on a
 * stepper at all, so a phantom 5-rung stepper would let a Gift be "raised" to a rating
 * nothing in the catalog prices. The previous rule treated an empty object the same as a
 * present-but-zero-sum ladder and fell back to 5 for both, which is right only for the
 * latter (a real authoring gap) and wrong for the former (a deliberate declaration). The
 * distinction is `ladder === undefined` (no `_meta` yet, or `_meta` with no `ladder` key at
 * all) versus `ladder` being present as `{}` - only the first falls back.
 *
 * This is still the whole of the D68 fix on the stepper side: the ceiling is read, never
 * inferred from tie counts or a family's own level count, so it cannot drift per-family the
 * way `maxLevel()` (1.2.9 and earlier) did.
 */
export function ladderCeiling( definition: TieredPowerDefinition ): number {
	const ladder = definition._meta?.ladder;
	if ( ladder === undefined || ladder === null ) {
		return DEFAULT_LADDER_CEILING;
	}
	return Object.values( ladder ).reduce( ( a, b ) => a + b, 0 );
}

/**
 * One step up, or the same level unchanged once the ladder ceiling is reached. This is
 * the load-bearing guarantee E1 exists for: however many times this is called, the
 * result can never exceed `ceiling`, so the stepper structurally cannot wander into
 * pick territory the way an uncapped "+"/a stale per-family max once could.
 */
export function incrementLevel( level: number, ceiling: number ): number {
	return level >= ceiling ? level : level + 1;
}

/**
 * One step down, floored at 1. Deliberately does **not** clamp against `ceiling` - a
 * legacy holding above the ceiling (1.2.10-design-workflow.md §A′: a stored level is a
 * TOTAL, e.g. Celerity 9 on a 5-rung ladder) must step down one rung at a time, never
 * jump straight to the ceiling the instant it is touched. That jump would silently
 * discard the picks the total represents - the exact shape of bug this release exists
 * to remove, just triggered by a click instead of a view switch.
 */
export function decrementLevel( level: number ): number {
	return Math.max( 1, level - 1 );
}

/**
 * Clamps an explicit target level into `[1, ceiling]` - used only by the checklist,
 * whose rungs are never anything but `1..ceiling` to begin with, so this can never
 * trigger the same silent-drop hazard `decrementLevel()`'s own doc comment describes.
 */
export function clampToCeiling( level: number, ceiling: number ): number {
	return Math.max( 1, Math.min( level, ceiling ) );
}

/** One rung of the declared ladder, as the checklist shows it. */
export interface LadderRung {
	/** The single name on the checkbox line - the printing in play. */
	label: string;
	/** Every other name filed at that rank, kept as a note rather than dropped. */
	alternates: string[];
}

/**
 * One rung of the declared ladder, named once (1.2.11 D93).
 *
 * **A rung is one thing you buy, so it gets one name.** This used to join every name at
 * the rank with ", ", which the owner reported from a real sheet: on pre-1.2.10
 * (production-shaped) data, Animalism's rung 1 read
 * `Feral Whispers, Beckoning, Beast Within (2nd ed), Feral Speech (dark ages), Noah's Call (dark ages)`
 * inside a single checkbox label.
 *
 * **Which name.** The line in play is the base printing - the one whose note carries
 * nothing beyond its tier word. `seamQualifier()` is the right input for that and is used
 * rather than any list of edition words: it returns a qualifier only where a family
 * genuinely disagrees with itself, so `2nd ed`, `dark ages` and `Sabbat` surface exactly
 * where they distinguish something. Where every name at the rank is qualified - a
 * concatenated family like `Path of Blood's Curse`, whose rung 1 is Tremere *and* Sabbat -
 * the first in source order wins, carrying its own qualifier so the line still says which
 * ladder it belongs to.
 *
 * **Nothing is dropped.** The rest come back as `alternates` for the caller to show as a
 * note, which keeps D66's "never roll up to a placeholder" intact - the names are all
 * still reachable, just not run together on one line.
 *
 * Falls back to a plain "{name} {rung}" when the catalog has no entry at that rank - a
 * custom power, or a genuine gap in the seeded data - so a box is never unlabeled.
 *
 * The read-only sheet is deliberately untouched: `TieredPowerRenderer.namedModeRows()`
 * lists every name at a rank as its own row, because there it is listing what a character
 * holds rather than labelling one purchase.
 */
export function ladderRung(
	definition: TieredPowerDefinition,
	name: string,
	rung: number
): LadderRung {
	const power = definition.powers.find( ( p ) => p.name === name );
	const atRank = findLevelsAtRank( power, rung );
	if ( atRank.length === 0 ) {
		return { label: `${ name } ${ rung }`, alternates: [] };
	}

	const named = atRank.map( ( level ) => {
		const base = level.power_name || `${ name } ${ rung }`;
		const qualifier = seamQualifier( power, level );
		return {
			label: qualifier ? `${ base } (${ qualifier })` : base,
			qualified: !! qualifier,
		};
	} );

	// The base printing where there is one, otherwise source order.
	const chosen = named.findIndex( ( entry ) => ! entry.qualified );
	const at = chosen === -1 ? 0 : chosen;

	return {
		label: named[ at ].label,
		alternates: named
			.filter( ( _entry, i ) => i !== at )
			.map( ( entry ) => entry.label ),
	};
}

/**
 * One rung's own checkbox label. Thin wrapper over `ladderRung()`; see it for why a rung
 * names one power rather than all of them.
 */
export function ladderRungLabel(
	definition: TieredPowerDefinition,
	name: string,
	rung: number
): string {
	return ladderRung( definition, name, rung ).label;
}

/**
 * The Tradition datalist options for one named power: that power's own real offering
 * traditions (`TieredPower.traditions`' keys) when the catalog has it and it carries that
 * map, narrower and more useful than the whole block's list since not every tradition
 * offers every path. Falls back to `definition.traditions` (Blood Magic's real, curated
 * list) when the power itself has no per-power map, and further back to the
 * pre-Blood-Magic convention of harvesting distinct `"X: "` prefixes straight out of the
 * power catalog for any other tiered_power block that still names powers that way.
 *
 * Unaffected by the levels/elder/overflow split - `traditions` lives beside those
 * containers on `TieredPower`, not inside any of them.
 */
export function traditionOptionsFor(
	definition: TieredPowerDefinition,
	name: string
): string[] {
	const blockTraditions =
		definition.traditions ??
		Array.from(
			new Set(
				( definition.powers ?? [] )
					.map( ( power ) => power.name )
					.filter( ( n ) => n.includes( ': ' ) )
					.map( ( n ) => n.slice( 0, n.indexOf( ': ' ) ) )
			)
		).sort();

	// 1.1.0 D5: the owner's own ruling - any power in a flagged set prompts for a
	// Tradition when taken - means the WHOLE block list is always on offer, never
	// narrowed to a path's own catalog-listed teachers (that narrowing is exactly
	// what made Hunter's Wind untakeable as Dur An Ki). The path's own teaching
	// traditions, when the catalog names any, come first for convenience only.
	const power = definition.powers.find( ( p ) => p.name === name );
	const ownTraditions = power?.traditions
		? Object.keys( power.traditions )
		: [];
	const rest = blockTraditions.filter(
		( t ) => ! ownTraditions.includes( t )
	);
	return [ ...ownTraditions, ...rest ];
}

/** One not-yet-held elder-and-above pick offered by the "Add" picker below. */
export interface PickOption {
	/** The string SearchableSelect matches on - never stored, only used to find this option again in addPick(). */
	value: string;
	family: string;
	/** The rank this pick sits under in the family's own `elder` container. */
	rank: string;
	powerName: string;
}

/**
 * Every not-yet-held elder-and-above pick across families the character already holds
 * some form of (a ladder rung or another pick), reading each family's `elder`
 * container ONLY - never `overflow` (never a pick, §A1b) and never `levels` (the
 * ladder, a different control entirely). Scoped to already-held families, matching
 * 0.99.2-workflow.md's own reasoning: reaching Elder-and-above within a discipline
 * presumes some standing in it already.
 *
 * A family can hold several distinct picks at once - this never excludes a family for
 * already holding one, only the specific picks it already has.
 */
export function pickOptionsFor(
	definition: TieredPowerDefinition,
	data: EditableHeldPower[]
): PickOption[] {
	const heldFamilies = new Set(
		data.filter( ( row ) => ! row._removed ).map( ( row ) => row.name )
	);
	const heldPicks = new Set(
		data
			.filter( ( row ) => ! row._removed && row.power_name )
			.map( ( row ) => `${ row.name }\0${ row.power_name }` )
	);

	const options: PickOption[] = [];
	for ( const familyName of heldFamilies ) {
		const power = definition.powers.find( ( p ) => p.name === familyName );
		if ( ! power?.elder ) {
			continue;
		}
		for ( const [ rank, levels ] of Object.entries( power.elder ) ) {
			for ( const level of levels ) {
				if ( ! level.power_name ) {
					continue;
				}
				if (
					heldPicks.has( `${ familyName }\0${ level.power_name }` )
				) {
					continue;
				}
				options.push( {
					value: `${ familyName }: ${ level.power_name }`,
					family: familyName,
					rank,
					powerName: level.power_name,
				} );
			}
		}
	}
	return options;
}

/**
 * Orders whatever ranks are actually present against the block's own declared
 * `_meta.ranks` vocabulary - a rank absent from the declaration (a block predating
 * `_meta`) sorts after every declared one but is never dropped. Never invents a rank
 * that has no options: a caller passes only ranks it already found real entries under,
 * so an empty rank simply never reaches this function, and there is nothing here that
 * could render it as a disabled or greyed-out section.
 */
export function orderRanks( present: string[], declared?: string[] ): string[] {
	if ( ! declared || declared.length === 0 ) {
		return present;
	}
	return [ ...present ].sort( ( a, b ) => {
		const ia = declared.indexOf( a );
		const ib = declared.indexOf( b );
		return (
			( ia === -1 ? declared.length : ia ) -
			( ib === -1 ? declared.length : ib )
		);
	} );
}

/** One rank's worth of grouped pick options, for the "Add an Elder-and-above power" picker. */
export interface PickRankGroup {
	rank: string;
	options: PickOption[];
}

/**
 * `pickOptionsFor()`'s results, sectioned by rank in `_meta.ranks` order - the E2
 * "rank-grouped, elder container only" picker. A rank with real options renders; a
 * rank with none simply is not a key here, never a present-but-empty section, so a
 * character holding two Master powers and no Elder ones sees an Elder section only if
 * an Elder pick actually exists to offer, never a gated/greyed placeholder.
 */
export function groupPickOptions(
	definition: TieredPowerDefinition,
	data: EditableHeldPower[]
): PickRankGroup[] {
	const options = pickOptionsFor( definition, data );
	const byRank = new Map< string, PickOption[] >();
	for ( const option of options ) {
		if ( ! byRank.has( option.rank ) ) {
			byRank.set( option.rank, [] );
		}
		( byRank.get( option.rank ) as PickOption[] ).push( option );
	}
	const order = orderRanks(
		Array.from( byRank.keys() ),
		definition._meta?.ranks
	);
	return order.map( ( rank ) => ( {
		rank,
		options: byRank.get( rank ) as PickOption[],
	} ) );
}

/**
 * The rank a held pick sits under, for grouping the "held" list the same way the "add"
 * picker is grouped. Prefers a live catalog match in `elder` (the common case); falls
 * back to `overflow` (a legacy/imported holding that happens to name an overflow-tier
 * power - still rendered, per §A1b, "as held content", never as a pick to add); falls
 * back to whatever tier the row itself already carries (an unresolved import, D67/the
 * `tier: "***"` placeholder handled by `displayableTier()`); and only then to the
 * generic `'elder'` bucket every prior release has used for "no better answer".
 */
export function pickRankOf(
	definition: TieredPowerDefinition,
	row: EditableHeldPower
): string {
	const power = definition.powers.find( ( p ) => p.name === row.name );
	if ( power?.elder ) {
		for ( const [ rank, levels ] of Object.entries( power.elder ) ) {
			if ( levels.some( ( l ) => l.power_name === row.power_name ) ) {
				return rank;
			}
		}
	}
	if ( power?.overflow ) {
		const found = power.overflow.find(
			( l ) => l.power_name === row.power_name
		);
		const tier = displayableTier( found?.tier );
		if ( tier ) {
			return tier;
		}
	}
	return displayableTier( row.tier ) ?? 'elder';
}

/** Capitalizes a rank word ("elder" -> "Elder") for a section heading; a rank the catalog never lowercases still renders unchanged. */
function rankHeading( rank: string ): string {
	return rank.length === 0 ? rank : rank[ 0 ].toUpperCase() + rank.slice( 1 );
}

/**
 * A safe display label for the reorder-mode drag list, which lists every held row
 * regardless of kind - a ladder holding (no `power_name`) or a pick (`power_name`
 * set). `elderLabel()` is built to describe a pick; calling it on a plain ladder
 * holding looks up a `power_name` that was never set and prints "undefined" (a
 * latent bug in the pre-1.2.10 component, never reached live because `player_order`
 * is not known to combine with a plain ladder holding in production data, but not
 * worth reproducing here either).
 */
function reorderRowLabel(
	definition: TieredPowerDefinition,
	row: EditableHeldPower
): string {
	if ( row.power_name ) {
		return elderLabel( definition, row );
	}
	return row.level != null ? `${ row.name } ${ row.level }` : row.name;
}

/**
 * Renders the held-power list for a tiered_power block: add a power, raise or lower
 * its ladder rating with a stepper (or the equivalent per-rung checklist), add or
 * remove an elder-and-above pick, set its tradition, or mark a row removed.
 */
export function TieredPowerEditor( {
	blockSlug,
	data,
	definition,
	onChange,
	costFor,
	readOnly,
	gameSlug,
	characterId,
}: TieredPowerEditorProps ) {
	const emit = ( next: EditableHeldPower[] ) => onChange( blockSlug, next );

	const ceiling = useMemo(
		() => ladderCeiling( definition ),
		[ definition ]
	);

	// A single per-sheet, player-persisted preference, not a per-power one (Decision
	// 100): every held power on every tiered_power block on this sheet shows the same
	// way, and the choice survives a reload. This toggles the LADDER view only - it
	// never touches `data`, which is what keeps a view switch from being read as an
	// edit (the exact D68 symptom: "switch view, lose levels").
	const [ showChecklist, setShowChecklist ] = usePowerDisplayMode();

	// Phone width only (mobile-sheet-design.md §4.5(4)) - the tradition field and the
	// remove action move behind this modal, matching TraitListEditor's own pattern.
	const [ detailsIndex, setDetailsIndex ] = useState< number | null >( null );

	const heldNames = useMemo(
		() =>
			new Set(
				data
					.filter( ( row ) => ! row._removed )
					.map( ( row ) => row.name )
			),
		[ data ]
	);
	const available = useMemo(
		() =>
			definition.powers
				.map( ( p ) => p.name )
				.filter( ( name ) => ! heldNames.has( name ) ),
		[ definition.powers, heldNames ]
	);

	// --- Reorder mode (player_order blocks only, 1.1.0 D4) ---
	const visibleRows = useMemo(
		() =>
			data
				.map( ( row, index ) => ( { ...row, index } ) )
				.filter( ( row ) => ! row._removed ),
		[ data ]
	);
	const [ reordering, setReordering ] = useState( false );
	const [ order, setOrder ] = useState< number[] >( [] );
	const [ dragPosition, setDragPosition ] = useState< number | null >( null );
	const [ savingOrder, setSavingOrder ] = useState( false );
	const [ orderError, setOrderError ] = useState< string | null >( null );

	const startReorder = () => {
		setOrder( visibleRows.map( ( _, i ) => i ) );
		setOrderError( null );
		setReordering( true );
	};

	const cancelReorder = () => {
		setReordering( false );
		setOrderError( null );
	};

	const saveOrder = async () => {
		if ( ! gameSlug || characterId === undefined ) {
			return;
		}
		setSavingOrder( true );
		setOrderError( null );
		const finalOrder = order.map( ( pos ) => visibleRows[ pos ].index );
		const names = order.map( ( pos ) => visibleRows[ pos ].name );
		try {
			await api
				.characters( gameSlug )
				.saveOrder( characterId, blockSlug, finalOrder, names );
			emit( finalOrder.map( ( i ) => data[ i ] ) );
			setReordering( false );
		} catch ( err ) {
			setOrderError(
				reorderErrorMessage(
					err,
					__(
						'Could not save the new order. Please try again.',
						'beyond-elysium'
					)
				)
			);
		} finally {
			setSavingOrder( false );
		}
	};

	// 1.1.0 D5: the owner's Blood Magic ruling - any power in a flagged set prompts for a
	// Tradition when taken. A blood_magic block's own add pickers below never add
	// immediately; they stash the pick here and wait for a paradigm before confirmPendingAdd()
	// actually adds it, so the row can never be created without one.
	const [ pendingAdd, setPendingAdd ] = useState< PendingAdd | null >( null );
	const [ pendingParadigm, setPendingParadigm ] = useState( '' );

	const addPower = ( name: string, isCustom: boolean ) => {
		const trimmed = name.trim();
		if ( ! trimmed || heldNames.has( trimmed ) ) {
			return;
		}
		if ( definition.blood_magic ) {
			setPendingAdd( { name: trimmed, isCustom } );
			setPendingParadigm( '' );
			return;
		}
		emit( [
			...data,
			isCustom
				? { name: trimmed, level: 1, custom: true }
				: { name: trimmed, level: 1 },
		] );
	};

	// E2: rank-grouped, `elder` container only - never a count, never a rank rendered
	// unavailable because a neighbour is empty (groupPickOptions() only ever returns
	// ranks that genuinely have an option).
	const pickGroups = useMemo(
		() => groupPickOptions( definition, data ),
		[ definition, data ]
	);
	const pickOptions = useMemo(
		() => pickOptionsFor( definition, data ),
		[ definition, data ]
	);
	const pickSearchGroups: OptionGroup[] = useMemo(
		() =>
			pickGroups.map( ( group ) => ( {
				label: rankHeading( group.rank ),
				options: group.options.map( ( o ) => o.value ),
			} ) ),
		[ pickGroups ]
	);

	/**
	 * The one Elder-and-above picker's own input (1.2.11 D93).
	 *
	 * `[Add Elder]` sits under each family's ladder, where the owner expects it, but it
	 * drives this single picker rather than duplicating it. The picker is **block-scoped** -
	 * `pickOptionsFor( definition, data )` spans every held family and each option carries
	 * its own `family` - so there is no one ladder to move it under once a block holds more
	 * than one family, which is the normal case for Disciplines and Blood Magic. One picker,
	 * reachable from each ladder, keeps its existing behaviour exactly.
	 */
	const pickPickerId = `${ blockSlug }-add-elder`;
	const focusPickPicker = () => {
		const input = document.getElementById( pickPickerId );
		if ( ! input ) {
			return;
		}
		input.scrollIntoView( { block: 'nearest' } );
		input.focus();
	};

	const addPick = ( value: string ) => {
		const found = pickOptions.find( ( o ) => o.value === value );
		if ( ! found ) {
			return;
		}
		if ( definition.blood_magic ) {
			setPendingAdd( {
				name: found.family,
				powerName: found.powerName,
				rank: found.rank,
			} );
			setPendingParadigm( '' );
			return;
		}
		emit( [
			...data,
			{
				name: found.family,
				power_name: found.powerName,
				tier: found.rank,
			},
		] );
	};

	const confirmPendingAdd = () => {
		if ( ! pendingAdd || ! pendingParadigm ) {
			return;
		}
		emit( [
			...data,
			pendingAdd.powerName
				? {
						name: pendingAdd.name,
						power_name: pendingAdd.powerName,
						...( pendingAdd.rank ? { tier: pendingAdd.rank } : {} ),
						tradition: pendingParadigm,
				  }
				: {
						name: pendingAdd.name,
						level: 1,
						...( pendingAdd.isCustom ? { custom: true } : {} ),
						tradition: pendingParadigm,
				  },
		] );
		setPendingAdd( null );
		setPendingParadigm( '' );
	};

	const cancelPendingAdd = () => {
		setPendingAdd( null );
		setPendingParadigm( '' );
	};

	/** Writes a new, already-safe level onto one row - never re-clamps a caller's value, so the two hazards documented on incrementLevel()/decrementLevel() stay real guarantees rather than being undone here. */
	const applyLevel = ( index: number, level: number ) => {
		const next = [ ...data ];
		next[ index ] = { ...next[ index ], level: Math.max( 1, level ) };
		emit( next );
	};

	const toggleRemoved = ( index: number ) => {
		const next = [ ...data ];
		next[ index ] = {
			...next[ index ],
			_removed: ! next[ index ]._removed,
		};
		emit( next );
	};

	const setTradition = ( index: number, tradition: string ) => {
		const next = [ ...data ];
		const trimmed = tradition.trim();
		if ( trimmed === '' ) {
			const { tradition: _drop, ...rest } = next[ index ];
			next[ index ] = rest;
		} else {
			next[ index ] = { ...next[ index ], tradition: trimmed };
		}
		emit( next );
	};

	const traditionListId = ( index: number ) =>
		`be-tradition-${ blockSlug }-${ index }`;

	// The two controls, split at the data level so a view switch cannot read as an
	// edit: `ladderRows` drives the stepper/checklist, `pickRows` is grouped by rank
	// below it. Both keep their original index into `data` for every mutation.
	const indexed = useMemo(
		() => data.map( ( row, index ) => ( { row, index } ) ),
		[ data ]
	);
	const ladderRows = useMemo(
		() => indexed.filter( ( item ) => ! item.row.power_name ),
		[ indexed ]
	);
	const pickRowGroups = useMemo( () => {
		const rows = indexed.filter( ( item ) => !! item.row.power_name );
		const byRank = new Map< string, typeof rows >();
		for ( const item of rows ) {
			const rank = pickRankOf( definition, item.row );
			if ( ! byRank.has( rank ) ) {
				byRank.set( rank, [] );
			}
			( byRank.get( rank ) as typeof rows ).push( item );
		}
		const rankOrder = orderRanks(
			Array.from( byRank.keys() ),
			definition._meta?.ranks
		);
		return rankOrder.map( ( rank ) => ( {
			rank,
			rows: byRank.get( rank ) as typeof rows,
		} ) );
	}, [ indexed, definition ] );

	return (
		<div className="be-tiered-power-editor" data-block-slug={ blockSlug }>
			{ definition.player_order &&
				! readOnly &&
				gameSlug &&
				characterId !== undefined &&
				! reordering && (
					<button
						type="button"
						className="be-tiered-power-editor__reorder-trigger"
						onClick={ startReorder }
					>
						{ __( 'Reorder', 'beyond-elysium' ) }
					</button>
				) }
			{ reordering ? (
				<>
					<ul className="be-tiered-power-editor__rows be-tiered-power-editor__rows--reorder">
						{ order.map( ( pos, uiIndex ) => {
							const row = visibleRows[ pos ];
							return (
								<li
									key={ `${ row.name }-${ row.index }` }
									className="be-tiered-power-editor__row"
									draggable
									onDragStart={ () =>
										setDragPosition( uiIndex )
									}
									onDragOver={ ( e ) => e.preventDefault() }
									onDrop={ () => {
										if ( dragPosition !== null ) {
											setOrder( ( prev ) =>
												moveTo(
													prev,
													dragPosition,
													uiIndex
												)
											);
										}
										setDragPosition( null );
									} }
								>
									<span
										className="be-tiered-power-editor__drag-handle"
										aria-hidden="true"
									>
										⠿
									</span>
									<span className="be-tiered-power-editor__name">
										{ reorderRowLabel( definition, row ) }
									</span>
									<button
										type="button"
										aria-label={ sprintf(
											/* translators: %1$s: power name */
											__(
												'Move %1$s up',
												'beyond-elysium'
											),
											row.name
										) }
										disabled={ uiIndex === 0 }
										onClick={ () =>
											setOrder( ( prev ) =>
												moveUp( prev, uiIndex )
											)
										}
									>
										{ __( '▲', 'beyond-elysium' ) }
									</button>
									<button
										type="button"
										aria-label={ sprintf(
											/* translators: %1$s: power name */
											__(
												'Move %1$s down',
												'beyond-elysium'
											),
											row.name
										) }
										disabled={
											uiIndex === order.length - 1
										}
										onClick={ () =>
											setOrder( ( prev ) =>
												moveDown( prev, uiIndex )
											)
										}
									>
										{ __( '▼', 'beyond-elysium' ) }
									</button>
								</li>
							);
						} ) }
					</ul>
					{ orderError && (
						<p
							className="be-tiered-power-editor__order-error"
							role="alert"
						>
							{ orderError }
						</p>
					) }
					<div className="be-tiered-power-editor__order-actions">
						<button
							type="button"
							onClick={ saveOrder }
							disabled={ savingOrder }
						>
							{ savingOrder
								? __( 'Saving…', 'beyond-elysium' )
								: __( 'Save order', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							onClick={ cancelReorder }
							disabled={ savingOrder }
						>
							{ __( 'Cancel', 'beyond-elysium' ) }
						</button>
					</div>
				</>
			) : (
				<>
					{ /* E1: the ladder. Bounded by `ceiling` alone - never able to reach or
					 * represent a pick. */ }
					<ul className="be-tiered-power-editor__rows">
						{ ladderRows.map( ( { row, index } ) => {
							const cost = costFor?.( row ) ?? null;
							const level = row.level ?? 1;
							const needsTradition =
								( definition.blood_magic ?? false ) &&
								! row._removed &&
								! row.tradition;
							return (
								<li
									key={ `${ row.name }-${ index }` }
									className={
										'be-tiered-power-editor__row' +
										( row._removed
											? ' be-tiered-power-editor__row--removed'
											: '' ) +
										( needsTradition
											? ' be-tiered-power-editor__row--needs-tradition'
											: '' )
									}
								>
									<span className="be-tiered-power-editor__name">
										{ row.name }
									</span>
									{ ! readOnly && (
										<button
											type="button"
											className="be-tiered-power-editor__view-toggle"
											onClick={ () =>
												setShowChecklist(
													! showChecklist
												)
											}
											aria-pressed={ showChecklist }
											title={ __(
												'Applies to every power on this sheet, not just this one',
												'beyond-elysium'
											) }
										>
											{ showChecklist
												? __(
														'Use stepper',
														'beyond-elysium'
												  )
												: __(
														'List each level',
														'beyond-elysium'
												  ) }
										</button>
									) }
									{ showChecklist ? (
										<>
											<ol className="be-tiered-power-editor__checklist">
												{ Array.from(
													{ length: ceiling },
													( _unused, i ) => i + 1
												).map( ( rung ) => {
													const ladder = ladderRung(
														definition,
														row.name,
														rung
													);
													const alternates =
														ladder.alternates.join(
															', '
														);
													return (
														<li key={ rung }>
															<label>
																<input
																	type="checkbox"
																	checked={
																		level >=
																		rung
																	}
																	disabled={
																		readOnly ||
																		row._removed
																	}
																	onChange={ () =>
																		applyLevel(
																			index,
																			clampToCeiling(
																				level >=
																					rung
																					? rung -
																							1
																					: rung,
																				ceiling
																			)
																		)
																	}
																/>
																<span className="be-tiered-power-editor__rung-number">
																	{ sprintf(
																		/* translators: %d: the rung's number on the ladder */
																		__(
																			'%d.',
																			'beyond-elysium'
																		),
																		rung
																	) }
																</span>
																{ ladder.label }
																{ alternates && (
																	<span
																		className="be-tiered-power-editor__rung-alternates"
																		title={ sprintf(
																			/* translators: %s: the other power names filed at this same rung */
																			__(
																				'Also at this level: %s',
																				'beyond-elysium'
																			),
																			alternates
																		) }
																		aria-label={ sprintf(
																			/* translators: %s: the other power names filed at this same rung */
																			__(
																				'Also at this level: %s',
																				'beyond-elysium'
																			),
																			alternates
																		) }
																	>
																		{ sprintf(
																			/* translators: %d: how many other names share this rung */
																			__(
																				'+%d',
																				'beyond-elysium'
																			),
																			ladder
																				.alternates
																				.length
																		) }
																	</span>
																) }
															</label>
														</li>
													);
												} ) }
											</ol>
											{ ! readOnly &&
												! row._removed &&
												pickSearchGroups.length > 0 && (
													<button
														type="button"
														className="be-tiered-power-editor__add-elder"
														onClick={
															focusPickPicker
														}
													>
														{ __(
															'Add Elder',
															'beyond-elysium'
														) }
													</button>
												) }
										</>
									) : (
										<div className="be-tiered-power-editor__stepper">
											<button
												type="button"
												className="be-tiered-power-editor__stepper-button"
												disabled={
													readOnly ||
													row._removed ||
													level <= 1
												}
												onClick={ () =>
													applyLevel(
														index,
														decrementLevel( level )
													)
												}
												aria-label={ sprintf(
													/* translators: %s: the power family's own name */
													__(
														'Decrease %s',
														'beyond-elysium'
													),
													row.name
												) }
											>
												{ __( '−', 'beyond-elysium' ) }
											</button>
											<span className="be-tiered-power-editor__level">
												{ level }
											</span>
											<button
												type="button"
												className="be-tiered-power-editor__stepper-button"
												disabled={
													readOnly ||
													row._removed ||
													incrementLevel(
														level,
														ceiling
													) === level
												}
												onClick={ () =>
													applyLevel(
														index,
														incrementLevel(
															level,
															ceiling
														)
													)
												}
												aria-label={ sprintf(
													/* translators: %s: the power family's own name */
													__(
														'Increase %s',
														'beyond-elysium'
													),
													row.name
												) }
											>
												{ __( '+', 'beyond-elysium' ) }
											</button>
										</div>
									) }

									{ readOnly && row.tradition && (
										<span className="be-tiered-power-editor__tradition-text">
											{ row.tradition }
										</span>
									) }

									{ cost !== null && (
										<span className="be-tiered-power-editor__cost">
											{ sprintf(
												/* translators: %1$d: XP cost */
												__(
													'%1$d XP',
													'beyond-elysium'
												),
												cost
											) }
										</span>
									) }

									{ ! readOnly && (
										<div className="be-tiered-power-editor__row-detail">
											{ ( definition.blood_magic ??
												false ) && (
												<>
													<datalist
														id={ traditionListId(
															index
														) }
													>
														{ traditionOptionsFor(
															definition,
															row.name
														).map( ( t ) => (
															<option
																key={ t }
																value={ t }
															/>
														) ) }
													</datalist>
													<input
														type="text"
														className={
															'be-tiered-power-editor__tradition' +
															( needsTradition
																? ' be-tiered-power-editor__tradition--required'
																: '' )
														}
														list={ traditionListId(
															index
														) }
														value={
															row.tradition ?? ''
														}
														placeholder={
															needsTradition
																? __(
																		'Choose paradigm',
																		'beyond-elysium'
																  )
																: __(
																		'Tradition',
																		'beyond-elysium'
																  )
														}
														aria-label={ sprintf(
															/* translators: %s: the power family's own name */
															__(
																'Tradition for %s',
																'beyond-elysium'
															),
															row.name
														) }
														aria-required={
															definition.blood_magic ??
															false
														}
														disabled={
															row._removed
														}
														onChange={ ( e ) =>
															setTradition(
																index,
																e.target.value
															)
														}
													/>
												</>
											) }
											<button
												type="button"
												className="be-tiered-power-editor__remove"
												onClick={ () =>
													toggleRemoved( index )
												}
												aria-label={
													row._removed
														? sprintf(
																/* translators: %s: the power family's own name */
																__(
																	'Undo removing %s',
																	'beyond-elysium'
																),
																row.name
														  )
														: sprintf(
																/* translators: %s: the power family's own name */
																__(
																	'Remove %s',
																	'beyond-elysium'
																),
																row.name
														  )
												}
											>
												{ row._removed
													? __(
															'Undo',
															'beyond-elysium'
													  )
													: __(
															'Remove',
															'beyond-elysium'
													  ) }
											</button>
										</div>
									) }
									{ ! readOnly && (
										<button
											type="button"
											className="be-tiered-power-editor__details-toggle"
											onClick={ () =>
												setDetailsIndex( index )
											}
										>
											{ __(
												'Details',
												'beyond-elysium'
											) }
										</button>
									) }
								</li>
							);
						} ) }
					</ul>

					{ ! readOnly && ! pendingAdd && (
						<div className="be-tiered-power-editor__add">
							<SearchableSelect
								options={ available }
								value=""
								placeholder={ __(
									'Add power…',
									'beyond-elysium'
								) }
								ariaLabel={ __(
									'Add power',
									'beyond-elysium'
								) }
								allowCustom={ definition.allow_custom ?? false }
								onChange={ addPower }
							/>
						</div>
					) }

					{ /* E2: rank-grouped, `elder` container only. No count anywhere on
					 * this list - what a family holds at each rank is simply present or
					 * not, never "N of M". */ }
					{ pickRowGroups.length > 0 && (
						<div className="be-tiered-power-editor__picks">
							{ pickRowGroups.map( ( group ) => (
								<div
									key={ group.rank }
									className="be-tiered-power-editor__picks-rank"
								>
									<h4 className="be-tiered-power-editor__picks-heading">
										{ rankHeading( group.rank ) }
									</h4>
									<ul className="be-tiered-power-editor__picks-list">
										{ group.rows.map(
											( { row, index } ) => {
												const cost =
													costFor?.( row ) ?? null;
												return (
													<li
														key={ `${ row.name }-${ index }` }
														className={
															'be-tiered-power-editor__row' +
															( row._removed
																? ' be-tiered-power-editor__row--removed'
																: '' )
														}
													>
														<span className="be-tiered-power-editor__name">
															{ elderLabel(
																definition,
																row
															) }
														</span>
														{ readOnly &&
															row.tradition && (
																<span className="be-tiered-power-editor__tradition-text">
																	{
																		row.tradition
																	}
																</span>
															) }
														{ cost !== null && (
															<span className="be-tiered-power-editor__cost">
																{ sprintf(
																	/* translators: %1$d: XP cost */
																	__(
																		'%1$d XP',
																		'beyond-elysium'
																	),
																	cost
																) }
															</span>
														) }
														{ ! readOnly && (
															<button
																type="button"
																className="be-tiered-power-editor__remove"
																onClick={ () =>
																	toggleRemoved(
																		index
																	)
																}
															>
																{ row._removed
																	? __(
																			'Undo',
																			'beyond-elysium'
																	  )
																	: __(
																			'Remove',
																			'beyond-elysium'
																	  ) }
															</button>
														) }
													</li>
												);
											}
										) }
									</ul>
								</div>
							) ) }
						</div>
					) }

					{ ! readOnly &&
						! pendingAdd &&
						pickSearchGroups.length > 0 && (
							<div className="be-tiered-power-editor__add">
								<SearchableSelect
									id={ pickPickerId }
									groups={ pickSearchGroups }
									value=""
									placeholder={ __(
										'Add an Elder-and-above power…',
										'beyond-elysium'
									) }
									ariaLabel={ __(
										'Add an Elder-and-above power',
										'beyond-elysium'
									) }
									allowCustom={ false }
									onChange={ addPick }
								/>
							</div>
						) }

					{ pendingAdd && (
						<div className="be-tiered-power-editor__pending-add">
							<span className="be-tiered-power-editor__pending-add-name">
								{ sprintf(
									/* translators: %s: the power (or family: power) being added */
									__( 'Paradigm for %s:', 'beyond-elysium' ),
									pendingAdd.powerName
										? `${ pendingAdd.name }: ${ pendingAdd.powerName }`
										: pendingAdd.name
								) }
							</span>
							<select
								value={ pendingParadigm }
								onChange={ ( e ) =>
									setPendingParadigm( e.target.value )
								}
								aria-label={ __(
									'Paradigm',
									'beyond-elysium'
								) }
							>
								<option value="">
									{ __(
										'Choose paradigm…',
										'beyond-elysium'
									) }
								</option>
								{ traditionOptionsFor(
									definition,
									pendingAdd.name
								).map( ( t ) => (
									<option key={ t } value={ t }>
										{ t }
									</option>
								) ) }
							</select>
							<button
								type="button"
								onClick={ confirmPendingAdd }
								disabled={ ! pendingParadigm }
							>
								{ __( 'Add', 'beyond-elysium' ) }
							</button>
							<button type="button" onClick={ cancelPendingAdd }>
								{ __( 'Cancel', 'beyond-elysium' ) }
							</button>
						</div>
					) }
				</>
			) }

			{ detailsIndex !== null && data[ detailsIndex ] && (
				<Modal
					title={ sprintf(
						/* translators: %s: the power family's own name */
						__( 'Details for %s', 'beyond-elysium' ),
						data[ detailsIndex ].name
					) }
					onClose={ () => setDetailsIndex( null ) }
					footer={
						<button
							type="button"
							onClick={ () => setDetailsIndex( null ) }
						>
							{ __( 'Close', 'beyond-elysium' ) }
						</button>
					}
				>
					{ ( definition.blood_magic ?? false ) && (
						<div className="be-tiered-power-editor__modal-field">
							<label
								htmlFor={ `${ traditionListId(
									detailsIndex
								) }-modal` }
							>
								{ __( 'Tradition', 'beyond-elysium' ) }
							</label>
							<datalist
								id={ `${ traditionListId(
									detailsIndex
								) }-modal-list` }
							>
								{ traditionOptionsFor(
									definition,
									data[ detailsIndex ].name
								).map( ( t ) => (
									<option key={ t } value={ t } />
								) ) }
							</datalist>
							<input
								id={ `${ traditionListId(
									detailsIndex
								) }-modal` }
								type="text"
								list={ `${ traditionListId(
									detailsIndex
								) }-modal-list` }
								value={ data[ detailsIndex ].tradition ?? '' }
								disabled={ data[ detailsIndex ]._removed }
								onChange={ ( e ) =>
									setTradition( detailsIndex, e.target.value )
								}
							/>
						</div>
					) }
					<button
						type="button"
						className="be-tiered-power-editor__remove"
						onClick={ () => toggleRemoved( detailsIndex ) }
					>
						{ data[ detailsIndex ]._removed
							? __( 'Undo removal', 'beyond-elysium' )
							: __( 'Remove', 'beyond-elysium' ) }
					</button>
				</Modal>
			) }
		</div>
	);
}

export default TieredPowerEditor;
