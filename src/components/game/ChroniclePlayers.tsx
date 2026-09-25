/**
 * Storyteller Toolkit: Players. Finds an existing OWbN account and makes it a player in the chronicle, or takes a
 * player out.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { errorMessage } from '../../lib/errorMessage';
import type { ChroniclePlayerList, ChroniclePlayerResult } from '../../types';
import type { WpUserSummary } from '../../types/character';
import HelpButton from '../shared/HelpButton';
import './ChroniclePlayers.css';

const SEARCH_DEBOUNCE_MS = 300;

export interface ChroniclePlayersProps {
	gameSlug: string;
}

/**
 * The sentences that say what adding or removing someone did, including what accessSchema did.
 */
export function resultMessages(
	name: string,
	result: ChroniclePlayerResult
): string[] {
	const lines: string[] = [];
	switch ( result.status ) {
		case 'added':
			lines.push(
				sprintf(
					/* translators: %s: the account's display name */
					__( '%s is now a player here.', 'beyond-elysium' ),
					name
				)
			);
			break;
		case 'already_player':
			lines.push(
				sprintf(
					/* translators: %s: the account's display name */
					__( '%s was already a player here.', 'beyond-elysium' ),
					name
				)
			);
			break;
		case 'staff':
			lines.push(
				sprintf(
					/* translators: 1: the account's display name, 2: their staff role */
					__(
						'%1$s is on this chronicle’s staff (%2$s). Nothing changed.',
						'beyond-elysium'
					),
					name,
					( result.role ?? '' ).toUpperCase()
				)
			);
			break;
		case 'removed':
			lines.push(
				sprintf(
					/* translators: %s: the account's display name */
					__(
						'%s is no longer a player here. Their characters are unchanged.',
						'beyond-elysium'
					),
					name
				)
			);
			break;
		case 'not_member':
			lines.push(
				sprintf(
					/* translators: %s: the account's display name */
					__( '%s was not a player here.', 'beyond-elysium' ),
					name
				)
			);
			break;
	}

	if ( result.site_added ) {
		lines.push(
			__( 'They were added to this site too.', 'beyond-elysium' )
		);
	}

	const asc = result.asc;
	if ( asc?.attempted ) {
		if ( asc.granted ) {
			lines.push(
				sprintf(
					/* translators: %s: the OWbN role path, such as chronicle/kony/player */
					__( 'OWbN role granted: %s.', 'beyond-elysium' ),
					asc.role_path ?? ''
				)
			);
		} else if ( asc.revoked ) {
			lines.push(
				sprintf(
					/* translators: %s: the OWbN role path, such as chronicle/kony/player */
					__( 'OWbN role removed: %s.', 'beyond-elysium' ),
					asc.role_path ?? ''
				)
			);
		} else if ( asc.granted === false ) {
			lines.push(
				sprintf(
					/* translators: 1: the OWbN role path, 2: why OWbN refused, as it said */
					__(
						'OWbN did not grant %1$s (%2$s). They are still a player here; an OWbN admin can grant the role.',
						'beyond-elysium'
					),
					asc.role_path ?? '',
					asc.message || __( 'no reason given', 'beyond-elysium' )
				)
			);
		} else if ( asc.revoked === false ) {
			lines.push(
				sprintf(
					/* translators: 1: the OWbN role path, 2: why OWbN refused, as it said */
					__(
						'OWbN did not remove %1$s (%2$s); an OWbN admin can remove the role.',
						'beyond-elysium'
					),
					asc.role_path ?? '',
					asc.message || __( 'no reason given', 'beyond-elysium' )
				)
			);
		}
	}
	return lines;
}

