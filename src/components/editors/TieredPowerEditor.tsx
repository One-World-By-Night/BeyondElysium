/**
 * TieredPowerEditor renders the held-power list for a tiered_power block - leveled
 * catalogs such as Disciplines, Arcanoi, or Gifts. Lets a player add a power, raise
 * or lower its level with a stepper, set its tradition, or mark it removed. Accepts
 * a catalog entry or, when the block allows it, a free-text custom power name.
 *
 * The tradition field is a free-text input with datalist suggestions for every
 * tiered_power block, not just Blood Magic - it predates the Blood Magic redesign
 * (BE_PROCESS/releases/0.99.2-workflow.md) as a generic "annotate which sorcery tradition taught
 * this" field. For a `blood_magic`-flagged block, adding a power requires a paradigm up
 * front - the picker shows every tradition the whole block offers, the power's own
 * teaching traditions first, never narrowed to just them (1.1.0 D5: that narrowing is
 * exactly what made Hunter's Wind untakeable as Dur An Ki). A held power with no
 * paradigm (older data) still shows "Choose paradigm" on its row, never forced - a
 * Storyteller reviewing the change is the actual check, not a client-side hard block
 * (Decision 057's UI-affordance pattern).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import SearchableSelect from '../shared/SearchableSelect';
import Modal from '../shared/Modal';
import { usePowerDisplayMode } from '../../lib/powerDisplayMode';
import {
	elderLabel,
	findLevelsAtRank,
	TIER_FOR_RANK,
	type HeldPower,
} from '../renderers/TieredPowerRenderer';
import {
	moveUp,
	moveDown,
	moveTo,
	reorderErrorMessage,
} from '../../lib/reorderArray';
import api from '../../api/client';
import type {
	PowerLevel,
	TieredPower,
	TieredPowerDefinition,
} from '../../types';
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
}

export interface TieredPowerEditorProps {
	blockSlug: string;
	data: EditableHeldPower[];
	definition: TieredPowerDefinition;
	onChange: ( blockSlug: string, nextData: EditableHeldPower[] ) => void;
	/** Returns the XP cost for a held power at its current level; omitted callers show no cost. */
	costFor?: ( power: EditableHeldPower ) => number | null;
	/** Overrides the stepper's maximum level for a named power; undefined falls back to the default. */
	trueMaxFor?: ( name: string ) => number | undefined;
	readOnly?: boolean;
	/** Needed only for a player_order block's "Save order" call (1.1.0 D4). */
	gameSlug?: string;
	characterId?: number;
}

/** The stepper's default maximum level when no per-power override applies. */
const DEFAULT_TRUE_MAX = 5;

/** Reverse of `TIER_FOR_RANK` - a tier's own numbered rank, when it has one. */
const RANK_FOR_TIER: Partial< Record< string, number > > = Object.fromEntries(
	Object.entries( TIER_FOR_RANK ).map( ( [ rank, tier ] ) => [
		tier,
		Number( rank ),
	] )
);

/**
 * Returns the highest level a named power can be raised to: an explicit trueMaxFor
 * override when given, otherwise DEFAULT_TRUE_MAX clamped to the highest real rank
 * the power's definition actually reaches. D66 (1.2.5-design-workflow.md §A2): a
 * tied top tier (several items sharing it, `level: null` on all of them) is still a
 * real, purchasable rank - reading `level` alone would undercount the true max
 * whenever the family's own highest tier happens to be tied, so this falls back to
 * the tier's own derived rank whenever an item's `level` is null, preferring the
 * item's own explicit `level` first when it has one (an untied rung, or a synthetic
 * custom-power ladder that deliberately reuses a tier label across two distinct
 * explicit levels).
 */
