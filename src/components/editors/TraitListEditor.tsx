/**
 * TraitListEditor renders the editable list for a trait_list block.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import SearchableSelect from '../shared/SearchableSelect';
import Modal from '../shared/Modal';
import WithDots from '../shared/Dots';
import { groupTraitsByField } from '../../lib/groupTraitsByField';
import { groupCatalogItems } from '../../lib/catalogGroups';
import { identityGroupValues } from '../../lib/identityGroups';
import { costChoices } from '../../lib/costChoices';
import { DOT } from '../../lib/displayTemper';
import { useCostVisibility } from '../../lib/costDisplayMode';
import {
	moveUp,
	moveDown,
	moveTo,
	reorderErrorMessage,
} from '../../lib/reorderArray';
import { traitRowIdentity, labelPrompt } from '../../lib/traitIdentity';
import api from '../../api/client';
import type { TraitListDefinition } from '../../types';
import './TraitListEditor.css';

/**
 * The editor's working-copy shape for one row.
 */
export interface EditableTrait {
	name: string;
	count?: number;
	specialization?: string;
	note?: string;
	/**
	 * Set when this row was entered as free text.
	 */
	custom?: boolean;
	/**
	 * The player's choice for a variable-cost item, e.g. "1 or 3" or "1-7".
	 */
	chosen_cost?: number;
	/**
	 * Marked for removal rather than deleted outright.
	 */
	_removed?: boolean;
}

export interface TraitListEditorProps {
	blockSlug: string;
	data: EditableTrait[];
	definition: TraitListDefinition;
	onChange: ( blockSlug: string, nextData: EditableTrait[] ) => void;
	readOnly?: boolean;
	/**
	 * Needed only for a player_order block's "Save order" call.
	 */
	gameSlug?: string;
	characterId?: number;
	/**
	 * The whole sheet, read only to find this character's own identity values so their matching pick-list sections sort
	 * first.
	 */
	sheetData?: Record< string, unknown >;
}

/**
 * `index === null` means the modal is adding a new trait.
 */
interface DraftState {
	index: number | null;
	name: string;
	isCustom: boolean;
	count: number;
	specialization: string;
	note: string;
	/**
	 * A variable-cost item's chosen cost.
	 */
	chosenCost?: number;
}

const EMPTY_DRAFT: Omit< DraftState, 'index' > = {
	name: '',
	isCustom: false,
	count: 1,
	specialization: '',
	note: '',
};

/**
 * The index of the held row a drafted trait belongs.
 */
export function findTraitRowIndex(
	rows: EditableTrait[],
	definition: TraitListDefinition,
	row: { name: string; specialization?: string },
	excludeIndex: number | null = null
): number {
	if ( definition.atomic ) {
		return -1;
	}
	const wanted = traitRowIdentity( definition, row );
	return rows.findIndex(
		( candidate, index ) =>
			index !== excludeIndex &&
			! candidate._removed &&
			traitRowIdentity( definition, candidate ) === wanted
	);
}

/**
 * The label a merged row keeps: its own, when it has one.
 */
export function mergedSpecialization(
	existing?: string,
	incoming?: string
): string | undefined {
	return existing && existing !== '' ? existing : incoming || undefined;
}

/**
 * One drafted trait as it will be stored, independent of the modal's own state shape.
 */
export interface TraitDraft {
	name: string;
	count: number;
	specialization?: string;
	note?: string;
	custom?: boolean;
	chosen_cost?: number;
}

/**
 * Applies one drafted trait to the held rows and returns the next list.
 */
export function saveTraitDraft(
	rows: EditableTrait[],
	definition: TraitListDefinition,
	draft: TraitDraft,
	index: number | null = null
): EditableTrait[] {
	const label = draft.specialization || undefined;
	const identity = { name: draft.name, specialization: label };
	const chosen =
		draft.chosen_cost !== undefined
			? { chosen_cost: draft.chosen_cost }
			: {};

	const unchanged =
		index !== null &&
		traitRowIdentity( definition, rows[ index ] ) ===
			traitRowIdentity( definition, identity );
	const mergeIndex = unchanged
		? -1
		: findTraitRowIndex( rows, definition, identity, index );

	if ( mergeIndex !== -1 ) {
		const next = [ ...rows ];
		const target = next[ mergeIndex ];
		const kept = mergedSpecialization( target.specialization, label );
		// A merge is a count bump.
		const keptNote = mergedSpecialization( target.note, draft.note );
		next[ mergeIndex ] = {
			...target,
			count: ( target.count ?? 1 ) + draft.count,
			...( kept ? { specialization: kept } : {} ),
			...( keptNote ? { note: keptNote } : {} ),
			// A chosen cost follows a new purchase, never a row being edited - unchanged here.
			...( index === null ? chosen : {} ),
		};
		if ( index !== null ) {
			next.splice( index, 1 );
		}
		return next;
	}

	if ( index === null ) {
		return [
			...rows,
			{
				name: draft.name,
				count: draft.count,
				custom: !! draft.custom,
				...( label ? { specialization: label } : {} ),
				...( draft.note ? { note: draft.note } : {} ),
				...chosen,
			},
		];
	}

	const next = [ ...rows ];
	next[ index ] = {
		...next[ index ],
		count: draft.count,
		specialization: label,
		note: draft.note || undefined,
		...chosen,
	};
	return next;
}

