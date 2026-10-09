/**
 * My Chronicle: the chronicles a signed-in account can ask to join, and the panel that asks one of them - a message,
 * or starting a character, or sending a Grapevine file - with a waiting request showing Withdraw instead.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { errorMessage } from '../../lib/errorMessage';
import { characterEditorUrl, sendFileLinkUrl } from '../../lib/pluginPages';
import {
	JOIN_MESSAGE_MAX_LENGTH,
	validateJoinMessage,
} from '../../lib/joinChronicleForm';
import { CharacterEditor } from '../character/CharacterEditor';
import HelpButton from '../shared/HelpButton';
import type { JoinableChronicle, MyJoinRequest } from '../../types';
import './JoinChronicle.css';

/**
 * `join_slug` carries the panel's own target chronicle, kept separate from `game_slug` so opening it for a
 * chronicle the account doesn't belong to never feeds the chronicle switcher's own membership-only state.
 */
function readJoinFromUrl(): string {
	const params = new URLSearchParams( window.location.search );
	return params.get( 'join' ) === '1'
		? ( params.get( 'join_slug' ) ?? '' )
		: '';
}

function writeJoinToUrl( slug: string | null ): void {
	const url = new URL( window.location.href );
	if ( slug ) {
		url.searchParams.set( 'join', '1' );
		url.searchParams.set( 'join_slug', slug );
	} else {
		url.searchParams.delete( 'join' );
		url.searchParams.delete( 'join_slug' );
	}
	window.history.replaceState( {}, '', url.toString() );
}

export interface JoinChronicleProps {
	/**
	 * Whether the signed-in account already belongs to at least one chronicle - the list starts folded when true.
	 */
	hasMemberships: boolean;
}

