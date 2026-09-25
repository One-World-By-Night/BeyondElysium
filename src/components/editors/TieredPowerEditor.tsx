/**
 * TieredPowerEditor renders the held-power list for a tiered_power block.
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

/**
 * Marked for removal rather than deleted outright.
 */
export interface EditableHeldPower extends HeldPower {
	_removed?: boolean;
	/**
	 * Set when this row was entered as free text.
	 */
	custom?: boolean;
}

/**
 * A blood_magic block's picked-but-not-yet-confirmed add, awaiting a paradigm.
 */
interface PendingAdd {
	name: string;
	isCustom?: boolean;
	powerName?: string;
	/**
	 * Set only for an elder-and-above pick.
	 */
	rank?: string;
}

export interface TieredPowerEditorProps {
	blockSlug: string;
	data: EditableHeldPower[];
	definition: TieredPowerDefinition;
	onChange: ( blockSlug: string, nextData: EditableHeldPower[] ) => void;
	/**
	 * Returns the XP cost for a held power at its current level.
	 */
	costFor?: ( power: EditableHeldPower ) => number | null;
	readOnly?: boolean;
	/**
	 * Needed only for a player_order block's "Save order" call.
	 */
	gameSlug?: string;
	characterId?: number;
}

/**
 * The stepper/checklist ceiling when a block predates `_meta` entirely.
 */
const DEFAULT_LADDER_CEILING = 5;

/**
 * The number of rungs the declared ladder actually has.
 */
export function ladderCeiling( definition: TieredPowerDefinition ): number {
	const ladder = definition._meta?.ladder;
	if ( ladder === undefined || ladder === null ) {
		return DEFAULT_LADDER_CEILING;
	}
	return Object.values( ladder ).reduce( ( a, b ) => a + b, 0 );
}

/**
 * One step up, or the same level unchanged once the ladder ceiling is reached.
 */
export function incrementLevel( level: number, ceiling: number ): number {
	return level >= ceiling ? level : level + 1;
}

/**
 * One step down, floored at 1.
 */
export function decrementLevel( level: number ): number {
	return Math.max( 1, level - 1 );
}

/**
 * Clamps an explicit target level into `[1, ceiling]`.
 */
export function clampToCeiling( level: number, ceiling: number ): number {
	return Math.max( 1, Math.min( level, ceiling ) );
}

/**
 * One rung of the declared ladder, as the checklist shows it.
 */
export interface LadderRung {
	/**
	 * The single name on the checkbox line.
	 */
	label: string;
	/**
	 * Every other name filed at that rank, kept as a note.
	 */
	alternates: string[];
}

/**
 * One rung of the declared ladder, named once.
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

	// The base printing where there is one.
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
 * One rung's own checkbox label.
 */
export function ladderRungLabel(
	definition: TieredPowerDefinition,
	name: string,
	rung: number
): string {
	return ladderRung( definition, name, rung ).label;
}

/**
 * The Tradition datalist options for one named power.
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
 * One not-yet-held elder-and-above pick offered by the "Add" picker below.
 */
export interface PickOption {
	/**
	 * The string SearchableSelect matches.
	 */
	value: string;
	family: string;
	/**
	 * The rank this pick sits under in the family's own `elder` container.
	 */
	rank: string;
	powerName: string;
}

/**
 * Every not-yet-held elder-and-above pick across families the character already holds some form of (a ladder rung or
 * another pick), reading each family's `elder` container ONLY.
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
 * Orders whatever ranks are actually present against the block's own declared `_meta.ranks` vocabulary.
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

/**
 * One rank's worth of grouped pick options, for the "Add an Elder-and-above power" picker.
 */
export interface PickRankGroup {
	rank: string;
	options: PickOption[];
}

/**
 * `pickOptionsFor()`'s results, sectioned by rank in `_meta.ranks` order.
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
 * The rank a held pick sits under, for grouping the "held" list the same way the "add" picker is grouped, or an empty
 * string when neither the catalog nor the row knows it.
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
	return displayableTier( row.tier ) ?? '';
}

/**
 * Capitalizes a rank word ("elder" -> "Elder") for a section heading, or names the group of picks whose rank is not
 * known.
 */
function rankHeading( rank: string ): string {
	return rank.length === 0
		? __( 'Other', 'beyond-elysium' )
		: rank[ 0 ].toUpperCase() + rank.slice( 1 );
}

/**
 * A safe display label for the reorder-mode drag list.
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
 * Renders the held-power list for a tiered_power block.
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

	// A single per-sheet, player-persisted preference.
	const [ showChecklist, setShowChecklist ] = usePowerDisplayMode();

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

	// --- Reorder mode (player_order blocks only) -------------
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

	// Rank-grouped, `elder` container only.
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
	 * The one Elder-and-above picker's own input.
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

	/**
	 * Writes a new, already-safe level onto one row.
	 */
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

	// The two controls, split at the data level.
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
