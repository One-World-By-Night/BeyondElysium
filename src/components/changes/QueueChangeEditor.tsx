/**
 * The pop-up a Storyteller edits a waiting change in: its fields, the section it is filed in, its price and a note to
 * the player. Saving leaves the change pending.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { describeChange } from '../../lib/describeChange';
import { errorMessage } from '../../lib/errorMessage';
import { MAX_CUSTOM_PRICE, priceUnitLabel } from '../../lib/queuePrice';
import {
	SECTION_FIELD,
	buildEditRequest,
	draftFor,
	inputFields,
	isMove,
	priceBoxKind,
	priceTextValid,
	usualPriceHint,
} from '../../lib/queueEdit';
import type { EditDraft } from '../../lib/queueEdit';
import type { QueueChange, SectionChoice } from '../../types/character';
import Modal from '../shared/Modal';
import PriceTotal from './PriceTotal';
import './QueueChangeEditor.css';

export interface QueueChangeEditorProps {
	gameSlug: string;
	change: QueueChange;
	onClose: () => void;
	/**
	 * Called with the change as the queue now shows it.
	 */
	onSaved: ( edited: QueueChange ) => void;
	/**
	 * Called when the change moved on while the editor was open, so the queue has to be read again.
	 */
	onStale: ( message: string ) => void;
}

/**
 * Error codes that mean what the editor showed is out of date.
 */
const STALE_CODES = [
	'change_changed',
	'change_not_pending',
	'price_unit_changed',
];

/**
 * Renders the editor for one waiting change inside a pop-up.
 */
export function QueueChangeEditor( {
	gameSlug,
	change,
	onClose,
	onSaved,
	onStale,
}: QueueChangeEditorProps ) {
	const [ draft, setDraft ] = useState< EditDraft >( () =>
		draftFor( change )
	);
	const [ sections, setSections ] = useState< SectionChoice[] >( [] );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const fields = inputFields( change );
	const basis = change.price_basis ?? null;
	const priceKind = priceBoxKind( basis );
	const moving = isMove( change, draft );

	useEffect( () => {
		if ( ! ( change.editable_fields ?? [] ).includes( SECTION_FIELD ) ) {
			return;
		}
		api.changes( gameSlug )
			.sections( change.id )
			.then( setSections )
			.catch( () => setSections( [] ) );
	}, [ gameSlug, change.id, change.editable_fields ] );

	function setField( key: string, value: string ) {
		setDraft( ( prev ) => ( {
			...prev,
			fields: { ...prev.fields, [ key ]: value },
		} ) );
	}

	async function save() {
		setSaving( true );
		setError( null );
		try {
			const edited = await api
				.changes( gameSlug )
				.edit( change.id, buildEditRequest( change, draft ) );
			onSaved( edited );
		} catch ( err: unknown ) {
			const code = ( err as { code?: string } ).code ?? '';
			const message = errorMessage(
				err,
				__( 'Failed to save the change.', 'beyond-elysium' )
			);
			if ( STALE_CODES.includes( code ) ) {
				onStale( message );
				return;
			}
			setError( message );
			setSaving( false );
		}
	}

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: a plain description of the change, such as "Added Wax Ward" */
				__( 'Edit: %s', 'beyond-elysium' ),
				describeChange( change.change_type, change.change_data )
			) }
			onClose={ onClose }
			footer={
				<div className="be-queue-editor__buttons">
					<button
						type="button"
						onClick={ onClose }
						disabled={ saving }
					>
						{ __( 'Cancel', 'beyond-elysium' ) }
					</button>
					<button
						type="button"
						className="button-primary"
						onClick={ save }
						disabled={ saving || ! priceTextValid( draft.price ) }
					>
						{ saving
							? __( 'Saving…', 'beyond-elysium' )
							: __( 'Save', 'beyond-elysium' ) }
					</button>
				</div>
			}
		>
			<div className="be-queue-editor">
				<p className="be-queue-editor__where">
					{ change.character_name ?? `#${ change.character_id }` }
					{ change.block_name &&
						` · ${ sprintf(
							/* translators: %s: the name of a sheet section, such as Rituals */
							__( 'In %s', 'beyond-elysium' ),
							change.block_name
						) }` }
				</p>

				{ sections.length > 1 && (
					<label className="be-queue-editor__field">
						<span>{ __( 'Section', 'beyond-elysium' ) }</span>
						<select
							className="be-queue-editor__input"
							value={ draft.fields[ SECTION_FIELD ] ?? '' }
							onChange={ ( e ) =>
								setField( SECTION_FIELD, e.target.value )
							}
						>
							{ sections.map( ( section ) => (
								<option
									key={ section.slug }
									value={ section.slug }
								>
									{ section.name }
								</option>
							) ) }
						</select>
					</label>
				) }

				{ fields.map( ( def ) => (
					<label key={ def.key } className="be-queue-editor__field">
						<span>{ def.label }</span>
						<input
							className="be-queue-editor__input"
							type={ def.kind === 'number' ? 'number' : 'text' }
							value={ draft.fields[ def.key ] ?? '' }
							onChange={ ( e ) =>
								setField( def.key, e.target.value )
							}
						/>
					</label>
				) ) }

				{ priceKind !== 'none' && basis && (
					<div className="be-queue-editor__field">
						<label className="be-queue-editor__price">
							<span>
								{ priceUnitLabel(
									priceKind === 'book' ? 'change' : basis.per
								) }
							</span>
							<input
								className="be-queue-editor__input be-queue-editor__price-input"
								type="number"
								min={ 0 }
								max={ MAX_CUSTOM_PRICE }
								step={ 1 }
								inputMode="numeric"
								value={ draft.price }
								disabled={ moving }
								placeholder={
									priceKind === 'book'
										? usualPriceHint( basis )
										: undefined
								}
								onChange={ ( e ) =>
									setDraft( ( prev ) => ( {
										...prev,
										price: e.target.value,
									} ) )
								}
							/>
							{ priceKind === 'homebrew' && ! moving && (
								<PriceTotal
									value={ draft.price }
									basis={ basis }
									className="be-queue-editor__hint"
								/>
							) }
						</label>
						<span className="be-queue-editor__hint">
							{ moving
								? __(
										'Save the move first, then set the price.',
										'beyond-elysium'
									)
								: priceKind === 'book'
									? __(
											'Leave empty to charge the usual price.',
											'beyond-elysium'
										)
									: __(
											'Leave empty to keep it waiting for a price.',
											'beyond-elysium'
										) }
						</span>
					</div>
				) }

				<label className="be-queue-editor__field">
					<span>
						{ __( 'Note to the player', 'beyond-elysium' ) }
					</span>
					<textarea
						className="be-queue-editor__input"
						rows={ 3 }
						value={ draft.note }
						onChange={ ( e ) =>
							setDraft( ( prev ) => ( {
								...prev,
								note: e.target.value,
							} ) )
						}
					/>
					<span className="be-queue-editor__hint">
						{ __(
							'The player sees this in their change history. Text between [ST] and [/ST] stays with Storytellers.',
							'beyond-elysium'
						) }
					</span>
				</label>

				{ error && (
					<div className="be-queue-editor__error" role="alert">
						{ error }
					</div>
				) }
			</div>
		</Modal>
	);
}

export default QueueChangeEditor;