export function maxLevel(
	definition: TieredPowerDefinition,
	name: string,
	trueMaxFor?: ( name: string ) => number | undefined
): number {
	const override = trueMaxFor?.( name );
	if ( override != null ) {
		return override;
	}

	const power = definition.powers.find( ( p ) => p.name === name );
	const ranks = ( power?.levels ?? [] )
		.map( ( l ) => l.level ?? RANK_FOR_TIER[ l.tier ] ?? undefined )
		.filter( ( r ): r is number => r != null );
	if ( ranks.length === 0 ) {
		// Guards against an empty ladder; falls back to the default max instead of pinning at 1.
		return DEFAULT_TRUE_MAX;
	}
	return Math.min( DEFAULT_TRUE_MAX, Math.max( ...ranks ) );
}

const CUSTOM_LEVEL_NAMES = [ 'One', 'Two', 'Three', 'Four', 'Five' ];
/** Tier for each of the five custom levels: 1-2 basic, 3-4 intermediate, 5 advanced. */
const CUSTOM_LEVEL_TIERS: PowerLevel[ 'tier' ][] = [
	'basic',
	'basic',
	'intermediate',
	'intermediate',
	'advanced',
];

/**
 * Returns a copy of the block definition with a synthetic five-level ladder added
 * for every custom (free-text) power held in data, using placeholder names "One"
 * through "Five". Computed fresh from the held rows on each call rather than stored,
 * so maxLevel() can look up a real ladder for a custom power the same way it does
 * for any catalog power.
 */
export function withCustomLadders(
	definition: TieredPowerDefinition,
	data: EditableHeldPower[]
): TieredPowerDefinition {
	const customNames = new Set(
		data.filter( ( row ) => row.custom ).map( ( row ) => row.name )
	);
	if ( customNames.size === 0 ) {
		return definition;
	}
	const synthetic: TieredPower[] = Array.from( customNames ).map(
		( name ) => ( {
			name,
			levels: CUSTOM_LEVEL_NAMES.map(
				( powerName, i ): PowerLevel => ( {
					level: i + 1,
					tier: CUSTOM_LEVEL_TIERS[ i ],
					power_name: powerName,
				} )
			),
		} )
	);
	return { ...definition, powers: [ ...definition.powers, ...synthetic ] };
}

/**
 * The Tradition datalist options for one named power: that power's own real offering
 * traditions (`TieredPower.traditions`' keys) when the catalog has it and it carries that
 * map, narrower and more useful than the whole block's list since not every tradition
 * offers every path. Falls back to `definition.traditions` (Blood Magic's real, curated
 * list) when the power itself has no per-power map, and further back to the
 * pre-Blood-Magic convention of harvesting distinct `"X: "` prefixes straight out of the
 * power catalog for any other tiered_power block that still names powers that way.
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

/**
 * The real catalog name(s) for one specific numbered rung of a family (e.g. "Alacrity"
 * for Celerity's level 1), for the checklist view's per-box labels - falls back to a
 * plain "{name} {level}" when the catalog has no entry there (a custom power's
 * synthetic ladder, or a genuine gap in the seeded data), so a box is never left
 * unlabeled. D66 (1.2.5-design-workflow.md §A2, "never roll up"): a rung tied between
 * several named alternatives joins every one of their names, rather than falling
 * through to the generic placeholder just because no single item carries that exact
 * `level` - one checkbox still toggles the whole tied rank, matching how a character
 * genuinely knows every power at a rank they've reached, not just one chosen pick.
 */
export function levelName(
	definition: TieredPowerDefinition,
	name: string,
	level: number
): string {
	const power = definition.powers.find( ( p ) => p.name === name );
	const atRank = findLevelsAtRank( power, level );
	if ( atRank.length === 0 ) {
		return `${ name } ${ level }`;
	}
	return atRank
		.map( ( l ) => l.power_name || `${ name } ${ level }` )
		.join( ', ' );
}