export function JoinChronicle( { hasMemberships }: JoinChronicleProps ) {
	const [ joinable, setJoinable ] = useState< JoinableChronicle[] >( [] );
	const [ expanded, setExpanded ] = useState( ! hasMemberships );
	const [ target, setTarget ] = useState( readJoinFromUrl );
	const [ targetName, setTargetName ] = useState( '' );
	const [ startingCharacter, setStartingCharacter ] = useState( false );
	const [ message, setMessage ] = useState( '' );
	const [ waiting, setWaiting ] = useState< MyJoinRequest | null >( null );
	const [ loadingWaiting, setLoadingWaiting ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		api.joinRequests
			.joinable()
			.then( ( found ) => {
				setJoinable( found );
				if ( target && ! targetName ) {
					setTargetName(
						found.find( ( c ) => c.slug === target )?.name ?? ''
					);
				}
			} )
			.catch( () => setJoinable( [] ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		if ( ! target ) {
			setWaiting( null );
			return;
		}
		setLoadingWaiting( true );
		api.joinRequests
			.mine( target )
			.then( ( request ) => setWaiting( request ) )
			.catch( () => setWaiting( null ) )
			.finally( () => setLoadingWaiting( false ) );
	}, [ target ] );

	function openPanel( slug: string, name: string ) {
		setTarget( slug );
		setTargetName( name );
		setStartingCharacter( false );
		setMessage( '' );
		setError( null );
		writeJoinToUrl( slug );
	}

	function closePanel() {
		setTarget( '' );
		setStartingCharacter( false );
		writeJoinToUrl( null );
	}

	function ask() {
		const validationError = validateJoinMessage( message );
		if ( validationError ) {
			setError( validationError );
			return;
		}
		setBusy( true );
		setError( null );
		api.joinRequests
			.ask( target, message.trim() )
			.then( ( request ) => setWaiting( request ) )
			.catch( ( err ) =>
				setError(
					errorMessage(
						err,
						__( 'Failed to send the request.', 'beyond-elysium' )
					)
				)
			)
			.finally( () => setBusy( false ) );
	}

	function withdraw() {
		if (
			! window.confirm( __( 'Withdraw this request?', 'beyond-elysium' ) )
		) {
			return;
		}
		setBusy( true );
		api.joinRequests
			.withdraw( target )
			.then( () => {
				setWaiting( null );
				setMessage( '' );
			} )
			.catch( ( err ) =>
				setError(
					errorMessage(
						err,
						__(
							'Failed to withdraw the request.',
							'beyond-elysium'
						)
					)
				)
			)
			.finally( () => setBusy( false ) );
	}

	return (
		<section className="be-join-chronicle">
			<div className="be-help-heading">
				<button
					type="button"
					className="be-join-chronicle__toggle"
					onClick={ () => setExpanded( ( prev ) => ! prev ) }
					aria-expanded={ expanded }
				>
					{ sprintf(
						/* translators: %d: how many chronicles can be asked to join */
						__(
							'Chronicles you can ask to join (%d)',
							'beyond-elysium'
						),
						joinable.length
					) }
				</button>
				<HelpButton helpKey="joining" />
			</div>

			{ expanded &&
				( joinable.length === 0 ? (
					<p className="be-join-chronicle__hint">
						{ __(
							'No chronicle is taking join requests right now.',
							'beyond-elysium'
						) }
					</p>
				) : (
					<ul className="be-join-chronicle__list">
						{ joinable.map( ( chronicle ) => (
							<li key={ chronicle.slug }>
								<span>{ chronicle.name }</span>
								<button
									type="button"
									className="be-join-chronicle__button"
									onClick={ () =>
										openPanel(
											chronicle.slug,
											chronicle.name
										)
									}
								>
									{ __( 'Ask to join', 'beyond-elysium' ) }
								</button>
							</li>
						) ) }
					</ul>
				) ) }

			{ target && (
				<div className="be-join-chronicle__panel">
					<div className="be-help-heading">
						<h3>
							{ sprintf(
								/* translators: %s: the chronicle's name */
								__( 'Ask to join %s', 'beyond-elysium' ),
								targetName || target
							) }
						</h3>
						<button
							type="button"
							className="be-join-chronicle__button"
							onClick={ closePanel }
						>
							{ __( 'Close', 'beyond-elysium' ) }
						</button>
					</div>

					{ error && (
						<p className="be-join-chronicle__error" role="alert">
							{ error }
						</p>
					) }

					{ loadingWaiting ? (
						<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
					) : waiting ? (
						<div className="be-join-chronicle__waiting">
							<p>{ waiting.message }</p>
							<p className="be-join-chronicle__hint">
								{ sprintf(
									/* translators: %s: the date the request was sent */
									__( 'Waiting since %s.', 'beyond-elysium' ),
									waiting.created_at.slice( 0, 10 )
								) }
							</p>
							{ waiting.character_id && (
								<p>
									{ __(
										'You started a character; a Storyteller will review it.',
										'beyond-elysium'
									) }{ ' ' }
									<a
										href={ characterEditorUrl(
											waiting.character_id,
											target
										) }
									>
										{ __( 'Open it', 'beyond-elysium' ) }
									</a>
								</p>
							) }
							{ waiting.submission_id && (
								<p>
									{ __(
										'You sent a Grapevine file; a Storyteller will review it.',
										'beyond-elysium'
									) }
								</p>
							) }
							<button
								type="button"
								className="be-join-chronicle__button be-join-chronicle__button--remove"
								disabled={ busy }
								onClick={ withdraw }
							>
								{ __( 'Withdraw', 'beyond-elysium' ) }
							</button>
						</div>
					) : startingCharacter ? (
						<CharacterEditor gameSlug={ target } />
					) : (
						<div className="be-join-chronicle__ask">
							<label>
								{ __(
									'A short message to the chronicle’s Storytellers',
									'beyond-elysium'
								) }
								<textarea
									value={ message }
									maxLength={ JOIN_MESSAGE_MAX_LENGTH }
									onChange={ ( e ) =>
										setMessage( e.target.value )
									}
								/>
							</label>
							<p className="be-join-chronicle__actions">
								<button
									type="button"
									className="be-join-chronicle__button"
									disabled={ busy }
									onClick={ ask }
								>
									{ __( 'Send request', 'beyond-elysium' ) }
								</button>
								<button
									type="button"
									className="be-join-chronicle__button"
									onClick={ () =>
										setStartingCharacter( true )
									}
								>
									{ __(
										'Start a character',
										'beyond-elysium'
									) }
								</button>
								<a
									className="be-join-chronicle__button"
									href={ sendFileLinkUrl( target ) }
								>
									{ __(
										'Send a Grapevine file',
										'beyond-elysium'
									) }
								</a>
							</p>
						</div>
					) }
				</div>
			) }
		</section>
	);
}

export default JoinChronicle;