/**
 * Renders the trait list for a trait_list block.
 */
export function TraitListEditor( {
	blockSlug,
	data,
	definition,
	onChange,
	readOnly,
	gameSlug,
	characterId,
	sheetData,
}: TraitListEditorProps ) {
	// Memoized: large catalogs make this expensive to recompute on every keystroke.
	const itemNames = useMemo(
		() => definition.items.map( ( item ) => item.name ),
		[ definition.items ]
	);

	const preferredGroups = useMemo(
		() => identityGroupValues( sheetData ),
		[ sheetData ]
	);
	const itemGroups = useMemo(
		() => groupCatalogItems( definition.items, preferredGroups ),
		[ definition.items, preferredGroups ]
	);
	const [ draft, setDraft ] = useState< DraftState | null >( null );
	const [ showCost, setShowCost ] = useCostVisibility();

	const emit = ( next: EditableTrait[] ) => onChange( blockSlug, next );

	const openAdd = () => setDraft( { index: null, ...EMPTY_DRAFT } );

	const openEdit = ( index: number ) => {
		const row = data[ index ];
		setDraft( {
			index,
			name: row.name,
			isCustom: !! row.custom,
			count: row.count ?? 1,
			specialization: row.specialization ?? '',
			note: row.note ?? '',
			chosenCost: row.chosen_cost,
		} );
	};

	const closeDraft = () => setDraft( null );

	// The costs the drafted catalog item may be bought at, when it lets the player choose.
	const draftChoices = draft
		? costChoices(
				definition.items.find( ( item ) => item.name === draft.name )
					?.cost
		  )
		: null;
	const chosen =
		draftChoices && draft?.chosenCost !== undefined
			? { chosen_cost: draft.chosenCost }
			: {};

	// Which label the modal asks for, if any.
	const draftLabelPrompt = draft
		? labelPrompt( definition, draft.name )
		: null;
	const draftLabelAlreadyHeld =
		draft && draftLabelPrompt === 'who_or_what'
			? findTraitRowIndex(
					data,
					definition,
					{ name: draft.name, specialization: draft.specialization },
					draft.index
			  ) !== -1
			: false;

	const saveDraft = () => {
		if ( ! draft || ! draft.name ) {
			return;
		}

		emit(
			saveTraitDraft(
				data,
				definition,
				{
					name: draft.name,
					count: draft.count,
					specialization: draft.specialization,
					note: draft.note,
					custom: draft.isCustom,
					...chosen,
				},
				draft.index
			)
		);
		closeDraft();
	};

	const toggleRemovedAndClose = () => {
		if ( ! draft || draft.index === null ) {
			return;
		}
		const next = [ ...data ];
		next[ draft.index ] = {
			...next[ draft.index ],
			_removed: ! next[ draft.index ]._removed,
		};
		emit( next );
		closeDraft();
	};

	const editingRow =
		draft && draft.index !== null ? data[ draft.index ] : null;
	const editingRemoved = !! editingRow?._removed;

	// Each row carries its original flat-array index through grouping.
	const indexedRows = useMemo(
		() => data.map( ( row, index ) => ( { ...row, index } ) ),
		[ data ]
	);
	// A player_order block never groups.
	const grouped = useMemo(
		() =>
			definition.player_order
				? null
				: groupTraitsByField( indexedRows, definition ),
		[ indexedRows, definition ]
	);

	// --- Reorder mode (player_order blocks only) ---
	const visibleRows = indexedRows.filter( ( row ) => ! row._removed );
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

	const renderRow = ( row: EditableTrait & { index: number } ) => (
		<li
			key={ `${ row.name }-${ row.index }` }
			className={
				'be-trait-list-editor__row' +
				( row._removed ? ' be-trait-list-editor__row--removed' : '' )
			}
		>
			<span className="be-trait-list-editor__summary">
				<span className="be-trait-list-editor__summary-name">
					{ row.name }
				</span>
				{ typeof row.count === 'number' &&
					row.count > 0 &&
					// A count_is_cost row's number is a price.
					( definition.count_is_cost ? (
						showCost && (
							<span className="be-trait-list-editor__summary-detail">
								{ sprintf(
									/* translators: %d: XP cost */
									__( '(%d XP)', 'beyond-elysium' ),
									row.count
								) }
							</span>
						)
					) : (
						<span className="be-trait-list-editor__dots">
							<WithDots text={ DOT.repeat( row.count ) } />
						</span>
					) ) }
				{ row.specialization && (
					<span className="be-trait-list-editor__summary-detail">
						({ row.specialization })
					</span>
				) }
				{ row.note && (
					<span className="be-trait-list-editor__summary-detail">
						{ row.note }
					</span>
				) }
				{ row.chosen_cost !== undefined && (
					<span className="be-trait-list-editor__summary-detail">
						{ sprintf(
							/* translators: %d: the cost chosen for a variable-cost item */
							__( 'cost %d', 'beyond-elysium' ),
							row.chosen_cost
						) }
					</span>
				) }
			</span>

			{ ! readOnly && (
				<button
					type="button"
					className="be-trait-list-editor__edit"
					aria-label={ sprintf(
						/* translators: %1$s: trait or item name */
						__( 'Edit %1$s', 'beyond-elysium' ),
						row.name
					) }
					onClick={ () => openEdit( row.index ) }
				>
					{ __( '✎', 'beyond-elysium' ) }
				</button>
			) }
		</li>
	);

	return (
		<div className="be-trait-list-editor" data-block-slug={ blockSlug }>
			{ definition.count_is_cost && ! readOnly && (
				<button
					type="button"
					className="be-trait-list-editor__cost-toggle"
					onClick={ () => setShowCost( ! showCost ) }
					aria-pressed={ showCost }
					title={ __(
						'Applies to every combo on this sheet',
						'beyond-elysium'
					) }
				>
					{ showCost
						? __( 'Hide XP costs', 'beyond-elysium' )
						: __( 'Show XP costs', 'beyond-elysium' ) }
				</button>
			) }
			{ definition.player_order &&
				! readOnly &&
				gameSlug &&
				characterId !== undefined &&
				! reordering && (
					<button
						type="button"
						className="be-trait-list-editor__reorder-trigger"
						onClick={ startReorder }
					>
						{ __( 'Reorder', 'beyond-elysium' ) }
					</button>
				) }

			{ reordering ? (
				<>
					<ul className="be-trait-list-editor__rows be-trait-list-editor__rows--reorder">
						{ order.map( ( pos, uiIndex ) => {
							const row = visibleRows[ pos ];
							return (
								<li
									key={ `${ row.name }-${ row.index }` }
									className="be-trait-list-editor__row"
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
										className="be-trait-list-editor__drag-handle"
										aria-hidden="true"
									>
										⠿
									</span>
									<span className="be-trait-list-editor__summary-name">
										{ row.name }
									</span>
									<button
										type="button"
										aria-label={ sprintf(
											/* translators: %1$s: trait or item name */
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
											/* translators: %1$s: trait or item name */
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
							className="be-trait-list-editor__order-error"
							role="alert"
						>
							{ orderError }
						</p>
					) }
					<div className="be-trait-list-editor__order-actions">
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
					{ grouped ? (
						grouped.map( ( { group, subgroups } ) => (
							<div
								className="be-trait-list-editor__group"
								key={ group }
							>
								<h4 className="be-trait-list-editor__category">
									{ group }
								</h4>
								{ subgroups.map( ( { subgroup, items } ) => (
									<div
										className="be-trait-list-editor__subgroup"
										key={ subgroup ?? '' }
									>
										{ subgroup && (
											<h5 className="be-trait-list-editor__subcategory">
												{ subgroup }
											</h5>
										) }
										<ul className="be-trait-list-editor__rows">
											{ items.map( renderRow ) }
										</ul>
									</div>
								) ) }
							</div>
						) )
					) : (
						<ul className="be-trait-list-editor__rows">
							{ indexedRows.map( renderRow ) }
						</ul>
					) }

					{ ! readOnly && (
						<button
							type="button"
							className="be-trait-list-editor__add-trigger"
							onClick={ openAdd }
						>
							{ __( '+ Add', 'beyond-elysium' ) }
						</button>
					) }
				</>
			) }

			{ draft && (
				<Modal
					title={
						draft.index === null
							? __( 'Add trait', 'beyond-elysium' )
							: sprintf(
									/* translators: %1$s: trait or item name */
									__( 'Edit %1$s', 'beyond-elysium' ),
									draft.name
							  )
					}
					onClose={ closeDraft }
					footer={
						<>
							{ draft.index !== null && (
								<button
									type="button"
									className="be-trait-list-editor__modal-remove"
									onClick={ toggleRemovedAndClose }
								>
									{ editingRemoved
										? __( 'Undo removal', 'beyond-elysium' )
										: __( 'Remove', 'beyond-elysium' ) }
								</button>
							) }
							<button type="button" onClick={ closeDraft }>
								{ __( 'Cancel', 'beyond-elysium' ) }
							</button>
							{ ! editingRemoved && (
								<button
									type="button"
									disabled={ ! draft.name }
									onClick={ saveDraft }
								>
									{ __( 'Save', 'beyond-elysium' ) }
								</button>
							) }
						</>
					}
				>
					{ editingRemoved ? (
						<p>
							{ __(
								'This trait is marked for removal. Undo to keep editing it, or leave it removed.',
								'beyond-elysium'
							) }
						</p>
					) : (
						<>
							{ draft.index === null && (
								<div className="be-trait-list-editor__modal-field">
									<label
										htmlFor={ `${ blockSlug }-trait-name` }
									>
										{ __( 'Name', 'beyond-elysium' ) }
									</label>
									<SearchableSelect
										id={ `${ blockSlug }-trait-name` }
										{ ...( itemGroups.length > 0
											? { groups: itemGroups }
											: { options: itemNames } ) }
										value={ draft.name }
										allowCustom={
											definition.allow_custom ?? false
										}
										placeholder={ __(
											'Choose or type a name…',
											'beyond-elysium'
										) }
										ariaLabel={ __(
											'Name',
											'beyond-elysium'
										) }
										onChange={ ( name, isCustom ) =>
											setDraft( {
												...draft,
												name,
												isCustom,
												chosenCost: undefined,
											} )
										}
									/>
								</div>
							) }

							<div className="be-trait-list-editor__modal-field">
								<label htmlFor={ `${ blockSlug }-trait-count` }>
									{ __( 'Count / Level', 'beyond-elysium' ) }
								</label>
								<input
									id={ `${ blockSlug }-trait-count` }
									type="number"
									min={ 1 }
									value={ draft.count }
									onChange={ ( e ) =>
										setDraft( {
											...draft,
											count: Math.max(
												1,
												Number( e.target.value )
											),
										} )
									}
								/>
							</div>

							{ draftChoices && (
								<div className="be-trait-list-editor__modal-field">
									<label
										htmlFor={ `${ blockSlug }-trait-cost` }
									>
										{ __( 'Cost', 'beyond-elysium' ) }
									</label>
									<select
										id={ `${ blockSlug }-trait-cost` }
										value={
											draft.chosenCost ??
											Math.min( ...draftChoices )
										}
										onChange={ ( e ) =>
											setDraft( {
												...draft,
												chosenCost: Number(
													e.target.value
												),
											} )
										}
									>
										{ draftChoices.map( ( choice ) => (
											<option
												key={ choice }
												value={ choice }
											>
												{ choice }
											</option>
										) ) }
									</select>
								</div>
							) }

							{ draftLabelPrompt && (
								<div className="be-trait-list-editor__modal-field">
									<label
										htmlFor={ `${ blockSlug }-trait-specialization` }
									>
										{ draftLabelPrompt === 'who_or_what'
											? __(
													'Who or what?',
													'beyond-elysium'
											  )
											: __(
													'Specialization',
													'beyond-elysium'
											  ) }
									</label>
									<input
										id={ `${ blockSlug }-trait-specialization` }
										type="text"
										value={ draft.specialization }
										aria-describedby={
											draftLabelAlreadyHeld
												? `${ blockSlug }-trait-specialization-hint`
												: undefined
										}
										onChange={ ( e ) =>
											setDraft( {
												...draft,
												specialization: e.target.value,
											} )
										}
									/>
									{ draftLabelAlreadyHeld && (
										<p
											id={ `${ blockSlug }-trait-specialization-hint` }
											className="be-trait-list-editor__modal-hint"
										>
											{ __(
												'Already on this sheet. Name this one to keep it separate, or leave it the same to add to it.',
												'beyond-elysium'
											) }
										</p>
									) }
								</div>
							) }

							<div className="be-trait-list-editor__modal-field">
								<label htmlFor={ `${ blockSlug }-trait-note` }>
									{ __( 'Note', 'beyond-elysium' ) }
								</label>
								<input
									id={ `${ blockSlug }-trait-note` }
									type="text"
									value={ draft.note }
									onChange={ ( e ) =>
										setDraft( {
											...draft,
											note: e.target.value,
										} )
									}
								/>
							</div>
						</>
					) }
				</Modal>
			) }
		</div>
	);
}

export default TraitListEditor;