/**
 * Every not-yet-held Elder-and-above pick across families the character already holds
 * some form of, as `{value, label}` pairs labeled "{Family}: {PowerName}" for the picker
 * below - scoped to already-held families only, since reaching Elder-and-above within a
 * discipline presumes some standing in it already, matching how the real catalog data is
 * shaped (0.99.2-workflow.md "Cost_Engine cannot price an Elder-tier purchase"). A family
 * can hold several distinct Elder+ picks at once, so this never excludes a family just for
 * already holding one - only the specific picks it already has are excluded.
 */
export function elderPickOptions(
	definition: TieredPowerDefinition,
	data: EditableHeldPower[],
	trueMaxFor?: ( name: string ) => number | undefined
): { value: string; family: string; powerName: string }[] {
	const heldFamilies = new Set(
		data.filter( ( row ) => ! row._removed ).map( ( row ) => row.name )
	);
	const heldPicks = new Set(
		data
			.filter( ( row ) => ! row._removed && row.power_name )
			.map( ( row ) => `${ row.name }\0${ row.power_name }` )
	);

	const options: { value: string; family: string; powerName: string }[] = [];
	for ( const familyName of heldFamilies ) {
		const power = definition.powers.find( ( p ) => p.name === familyName );
		if ( ! power ) {
			continue;
		}
		const cap = maxLevel( definition, familyName, trueMaxFor );
		for ( const level of power.levels ) {
			if ( ! level.power_name ) {
				continue;
			}
			// Already reachable via the stepper/checklist (a real numbered rung within the
			// displayed range) - only rungs beyond that range are this picker's concern.
			if ( level.level != null && level.level <= cap ) {
				continue;
			}
			if ( heldPicks.has( `${ familyName }\0${ level.power_name }` ) ) {
				continue;
			}
			options.push( {
				value: `${ familyName }: ${ level.power_name }`,
				family: familyName,
				powerName: level.power_name,
			} );
		}
	}
	return options;
}

/**
 * Renders the held-power list for a tiered_power block: add a power, raise or lower
 * its level with a stepper, set its tradition, or mark it removed. A sequential block
 * treats level n as holding every level from 1 to n, so each power gets a single
 * stepper rather than a row of individual checkboxes.
 */
