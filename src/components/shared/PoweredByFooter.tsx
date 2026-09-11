/**
 * Small attribution line shown beneath every front-end Beyond Elysium
 * widget: "Powered by BeyondElysium". Clicking the BeyondElysium name
 * opens a modal with a link to beyondelysium.com, the plugin's credits
 * text, and its in-memoriam list - the latter two editable by a site
 * administrator through this same modal.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { CreditsResponse, InMemoriamEntry } from '../../api/client';
import Modal from './Modal';
import './PoweredByFooter.css';

/**
 * Renders the "Powered by BeyondElysium" line and owns the open/closed
 * state of its Credits modal. Fetches credits content only once the
 * modal is actually opened, not on every page load.
 */
export function PoweredByFooter() {
	const [ open, setOpen ] = useState( false );

	return (
		<div className="be-powered-by">
			{ __( 'Powered by ', 'beyond-elysium' ) }
			<a
				href="#"
				className="be-powered-by__link"
				onClick={ ( e ) => {
					e.preventDefault();
					setOpen( true );
				} }
			>
				{ __( 'BeyondElysium', 'beyond-elysium' ) }
			</a>
			{ open && <CreditsModal onClose={ () => setOpen( false ) } /> }
		</div>
	);
}

/**
 * The Credits modal itself: loads the current credits text and
 * in-memoriam list, shows them as plain read-only content for every
 * viewer, and additionally shows edit controls for a viewer who holds
 * be_manage_games.
 */
function CreditsModal( { onClose }: { onClose: () => void } ) {
	const [ data, setData ] = useState<CreditsResponse | null>( null );
	const [ editing, setEditing ] = useState( false );
	const [ draftText, setDraftText ] = useState( '' );
	const [ draftList, setDraftList ] = useState<InMemoriamEntry[]>( [] );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );

	const canManage = window.beyondElysium?.capabilities?.be_manage_games ?? false;

	useEffect( () => {
		api.credits
			.get()
			.then( ( result ) => {
				setData( result );
				setDraftText( result.credits_text );
				setDraftList( result.in_memoriam );
			} )
			.catch( () => setError( __( 'Failed to load credits.', 'beyond-elysium' ) ) );
	}, [] );

	async function save() {
		setSaving( true );
		setError( null );
		try {
			const result = await api.credits.update( { credits_text: draftText, in_memoriam: draftList } );
			setData( result );
			setEditing( false );
		} catch {
			setError( __( 'Failed to save changes.', 'beyond-elysium' ) );
		} finally {
			setSaving( false );
		}
	}

	function updateEntry( index: number, patch: Partial<InMemoriamEntry> ) {
		setDraftList( ( list ) => list.map( ( entry, i ) => ( i === index ? { ...entry, ...patch } : entry ) ) );
	}

	function removeEntry( index: number ) {
		setDraftList( ( list ) => list.filter( ( _, i ) => i !== index ) );
	}

	function addEntry() {
		setDraftList( ( list ) => [ ...list, { name: '', note: '' } ] );
	}

	return (
		<Modal title={ __( 'Credits', 'beyond-elysium' ) } onClose={ onClose }>
			<p>
				<a href="https://beyondelysium.com" target="_blank" rel="noopener noreferrer">
					beyondelysium.com
				</a>
			</p>

			{ error && (
				<p className="be-powered-by__error" role="alert">
					{ error }
				</p>
			) }

			{ ! data ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : editing ? (
				<div className="be-powered-by__edit">
					<label>
						{ __( 'Credits text', 'beyond-elysium' ) }
						<textarea value={ draftText } onChange={ ( e ) => setDraftText( e.target.value ) } />
					</label>

					<h4>{ __( 'In Memoriam', 'beyond-elysium' ) }</h4>
					{ draftList.map( ( entry, i ) => (
						<div className="be-powered-by__memoriam-row" key={ i }>
							<input
								type="text"
								aria-label={ __( 'Name', 'beyond-elysium' ) }
								placeholder={ __( 'Name', 'beyond-elysium' ) }
								value={ entry.name }
								onChange={ ( e ) => updateEntry( i, { name: e.target.value } ) }
							/>
							<input
								type="text"
								aria-label={ __( 'Note (optional)', 'beyond-elysium' ) }
								placeholder={ __( 'Note (optional)', 'beyond-elysium' ) }
								value={ entry.note ?? '' }
								onChange={ ( e ) => updateEntry( i, { note: e.target.value } ) }
							/>
							<button type="button" onClick={ () => removeEntry( i ) }>
								{ __( 'Remove', 'beyond-elysium' ) }
							</button>
						</div>
					) ) }
					<button type="button" onClick={ addEntry }>
						{ __( '+ Add name', 'beyond-elysium' ) }
					</button>

					<div className="be-powered-by__edit-actions">
						<button type="button" disabled={ saving } onClick={ save }>
							{ saving ? __( 'Saving…', 'beyond-elysium' ) : __( 'Save', 'beyond-elysium' ) }
						</button>
						<button type="button" disabled={ saving } onClick={ () => setEditing( false ) }>
							{ __( 'Cancel', 'beyond-elysium' ) }
						</button>
					</div>
				</div>
			) : (
				<>
					<p>{ data.credits_text }</p>

					<h4>{ __( 'In Memoriam', 'beyond-elysium' ) }</h4>
					<ul className="be-powered-by__memoriam-list">
						{ data.in_memoriam.map( ( entry, i ) => (
							<li key={ i }>
								{ entry.name }
								{ entry.note && <span className="be-powered-by__memoriam-note"> — { entry.note }</span> }
							</li>
						) ) }
					</ul>

					{ canManage && (
						<button type="button" onClick={ () => setEditing( true ) }>
							{ __( 'Edit', 'beyond-elysium' ) }
						</button>
					) }
				</>
			) }
		</Modal>
	);
}

export default PoweredByFooter;
