/**
 * My Chronicle: the panel that confirms a Discord link request, read by its own token in the URL - picking the
 * characters it should carry, or saying it has expired.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { errorMessage } from '../../lib/errorMessage';
import {
	readDiscordLinkParams,
	clearedDiscordLinkUrl,
} from '../../lib/discordLinkParams';
import type { DiscordLinkPreview } from '../../types';
import HelpButton from '../shared/HelpButton';
import './DiscordLinkPanel.css';

export function DiscordLinkPanel() {
	const [ target ] = useState( () =>
		readDiscordLinkParams( window.location.search )
	);
	const [ preview, setPreview ] = useState< DiscordLinkPreview | null >(
		null
	);
	const [ selected, setSelected ] = useState< number[] >( [] );
	const [ result, setResult ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		if ( ! target ) {
			return;
		}
		const loggedIn = Number( window.beyondElysium?.currentUserId ?? 0 ) > 0;
		if ( ! loggedIn ) {
			const redirect = encodeURIComponent( window.location.href );
			window.location.href = `/wp-login.php?redirect_to=${ redirect }`;
			return;
		}
		api.discordLinks( target.slug )
			.preview( target.token )
			.then( setPreview )
			.catch( ( err ) =>
				setError(
					errorMessage(
						err,
						__( 'That link has expired.', 'beyond-elysium' )
					)
				)
			)
			.finally( () => setLoading( false ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( ! target ) {
		return null;
	}

	function toggle( characterId: number ) {
		setSelected( ( prev ) =>
			prev.includes( characterId )
				? prev.filter( ( id ) => id !== characterId )
				: [ ...prev, characterId ]
		);
	}

	function confirm() {
		if ( ! target || ! preview ) {
			return;
		}
		if ( ! preview.staff && selected.length === 0 ) {
			setError( __( 'Pick at least one character.', 'beyond-elysium' ) );
			return;
		}
		setBusy( true );
		setError( null );
		api.discordLinks( target.slug )
			.confirm( target.token, selected )
			.then( ( response ) => {
				setResult( response.status );
				window.history.replaceState(
					{},
					'',
					clearedDiscordLinkUrl( window.location.href )
				);
			} )
			.catch( ( err ) =>
				setError(
					errorMessage(
						err,
						__( 'Failed to confirm the link.', 'beyond-elysium' )
					)
				)
			)
			.finally( () => setBusy( false ) );
	}

	return (
		<section className="be-discord-link-panel">
			<div className="be-help-heading">
				<h3>{ __( 'Link your Discord account', 'beyond-elysium' ) }</h3>
				<HelpButton helpKey="discord" />
			</div>

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : result === 'approved' ? (
				<p>{ __( "You're linked.", 'beyond-elysium' ) }</p>
			) : result === 'confirmed' ? (
				<p>
					{ __(
						"You're confirmed. A Storyteller will approve it.",
						'beyond-elysium'
					) }
				</p>
			) : error && ! preview ? (
				<p className="be-discord-link-panel__error" role="alert">
					{ error }
				</p>
			) : preview ? (
				<div className="be-discord-link-panel__body">
					<p>
						{ sprintf(
							/* translators: %s: the Discord account's name */
							__(
								'Linking %s to your account here.',
								'beyond-elysium'
							),
							preview.discord_username
						) }
					</p>
					{ error && (
						<p
							className="be-discord-link-panel__error"
							role="alert"
						>
							{ error }
						</p>
					) }
					{ preview.characters.length === 0 ? (
						<p className="be-discord-link-panel__hint">
							{ __(
								'You have no characters in this chronicle yet.',
								'beyond-elysium'
							) }
						</p>
					) : (
						<ul className="be-discord-link-panel__characters">
							{ preview.characters.map( ( character ) => (
								<li key={ character.id }>
									<label>
										<input
											type="checkbox"
											checked={ selected.includes(
												character.id
											) }
											onChange={ () =>
												toggle( character.id )
											}
										/>
										{ character.name }
									</label>
								</li>
							) ) }
						</ul>
					) }
					<button
						type="button"
						className="be-discord-link-panel__button"
						disabled={ busy }
						onClick={ confirm }
					>
						{ __( 'Confirm', 'beyond-elysium' ) }
					</button>
				</div>
			) : null }
		</section>
	);
}

export default DiscordLinkPanel;
