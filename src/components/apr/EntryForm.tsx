/**
 * Form for adding a new entry to a plot's timeline. Renders an entry-type selector (when
 * more than one type is available), a content textarea, an optional event-date field, and
 * a submit button that posts the entry through the API. A non-manager viewer only ever
 * sees the action entry type; a manager sees response, note, and resolution too.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { EntryType } from '../../types/plot';
import './EntryForm.css';

export interface EntryFormProps {
	gameSlug: string;
	plotId: number;
	/** Whether the viewer holds be_manage_plots - controls which entry types are offered. */
	canManage: boolean;
	onCreated: () => void;
	/** Shows an optional Timeline date field when true. */
	expandedEnabled?: boolean;
}

/**
 * Renders a form for posting a new entry to a plot. The set of entry types offered depends
 * on the viewer's permissions - a player sees only "action," while a manager sees the full
 * set of entry types. Submits the entry through the API and clears the form on success.
 */
export function EntryForm( { gameSlug, plotId, canManage, onCreated, expandedEnabled }: EntryFormProps ) {
	const availableTypes: EntryType[] = canManage
		? [ 'response', 'note', 'resolution', 'action' ]
		: [ 'action' ];

	const [ entryType, setEntryType ] = useState<EntryType>( availableTypes[ 0 ] );
	const [ content, setContent ] = useState( '' );
	const [ eventDate, setEventDate ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );

	/**
	 * Submits the entry form. Sends the trimmed content, selected entry type, and optional
	 * event date to the API, then clears the content and date fields and notifies the
	 * parent via onCreated.
	 */
	async function submit( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! content.trim() ) {
			return;
		}
		setSubmitting( true );
		setError( null );
		try {
			await api.plotEntries( gameSlug ).create( plotId, {
				entry_type: entryType,
				content: content.trim(),
				event_date: eventDate || undefined,
			} );
			setContent( '' );
			setEventDate( '' );
			onCreated();
		} catch {
			setError( __( 'Failed to add this entry.', 'beyond-elysium' ) );
		} finally {
			setSubmitting( false );
		}
	}

	return (
		<form className="be-entry-form" onSubmit={ submit }>
			{ error && (
				<div className="be-entry-form__error" role="alert">
					{ error }
				</div>
			) }
			<div className="be-entry-form__row">
				{ availableTypes.length > 1 && (
					<select value={ entryType } onChange={ ( e ) => setEntryType( e.target.value as EntryType ) }>
						{ availableTypes.map( ( t ) => (
							<option key={ t } value={ t }>
								{ t }
							</option>
						) ) }
					</select>
				) }
				<textarea
					value={ content }
					onChange={ ( e ) => setContent( e.target.value ) }
					placeholder={
						entryType === 'action'
							? __( 'Describe your action…', 'beyond-elysium' )
							: sprintf( __( 'Write a %s…', 'beyond-elysium' ), entryType )
					}
					rows={ 3 }
				/>
				{ expandedEnabled && (
					<input
						type="date"
						value={ eventDate }
						onChange={ ( e ) => setEventDate( e.target.value ) }
						aria-label={ __( 'Timeline date (optional)', 'beyond-elysium' ) }
					/>
				) }
			</div>
			<button type="submit" className="be-st-button" disabled={ submitting || ! content.trim() }>
				{ submitting ? __( 'Posting…', 'beyond-elysium' ) : __( 'Post', 'beyond-elysium' ) }
			</button>
		</form>
	);
}

export default EntryForm;
