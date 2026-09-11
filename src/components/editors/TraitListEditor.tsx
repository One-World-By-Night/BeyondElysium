/**
 * TraitListEditor renders the editable list for a trait_list block - flat or
 * grouped catalogs such as Abilities, Backgrounds, or Merits/Flaws. Traits are
 * added and edited through a modal; each row shows a read-only summary with an
 * edit control, rather than permanently visible input fields.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import SearchableSelect from '../shared/SearchableSelect';
import Modal from '../shared/Modal';
import { groupTraitsByField } from '../../lib/groupTraitsByField';
import type { TraitListDefinition } from '../../types';
import './TraitListEditor.css';

/** The editor's working-copy shape for one row; count maps to Trait.total once submitted. */
export interface EditableTrait {
	name: string;
	count?: number;
	specialization?: string;
	note?: string;
	/** Set when this row was entered as free text rather than chosen from the catalog. */
	custom?: boolean;
	/** The player's choice for a variable-cost item, e.g. "1 or 3" or "1-7"; ignored for a fixed cost. */
	chosen_cost?: number;
	/** Marked for removal rather than deleted outright; removal is applied when changes are submitted. */
	_removed?: boolean;
}

export interface TraitListEditorProps {
	blockSlug: string;
	data: EditableTrait[];
	definition: TraitListDefinition;
	onChange: ( blockSlug: string, nextData: EditableTrait[] ) => void;
	readOnly?: boolean;
}

/** `index === null` means the modal is adding a new trait, not editing an existing row. */
interface DraftState {
	index: number | null;
	name: string;
	isCustom: boolean;
	count: number;
	specialization: string;
	note: string;
}

const EMPTY_DRAFT: Omit<DraftState, 'index'> = { name: '', isCustom: false, count: 1, specialization: '', note: '' };

/**
 * Renders the trait list for a trait_list block: each held row shows as a compact
 * read-only summary (name plus a dot/count) with an edit button, and a "+ Add"
 * button opens the same modal blank for a new trait. Grouped blocks render their
 * items under group/subgroup headings; flat blocks render a single list.
 */