export function TieredPowerEditor( {
	blockSlug,
	data,
	definition,
	onChange,
	costFor,
	trueMaxFor,
	readOnly,
	gameSlug,
	characterId,
}: TieredPowerEditorProps ) {
	const emit = ( next: EditableHeldPower[] ) => onChange( blockSlug, next );

	// A single per-sheet, player-persisted preference, not a per-power one (user's own
	// correction, 2026-09-13): "it's per sheet - I can change it as a player if I want it
	// one way or the other." Every held power on every tiered_power block on this sheet
	// shows the same way; toggling it anywhere changes all of them, and the choice
	// survives a reload (0.99.2-workflow.md, "the gap" - accounting for both display needs).
	const [ showChecklist, setShowChecklist ] = usePowerDisplayMode();

	// Phone width only (mobile-sheet-design.md §4.5(4)) - the tradition field and the
	// remove action move behind this modal, the same summary-row-plus-modal shape
	// TraitListEditor already established (Decision 053), rather than the six-control
	// inline row that survives only by wrapping raggedly at 375px. The stepper stays
	// inline everywhere: it is the primary, most-frequent interaction, not a rare one.
	const [ detailsIndex, setDetailsIndex ] = useState< number | null >( null );

	// Memoized: large power catalogs make this expensive to recompute on every keystroke.
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
	// Used only for level-capping below; suggestions still use the real catalog, not placeholder names.
	const definitionWithCustomLadders = useMemo(
		() => withCustomLadders( definition, data ),
		[ definition, data ]
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

	// A family can hold several distinct Elder-and-above picks at once (0.99.2-workflow.md:
	// "you can have multiple powers at those levels") - this is additive alongside a
	// family's own numbered holding, never a replacement for it, so it stays a separate
	// picker rather than folded into "Add power" above (which starts a brand-new family).
	const elderOptions = useMemo(
		() => elderPickOptions( definition, data, trueMaxFor ),
		[ definition, data, trueMaxFor ]
	);
	const addElderPick = ( value: string ) => {
		const found = elderOptions.find( ( o ) => o.value === value );
		if ( ! found ) {
			return;
		}
		if ( definition.blood_magic ) {
			setPendingAdd( { name: found.family, powerName: found.powerName } );
			setPendingParadigm( '' );
			return;
		}
		emit( [
			...data,
			{ name: found.family, power_name: found.powerName },
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

	const setLevel = ( index: number, level: number ) => {
		const clamped = Math.max(
			1,
			Math.min(
				level,
				maxLevel(
					definitionWithCustomLadders,
					data[ index ].name,
					trueMaxFor
				)
			)
		);
		const next = [ ...data ];
		next[ index ] = { ...next[ index ], level: clamped };
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
										{ elderLabel( definition, row ) }
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
					<ul className="be-tiered-power-editor__rows">
						{ data.map( ( row, index ) => {
							const cost = costFor?.( row ) ?? null;
							// Rows created here always have a numeric level; this default is only type narrowing.
							const level = row.level ?? 1;
							// Blood magic only: a power taken from a blood_magic-flagged block has no
							// meaning without knowing which tradition taught it - visually required,
							// though the actual gate on an incomplete pick is the Storyteller's review
							// (Decision 057's UI-affordance pattern: the client nudges, the person
							// approving the change is the real check).
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
									{ row.power_name ? (
										// An Elder-and-above pick (Decision 037) is a discrete choice already
										// made via the picker below, not a dot rating - no stepper or checklist
										// to raise or lower, only removal.
										<span className="be-tiered-power-editor__name">
											{ elderLabel( definition, row ) }
										</span>
									) : (
										<>
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
													aria-pressed={
														showChecklist
													}
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
												<ul className="be-tiered-power-editor__checklist">
													{ Array.from(
														{
															length: maxLevel(
																definitionWithCustomLadders,
																row.name,
																trueMaxFor
															),
														},
														( _unused, i ) => i + 1
													).map( ( rung ) => (
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
																		setLevel(
																			index,
																			level >=
																				rung
																				? rung -
																						1
																				: rung
																		)
																	}
																/>
																{ levelName(
																	definitionWithCustomLadders,
																	row.name,
																	rung
																) }
															</label>
														</li>
													) ) }
												</ul>
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
															setLevel(
																index,
																level - 1
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
														{ __(
															'−',
															'beyond-elysium'
														) }
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
															level >=
																maxLevel(
																	definitionWithCustomLadders,
																	row.name,
																	trueMaxFor
																)
														}
														onClick={ () =>
															setLevel(
																index,
																level + 1
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
														{ __(
															'+',
															'beyond-elysium'
														) }
													</button>
												</div>
											) }
										</>
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

									{ /* Desktop: tradition + remove stay inline, exactly as before. Phone width
									 * (§4.5(4)): both collapse behind the "Details" trigger and its modal below -
									 * .be-tiered-power-editor__row-detail is display:none there, the trigger is
									 * display:none everywhere else, matching the CSS-only dual-markup pattern
									 * already used for ApprovalQueue's own MS-9 disclosure. */ }
									{ ! readOnly && (
										<div className="be-tiered-power-editor__row-detail">
											<datalist
												id={ traditionListId( index ) }
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
												value={ row.tradition ?? '' }
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
												disabled={ row._removed }
												onChange={ ( e ) =>
													setTradition(
														index,
														e.target.value
													)
												}
											/>
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

					{ ! readOnly && ! pendingAdd && elderOptions.length > 0 && (
						<div className="be-tiered-power-editor__add">
							<SearchableSelect
								options={ elderOptions.map( ( o ) => o.value ) }
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
								onChange={ addElderPick }
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
							id={ `${ traditionListId( detailsIndex ) }-modal` }
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
