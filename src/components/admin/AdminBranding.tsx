/**
 * Site-wide brand accent default and the switch for Beyond Elysium's own layout on My Chronicle and the Storyteller
 * Toolkit.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import HelpButton from '../shared/HelpButton';
import { errorMessage } from '../../lib/errorMessage';
import './Admin.css';

export function AdminBranding() {
	const [ accentColor, setAccentColor ] = useState( '' );
	const [ appLayout, setAppLayout ] = useState( true );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ message, setMessage ] = useState< string | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		api.games
			.getBranding()
			.then( ( result ) => {
				setAccentColor( result.accent_color );
				setAppLayout( result.app_layout );
			} )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				)
			)
			.finally( () => setLoading( false ) );
	}, [] );

	function save( color: string ) {
		setSaving( true );
		setError( null );
		setMessage( null );
		api.games
			.updateBranding( color )
			.then( ( result ) => {
				setAccentColor( result.accent_color );
				setMessage( __( 'Saved.', 'beyond-elysium' ) );
			} )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__(
							'That change could not be saved.',
							'beyond-elysium'
						)
					)
				)
			)
			.finally( () => setSaving( false ) );
	}

	function saveLayout( on: boolean ) {
		setSaving( true );
		setError( null );
		setMessage( null );
		api.games
			.updateAppLayout( on )
			.then( ( result ) => {
				setAppLayout( result.app_layout );
				setMessage( __( 'Saved.', 'beyond-elysium' ) );
			} )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__(
							'That change could not be saved.',
							'beyond-elysium'
						)
					)
				)
			)
			.finally( () => setSaving( false ) );
	}

	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h2>{ __( 'Branding', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="branding" />
			</div>
			<p className="description">
				{ __(
					"The site-wide default accent color for the Storyteller Toolkit's and My Chronicle's own chrome (buttons, highlights). Any chronicle can override this for itself in its own Chronicle Setup; this is only the fallback when a chronicle sets none.",
					'beyond-elysium'
				) }
			</p>
			{ error && (
				<p className="be-admin__error" role="alert">
					{ error }
				</p>
			) }
			{ message && <p role="status">{ message }</p> }
			<div className="be-admin__form-actions">
				<input
					type="color"
					aria-label={ __( 'Accent color', 'beyond-elysium' ) }
					value={ accentColor || '#8b0000' }
					disabled={ saving }
					onChange={ ( e ) => save( e.target.value ) }
				/>
				<button
					type="button"
					disabled={ saving || ! accentColor }
					onClick={ () => save( '' ) }
				>
					{ __( 'Reset to plugin default', 'beyond-elysium' ) }
				</button>
			</div>
			<p>
				<label>
					<input
						type="checkbox"
						checked={ appLayout }
						disabled={ saving }
						onChange={ ( e ) => saveLayout( e.target.checked ) }
					/>{ ' ' }
					{ __(
						"Use Beyond Elysium's own layout on My Chronicle and the Storyteller Toolkit",
						'beyond-elysium'
					) }
				</label>
			</p>
			<p className="description">
				{ __(
					"On, both pages show Beyond Elysium's own header with the site's name, the page's sections and its own footer, in place of the theme's. Off, they sit inside the theme as before. A page rebuilt with Elementor or given another template keeps its own layout either way.",
					'beyond-elysium'
				) }
			</p>
		</div>
	);
}

export default AdminBranding;