export function ChroniclePlayers( { gameSlug }: ChroniclePlayersProps ) {
	const [ list, setList ] = useState< ChroniclePlayerList | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ search, setSearch ] = useState( '' );
	const [ results, setResults ] = useState< WpUserSummary[] >( [] );
	const [ searching, setSearching ] = useState( false );
	const [ busyId, setBusyId ] = useState< number | null >( null );
	const [ messages, setMessages ] = useState< string[] >( [] );

	function load() {
		api.chroniclePlayers( gameSlug )
			.list()
			.then( ( result ) => {
				setList( result );
				setError( null );
			} )
			.catch( ( err ) =>
				setError(
					errorMessage(
						err,
						__( 'Failed to load the players.', 'beyond-elysium' )
					)
				)
			);
	}

	useEffect( load, [ gameSlug ] );

	useEffect( () => {
		const term = search.trim();
		if ( term.length < 3 ) {
			setResults( [] );
			return;
		}
		setSearching( true );
		const timer = window.setTimeout( () => {
			api.wpUsers
				.searchForChronicle( gameSlug, term )
				.then( ( found ) => setResults( found ) )
				.catch( () => setResults( [] ) )
				.finally( () => setSearching( false ) );
		}, SEARCH_DEBOUNCE_MS );
		return () => window.clearTimeout( timer );
	}, [ search, gameSlug ] );

	const playerIds = new Set(
		( list?.players ?? [] ).map( ( p ) => p.wp_user_id )
	);

	function add( user: WpUserSummary ) {
		setBusyId( user.id );
		api.chroniclePlayers( gameSlug )
			.add( user.id )
			.then( ( result ) => {
				setMessages( resultMessages( user.display_name, result ) );
				load();
			} )
			.catch( ( err ) =>
				setMessages( [
					errorMessage(
						err,
						__( 'Failed to add the player.', 'beyond-elysium' )
					),
				] )
			)
			.finally( () => setBusyId( null ) );
	}

	function remove( wpUserId: number, name: string ) {
		if (
			// eslint-disable-next-line no-alert
			! window.confirm(
				sprintf(
					/* translators: %s: the player's display name */
					__(
						'Take %s out of this chronicle? Their characters stay as they are.',
						'beyond-elysium'
					),
					name
				)
			)
		) {
			return;
		}
		setBusyId( wpUserId );
		api.chroniclePlayers( gameSlug )
			.remove( wpUserId )
			.then( ( result ) => {
				setMessages( resultMessages( name, result ) );
				load();
			} )
			.catch( ( err ) =>
				setMessages( [
					errorMessage(
						err,
						__( 'Failed to remove the player.', 'beyond-elysium' )
					),
				] )
			)
			.finally( () => setBusyId( null ) );
	}

	return (
		<div className="be-chronicle-players">
			<div className="be-help-heading">
				<h2>{ __( 'Players', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="chronicle-players" />
			</div>

			{ error && (
				<p className="be-chronicle-players__error" role="alert">
					{ error }
				</p>
			) }

			{ messages.length > 0 && (
				<div className="be-chronicle-players__messages" role="status">
					{ messages.map( ( line ) => (
						<p key={ line }>{ line }</p>
					) ) }
				</div>
			) }

			<section className="be-chronicle-players__add">
				<h3>{ __( 'Add a player', 'beyond-elysium' ) }</h3>
				<label className="be-chronicle-players__search-label">
					{ __( 'Find an OWbN account', 'beyond-elysium' ) }
					<input
						type="search"
						className="be-chronicle-players__search"
						value={ search }
						placeholder={ __(
							'At least three letters of a name, or an exact email',
							'beyond-elysium'
						) }
						onChange={ ( e ) => setSearch( e.target.value ) }
					/>
				</label>
				<p className="be-chronicle-players__hint">
					{ __(
						'Someone with no OWbN account yet needs to sign in through OWbN once before you can add them.',
						'beyond-elysium'
					) }
				</p>
				{ search.trim().length >= 3 &&
					! searching &&
					results.length === 0 && (
						<p className="be-chronicle-players__hint">
							{ __( 'No account matches.', 'beyond-elysium' ) }
						</p>
					) }
				{ results.length > 0 && (
					<ul className="be-chronicle-players__list">
						{ results.map( ( user ) => (
							<li key={ user.id }>
								<span className="be-chronicle-players__name">
									{ user.display_name }
									{ user.email && (
										<span className="be-chronicle-players__email">
											{ user.email }
										</span>
									) }
								</span>
								{ playerIds.has( user.id ) ? (
									<span className="be-chronicle-players__already">
										{ __(
											'Already a player',
											'beyond-elysium'
										) }
									</span>
								) : (
									<button
										type="button"
										className="be-chronicle-players__button"
										disabled={ busyId === user.id }
										onClick={ () => add( user ) }
									>
										{ __(
											'Add as player',
											'beyond-elysium'
										) }
									</button>
								) }
							</li>
						) ) }
					</ul>
				) }
			</section>

			<section className="be-chronicle-players__current">
				<h3>
					{ sprintf(
						/* translators: %d: how many players the chronicle has */
						__( 'Players (%d)', 'beyond-elysium' ),
						list?.players.length ?? 0
					) }
				</h3>
				{ list?.asc_role_path && (
					<p className="be-chronicle-players__hint">
						{ sprintf(
							/* translators: %s: the OWbN role path, such as chronicle/kony/player */
							__(
								'Adding someone here also grants them %s in OWbN, and removing them takes it away. Someone given that role in OWbN directly can play here too, though they are not listed until they are added here.',
								'beyond-elysium'
							),
							list.asc_role_path
						) }
					</p>
				) }
				{ list && list.players.length === 0 && (
					<p className="be-chronicle-players__hint">
						{ __( 'No players yet.', 'beyond-elysium' ) }
					</p>
				) }
				{ list && list.players.length > 0 && (
					<ul className="be-chronicle-players__list">
						{ list.players.map( ( player ) => (
							<li key={ player.wp_user_id }>
								<span className="be-chronicle-players__name">
									{ player.display_name ??
										__(
											'(account removed)',
											'beyond-elysium'
										) }
								</span>
								<button
									type="button"
									className="be-chronicle-players__button be-chronicle-players__button--remove"
									disabled={ busyId === player.wp_user_id }
									onClick={ () =>
										remove(
											player.wp_user_id,
											player.display_name ?? ''
										)
									}
								>
									{ __( 'Remove', 'beyond-elysium' ) }
								</button>
							</li>
						) ) }
					</ul>
				) }
			</section>
		</div>
	);
}

export default ChroniclePlayers;
