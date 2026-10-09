/**
 * Small attribution line shown beneath every front-end Beyond Elysium widget: "Powered by BeyondElysium".
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { CreditsResponse } from '../../api/client';
import Modal from './Modal';
import AiAssistButton from './AiAssistButton';
import HelpButton from './HelpButton';
import './PoweredByFooter.css';

/**
 * Renders the "Powered by BeyondElysium" line and owns the open/closed state of its Credits modal.
 */
export function PoweredByFooter() {
	const [ open, setOpen ] = useState( false );

	return (
		<div className="be-powered-by">
			{ __( 'Powered by', 'beyond-elysium' ) }{ ' ' }
			{ /* eslint-disable-next-line jsx-a11y/anchor-is-valid */ }
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
 * The Credits modal itself: loads the current credits text and in-memoriam list, shows them as plain read-only
 * content for every viewer, and additionally shows edit controls for the credits text for a viewer who holds
 * be_manage_games. The in-memoriam list has no edit path; the owner maintains it outside the plugin.
 */
function CreditsModal( { onClose }: { onClose: () => void } ) {
	const [ data, setData ] = useState< CreditsResponse | null >( null );
	const [ editing, setEditing ] = useState( false );
	const [ draftText, setDraftText ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const canManage =
		window.beyondElysium?.capabilities?.be_manage_games ?? false;

	useEffect( () => {
		api.credits
			.get()
			.then( ( result ) => {
				setData( result );
				setDraftText( result.credits_text );
			} )
			.catch( () =>
				setError( __( 'Failed to load credits.', 'beyond-elysium' ) )
			);
	}, [] );

	async function save() {
		setSaving( true );
		setError( null );
		try {
			const result = await api.credits.update( {
				credits_text: draftText,
			} );
			setData( result );
			setEditing( false );
		} catch {
			setError( __( 'Failed to save changes.', 'beyond-elysium' ) );
		} finally {
			setSaving( false );
		}
	}

	return (
		<Modal title={ __( 'Credits', 'beyond-elysium' ) } onClose={ onClose }>
			<div className="be-help-heading">
				<HelpButton helpKey="credits" />
			</div>
			<p>
				<a
					href="https://beyondelysium.com"
					target="_blank"
					rel="noopener noreferrer"
				>
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
						<textarea
							value={ draftText }
							onChange={ ( e ) => setDraftText( e.target.value ) }
						/>
						<AiAssistButton
							capability="be_manage_games"
							fieldContext="credits_text"
							currentValue={ draftText }
							onAccept={ setDraftText }
						/>
					</label>

					<div className="be-powered-by__edit-actions">
						<button
							type="button"
							disabled={ saving }
							onClick={ save }
						>
							{ saving
								? __( 'Saving…', 'beyond-elysium' )
								: __( 'Save', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							disabled={ saving }
							onClick={ () => setEditing( false ) }
						>
							{ __( 'Cancel', 'beyond-elysium' ) }
						</button>
					</div>
				</div>
			) : (
				<>
					<p>{ data.credits_text }</p>

					{ canManage && (
						<button
							type="button"
							onClick={ () => setEditing( true ) }
						>
							{ __( 'Edit', 'beyond-elysium' ) }
						</button>
					) }
				</>
			) }

			{ data && (
				<>
					<h4>{ __( 'In Memoriam', 'beyond-elysium' ) }</h4>
					<ul className="be-powered-by__memoriam-list">
						{ data.in_memoriam.map( ( entry, i ) => (
							<li key={ i }>
								{ entry.name }
								{ entry.note && (
									<span className="be-powered-by__memoriam-note">
										{ ' ' }
										— { entry.note }
									</span>
								) }
							</li>
						) ) }
					</ul>
				</>
			) }
		</Modal>
	);
}

export default PoweredByFooter;