export function TraitListEditor( { blockSlug, data, definition, onChange, readOnly }: TraitListEditorProps ) {
	// Memoized: large catalogs make this expensive to recompute on every keystroke.
	const itemNames = useMemo( () => definition.items.map( ( item ) => item.name ), [ definition.items ] );
	const [ draft, setDraft ] = useState<DraftState | null>( null );

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
		} );
	};

	const closeDraft = () => setDraft( null );

	const saveDraft = () => {
		if ( ! draft || ! draft.name ) {
			return;
		}

		if ( draft.index === null ) {
			// A non-atomic list increments an already-held trait's count instead of duplicating the row.
			if ( ! definition.atomic ) {
				// Merges only into a row matching both name and specialization, so distinct specializations stay separate rows.
				const existingIndex = data.findIndex(
					( row ) =>
						row.name === draft.name &&
						! row._removed &&
						( row.specialization ?? '' ) === ( draft.specialization ?? '' )
				);
				if ( existingIndex !== -1 ) {
					const next = [ ...data ];
					const existing = next[ existingIndex ];
					next[ existingIndex ] = { ...existing, count: ( existing.count ?? 1 ) + draft.count };
					emit( next );
					closeDraft();
					return;
				}
			}

			emit( [
				...data,
				{
					name: draft.name,
					count: draft.count,
					custom: draft.isCustom,
					...( definition.has_specializations && draft.specialization ? { specialization: draft.specialization } : {} ),
					...( draft.note ? { note: draft.note } : {} ),
				},
			] );
		} else {
			// Same name+specialization uniqueness rule as adding: merges into the matching row instead of duplicating it.
			const newSpecialization = draft.specialization || undefined;
			const collisionIndex = ! definition.atomic
				? data.findIndex(
						( row, i ) =>
							i !== draft.index &&
							row.name === draft.name &&
							! row._removed &&
							( row.specialization ?? '' ) === ( newSpecialization ?? '' )
				  )
				: -1;

			const next = [ ...data ];
			if ( collisionIndex !== -1 ) {
				const target = next[ collisionIndex ];
				next[ collisionIndex ] = { ...target, count: ( target.count ?? 1 ) + draft.count };
				next.splice( draft.index, 1 );
			} else {
				next[ draft.index ] = {
					...next[ draft.index ],
					count: draft.count,
					specialization: newSpecialization,
					note: draft.note || undefined,
				};
			}
			emit( next );
		}

		closeDraft();
	};

	const toggleRemovedAndClose = () => {
		if ( ! draft || draft.index === null ) {
			return;
		}
		const next = [ ...data ];
		next[ draft.index ] = { ...next[ draft.index ], _removed: ! next[ draft.index ]._removed };
		emit( next );
		closeDraft();
	};

	const editingRow = draft && draft.index !== null ? data[ draft.index ] : null;
	const editingRemoved = !! editingRow?._removed;

	// Each row carries its original flat-array index through grouping, so edits still address the right row in `data`.
	const indexedRows = useMemo( () => data.map( ( row, index ) => ( { ...row, index } ) ), [ data ] );
	const grouped = useMemo( () => groupTraitsByField( indexedRows, definition ), [ indexedRows, definition ] );

	const renderRow = ( row: EditableTrait & { index: number } ) => (
		<li
			key={ `${ row.name }-${ row.index }` }
			className={ 'be-trait-list-editor__row' + ( row._removed ? ' be-trait-list-editor__row--removed' : '' ) }
		>
			<span className="be-trait-list-editor__summary">
				<span className="be-trait-list-editor__summary-name">{ row.name }</span>
				{ typeof row.count === 'number' && row.count > 0 && (
					<span className="be-trait-list-editor__dots">{ '•'.repeat( row.count ) }</span>
				) }
				{ row.specialization && (
					<span className="be-trait-list-editor__summary-detail">({ row.specialization })</span>
				) }
				{ row.note && <span className="be-trait-list-editor__summary-detail">{ row.note }</span> }
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
			{ grouped ? (
				grouped.map( ( { group, subgroups } ) => (
					<div className="be-trait-list-editor__group" key={ group }>
						<h4 className="be-trait-list-editor__category">{ group }</h4>
						{ subgroups.map( ( { subgroup, items } ) => (
							<div className="be-trait-list-editor__subgroup" key={ subgroup ?? '' }>
								{ subgroup && <h5 className="be-trait-list-editor__subcategory">{ subgroup }</h5> }
								<ul className="be-trait-list-editor__rows">{ items.map( renderRow ) }</ul>
							</div>
						) ) }
					</div>
				) )
			) : (
				<ul className="be-trait-list-editor__rows">{ indexedRows.map( renderRow ) }</ul>
			) }

			{ ! readOnly && (
				<button type="button" className="be-trait-list-editor__add-trigger" onClick={ openAdd }>
					{ __( '+ Add', 'beyond-elysium' ) }
				</button>
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
								<button type="button" className="be-trait-list-editor__modal-remove" onClick={ toggleRemovedAndClose }>
									{ editingRemoved ? __( 'Undo removal', 'beyond-elysium' ) : __( 'Remove', 'beyond-elysium' ) }
								</button>
							) }
							<button type="button" onClick={ closeDraft }>
								{ __( 'Cancel', 'beyond-elysium' ) }
							</button>
							{ ! editingRemoved && (
								<button type="button" disabled={ ! draft.name } onClick={ saveDraft }>
									{ __( 'Save', 'beyond-elysium' ) }
								</button>
							) }
						</>
					}
				>
					{ editingRemoved ? (
						<p>{ __( 'This trait is marked for removal. Undo to keep editing it, or leave it removed.', 'beyond-elysium' ) }</p>
					) : (
						<>
							{ draft.index === null && (
								<div className="be-trait-list-editor__modal-field">
									<label htmlFor={ `${ blockSlug }-trait-name` }>{ __( 'Name', 'beyond-elysium' ) }</label>
									<SearchableSelect
										id={ `${ blockSlug }-trait-name` }
										options={ itemNames }
										value={ draft.name }
										allowCustom={ definition.allow_custom ?? false }
										placeholder={ __( 'Choose or type a name…', 'beyond-elysium' ) }
										ariaLabel={ __( 'Name', 'beyond-elysium' ) }
										onChange={ ( name, isCustom ) => setDraft( { ...draft, name, isCustom } ) }
									/>
								</div>
							) }

							<div className="be-trait-list-editor__modal-field">
								<label htmlFor={ `${ blockSlug }-trait-count` }>{ __( 'Count / Level', 'beyond-elysium' ) }</label>
								<input
									id={ `${ blockSlug }-trait-count` }
									type="number"
									min={ 1 }
									value={ draft.count }
									onChange={ ( e ) => setDraft( { ...draft, count: Math.max( 1, Number( e.target.value ) ) } ) }
								/>
							</div>

							{ definition.has_specializations && (
								<div className="be-trait-list-editor__modal-field">
									<label htmlFor={ `${ blockSlug }-trait-specialization` }>{ __( 'Specialization', 'beyond-elysium' ) }</label>
									<input
										id={ `${ blockSlug }-trait-specialization` }
										type="text"
										value={ draft.specialization }
										onChange={ ( e ) => setDraft( { ...draft, specialization: e.target.value } ) }
									/>
								</div>
							) }

							<div className="be-trait-list-editor__modal-field">
								<label htmlFor={ `${ blockSlug }-trait-note` }>{ __( 'Note', 'beyond-elysium' ) }</label>
								<input
									id={ `${ blockSlug }-trait-note` }
									type="text"
									value={ draft.note }
									onChange={ ( e ) => setDraft( { ...draft, note: e.target.value } ) }
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
