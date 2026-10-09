/**
 * Storyteller Toolkit: Players. Invites a player by email with their characters, lists the invites waiting for someone
 * to sign in, and lists the chronicle's players with their characters, which can be added to or unlinked.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { errorMessage } from '../../lib/errorMessage';
import { everyPage } from '../../lib/everyPage';
import { pickerRows, type PickerRow } from '../../lib/characterPicker';
import { inviteMessages, linkMessages } from '../../lib/playerInviteMessages';
import type {
	ChroniclePlayer,
	ChroniclePlayerList,
	ChroniclePlayerResult,
	ChronicleJoinRequest,
	PlayerInvite,
} from '../../types';
import type { Character, WpUserSummary } from '../../types/character';
import HelpButton from '../shared/HelpButton';
import './ChroniclePlayers.css';

const SEARCH_DEBOUNCE_MS = 300;

/**
 * Whether text reads as one email address.
 */
function looksLikeEmail( text: string ): boolean {
	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( text.trim() );
}

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

/**
 * A character's note in the picker: who holds it, or what email it waits for.
 */
function rowNote( row: PickerRow ): string {
	switch ( row.state ) {
		case 'linked':
			return sprintf(
				/* translators: %s: the player a character is linked to */
				__( 'linked to %s', 'beyond-elysium' ),
				row.note || __( 'another player', 'beyond-elysium' )
			);
		case 'waiting':
			return sprintf(
				/* translators: %s: the email address a character waits for */
				__( 'waiting for %s', 'beyond-elysium' ),
				row.note
			);
		case 'own':
			return __( 'already theirs', 'beyond-elysium' );
		default:
			return '';
	}
}

/**
 * The chronicle's player characters as a filterable checklist; a character linked to an account cannot be ticked.
 */
function CharacterPicker( {
	rows,
	selected,
	onToggle,
	filter,
	onFilter,
}: {
	rows: PickerRow[];
	selected: Set< number >;
	onToggle: ( id: number ) => void;
	filter: string;
	onFilter: ( text: string ) => void;
} ) {
	return (
		<div className="be-chronicle-players__picker">
			<input
				type="search"
				className="be-chronicle-players__search be-chronicle-players__search"
				value={ filter }
				placeholder={ __(
					'Filter characters by name',
					'beyond-elysium'
				) }
				aria-label={ __(
					'Filter characters by name',
					'beyond-elysium'
				) }
				onChange={ ( e ) => onFilter( e.target.value ) }
			/>
			{ rows.length === 0 ? (
				<p className="be-chronicle-players__hint">
					{ __( 'No character matches.', 'beyond-elysium' ) }
				</p>
			) : (
				<ul className="be-chronicle-players__picker-list">
					{ rows.map( ( row ) => (
						<li key={ row.id }>
							<label>
								<input
									type="checkbox"
									checked={ selected.has( row.id ) }
									disabled={
										row.state === 'linked' ||
										row.state === 'own'
									}
									onChange={ () => onToggle( row.id ) }
								/>
								<span>{ row.name }</span>
								{ row.state !== 'free' && (
									<span className="be-chronicle-players__email">
										{ rowNote( row ) }
									</span>
								) }
							</label>
						</li>
					) ) }
				</ul>
			) }
			<p className="be-chronicle-players__hint">
				{ sprintf(
					/* translators: %d: how many characters are ticked */
					__( '%d ticked', 'beyond-elysium' ),
					selected.size
				) }
			</p>
		</div>
	);
}

/**
 * A set with one id added or taken away.
 */
function toggled( set: Set< number >, id: number ): Set< number > {
	const next = new Set( set );
	if ( next.has( id ) ) {
		next.delete( id );
	} else {
		next.add( id );
	}
	return next;
}

export function ChroniclePlayers( { gameSlug }: ChroniclePlayersProps ) {
	const [ list, setList ] = useState< ChroniclePlayerList | null >( null );
	const [ invites, setInvites ] = useState< PlayerInvite[] >( [] );
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ joinRequests, setJoinRequests ] = useState<
		ChronicleJoinRequest[]
	>( [] );
	const [ refuseNote, setRefuseNote ] = useState< Record< number, string > >(
		{}
	);
	const [ error, setError ] = useState< string | null >( null );
	const [ messages, setMessages ] = useState< string[] >( [] );
	const [ busy, setBusy ] = useState< string | null >( null );

	const [ email, setEmail ] = useState( '' );
	const [ lookup, setLookup ] = useState< WpUserSummary[] >( [] );
	const [ selection, setSelection ] = useState< Set< number > >( new Set() );
	const [ filter, setFilter ] = useState( '' );
	const [ sendEmail, setSendEmail ] = useState( true );

	const [ addFor, setAddFor ] = useState< number | null >( null );
	const [ addSelection, setAddSelection ] = useState< Set< number > >(
		new Set()
	);
	const [ addFilter, setAddFilter ] = useState( '' );
	const [ linkCopied, setLinkCopied ] = useState( false );

	function copyJoinLink( url: string, inputEl: HTMLInputElement | null ) {
		const done = () => {
			setLinkCopied( true );
			setTimeout( () => setLinkCopied( false ), 3000 );
		};
		if ( navigator.clipboard?.writeText ) {
			navigator.clipboard.writeText( url ).then( done, () => {
				inputEl?.select();
			} );
		} else {
			inputEl?.select();
			document.execCommand( 'copy' );
			done();
		}
	}

	function load() {
		Promise.all( [
			api.chroniclePlayers( gameSlug ).list(),
			api.chroniclePlayers( gameSlug ).invites(),
			everyPage( ( page ) =>
				api
					.characters( gameSlug )
					.listPaginated( { page, per_page: 100 } )
			),
			api.chroniclePlayers( gameSlug ).joinRequests(),
		] )
			.then( ( [ players, open, all, requests ] ) => {
				setList( players );
				setInvites( open );
				setCharacters( all );
				setJoinRequests( requests );
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

	// Looks up accounts by name while the email box holds a name rather than an address.
	useEffect( () => {
		const term = email.trim();
		if ( term.length < 3 || looksLikeEmail( term ) ) {
			setLookup( [] );
			return;
		}
		const timer = window.setTimeout( () => {
			api.wpUsers
				.searchForChronicle( gameSlug, term )
				.then( ( found ) => setLookup( found ) )
				.catch( () => setLookup( [] ) );
		}, SEARCH_DEBOUNCE_MS );
		return () => window.clearTimeout( timer );
	}, [ email, gameSlug ] );

	function invite() {
		const address = email.trim();
		setBusy( 'invite' );
		api.chroniclePlayers( gameSlug )
			.invite( address, Array.from( selection ), sendEmail )
			.then( ( result ) => {
				setMessages( inviteMessages( address, result ) );
				setEmail( '' );
				setSelection( new Set() );
				setFilter( '' );
				setSendEmail( true );
				load();
			} )
			.catch( ( err ) =>
				setMessages( [
					errorMessage(
						err,
						__( 'Failed to send the invite.', 'beyond-elysium' )
					),
				] )
			)
			.finally( () => setBusy( null ) );
	}

	function cancel( pending: PlayerInvite ) {
		if (
			! window.confirm(
				sprintf(
					/* translators: %s: the email address invited */
					__(
						'Cancel the invite for %s? Its characters stop waiting for them.',
						'beyond-elysium'
					),
					pending.email
				)
			)
		) {
			return;
		}
		setBusy( `invite-${ pending.id }` );
		api.chroniclePlayers( gameSlug )
			.cancelInvite( pending.id )
			.then( () => {
				setMessages( [
					sprintf(
						/* translators: %s: the email address invited */
						__(
							'The invite for %s is cancelled.',
							'beyond-elysium'
						),
						pending.email
					),
				] );
				load();
			} )
			.catch( ( err ) =>
				setMessages( [
					errorMessage(
						err,
						__( 'Failed to cancel the invite.', 'beyond-elysium' )
					),
				] )
			)
			.finally( () => setBusy( null ) );
	}

	function approveJoin( request: ChronicleJoinRequest ) {
		setBusy( `join-${ request.id }` );
		api.chroniclePlayers( gameSlug )
			.approveJoinRequest( request.id )
			.then( () => {
				setMessages( [
					sprintf(
						/* translators: %s: the applicant's display name */
						__( '%s is approved to join.', 'beyond-elysium' ),
						request.display_name ??
							__( 'That account', 'beyond-elysium' )
					),
				] );
				load();
			} )
			.catch( ( err ) =>
				setMessages( [
					errorMessage(
						err,
						__( 'Failed to approve the request.', 'beyond-elysium' )
					),
				] )
			)
			.finally( () => setBusy( null ) );
	}

	function refuseJoin( request: ChronicleJoinRequest ) {
		setBusy( `join-${ request.id }` );
		const note = refuseNote[ request.id ] ?? '';
		api.chroniclePlayers( gameSlug )
			.refuseJoinRequest( request.id, note )
			.then( () => {
				setMessages( [
					sprintf(
						/* translators: %s: the applicant's display name */
						__( '%s is refused.', 'beyond-elysium' ),
						request.display_name ??
							__( 'That account', 'beyond-elysium' )
					),
				] );
				setRefuseNote( ( prev ) => {
					const next = { ...prev };
					delete next[ request.id ];
					return next;
				} );
				load();
			} )
			.catch( ( err ) =>
				setMessages( [
					errorMessage(
						err,
						__( 'Failed to refuse the request.', 'beyond-elysium' )
					),
				] )
			)
			.finally( () => setBusy( null ) );
	}

	function openAdd( player: ChroniclePlayer ) {
		setAddFor( player.wp_user_id );
		setAddSelection( new Set() );
		setAddFilter( '' );
	}

	function addCharacters( player: ChroniclePlayer ) {
		setBusy( `add-${ player.wp_user_id }` );
		api.chroniclePlayers( gameSlug )
			.linkCharacters( player.wp_user_id, Array.from( addSelection ) )
			.then( ( result ) => {
				setMessages(
					linkMessages( player.display_name ?? '', result )
				);
				setAddFor( null );
				load();
			} )
			.catch( ( err ) =>
				setMessages( [
					errorMessage(
						err,
						__( 'Failed to link the characters.', 'beyond-elysium' )
					),
				] )
			)
			.finally( () => setBusy( null ) );
	}

	function unlink(
		player: ChroniclePlayer,
		characterId: number,
		name: string
	) {
		if (
			! window.confirm(
				sprintf(
					/* translators: 1: a character's name, 2: the player's display name */
					__( 'Unlink %1$s from %2$s?', 'beyond-elysium' ),
					name,
					player.display_name ?? ''
				)
			)
		) {
			return;
		}
		setBusy( `unlink-${ characterId }` );
		api.chroniclePlayers( gameSlug )
			.unlinkCharacter( player.wp_user_id, characterId )
			.then( () => {
				setMessages( [
					sprintf(
						/* translators: 1: a character's name, 2: the player's display name */
						__( '%1$s is unlinked from %2$s.', 'beyond-elysium' ),
						name,
						player.display_name ?? ''
					),
				] );
				load();
			} )
			.catch( ( err ) =>
				setMessages( [
					errorMessage(
						err,
						__(
							'Failed to unlink the character.',
							'beyond-elysium'
						)
					),
				] )
			)
			.finally( () => setBusy( null ) );
	}

	function remove( player: ChroniclePlayer ) {
		const name = player.display_name ?? '';
		if (
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
		setBusy( `remove-${ player.wp_user_id }` );
		api.chroniclePlayers( gameSlug )
			.remove( player.wp_user_id )
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
			.finally( () => setBusy( null ) );
	}

	const canInvite = looksLikeEmail( email ) && busy === null;

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

			<section className="be-chronicle-players__join-requests">
				<h3>
					{ sprintf(
						/* translators: %d: how many join requests are waiting */
						__( 'Join requests (%d)', 'beyond-elysium' ),
						joinRequests.filter( ( r ) => r.status === 'waiting' )
							.length
					) }
				</h3>
				{ list?.join_link && (
					<p className="be-chronicle-players__hint">
						{ __(
							'Share this link so people can ask to join this chronicle:',
							'beyond-elysium'
						) }
						<br />
						<input
							type="text"
							readOnly
							id="be-join-link"
							value={ list.join_link }
							onFocus={ ( e ) => e.currentTarget.select() }
						/>{ ' ' }
						<button
							type="button"
							className="be-chronicle-players__button"
							onClick={ () =>
								copyJoinLink(
									list.join_link,
									document.getElementById(
										'be-join-link'
									) as HTMLInputElement | null
								)
							}
						>
							{ __( 'Copy link', 'beyond-elysium' ) }
						</button>
						{ linkCopied && (
							<span role="status">
								{ __( 'Link copied.', 'beyond-elysium' ) }
							</span>
						) }
					</p>
				) }
				{ joinRequests.length === 0 ? (
					<p className="be-chronicle-players__hint">
						{ __( 'No one has asked to join.', 'beyond-elysium' ) }
					</p>
				) : (
					<ul className="be-chronicle-players__list">
						{ joinRequests.map( ( request ) => (
							<li key={ request.id }>
								<span className="be-chronicle-players__name">
									{ request.display_name ??
										__(
											'(account removed)',
											'beyond-elysium'
										) }
									<span className="be-chronicle-players__email">
										{ request.message }
									</span>
									{ request.character && (
										<span className="be-chronicle-players__email">
											{ sprintf(
												/* translators: %s: a character's name */
												__(
													'started a character: %s',
													'beyond-elysium'
												),
												request.character.name
											) }
										</span>
									) }
									{ request.status !== 'waiting' && (
										<span className="be-chronicle-players__email">
											{ request.status === 'approved' &&
												__(
													'Approved.',
													'beyond-elysium'
												) }
											{ request.status === 'refused' &&
												__(
													'Refused.',
													'beyond-elysium'
												) }
											{ request.status === 'withdrawn' &&
												__(
													'Withdrawn.',
													'beyond-elysium'
												) }
										</span>
									) }
								</span>
								{ request.status === 'waiting' && (
									<span className="be-chronicle-players__actions">
										{ request.submission_id ? (
											<span className="be-chronicle-players__hint">
												{ __(
													'This request carries a Grapevine file - review and accept it from Import, which closes this request too.',
													'beyond-elysium'
												) }
											</span>
										) : (
											<button
												type="button"
												className="be-chronicle-players__button"
												disabled={ busy !== null }
												onClick={ () =>
													approveJoin( request )
												}
											>
												{ __(
													'Approve',
													'beyond-elysium'
												) }
											</button>
										) }
										<input
											type="text"
											className="be-chronicle-players__search"
											placeholder={ __(
												'Note (optional)',
												'beyond-elysium'
											) }
											value={
												refuseNote[ request.id ] ?? ''
											}
											onChange={ ( e ) =>
												setRefuseNote( ( prev ) => ( {
													...prev,
													[ request.id ]:
														e.target.value,
												} ) )
											}
										/>
										<button
											type="button"
											className="be-chronicle-players__button be-chronicle-players__button--remove"
											disabled={ busy !== null }
											onClick={ () =>
												refuseJoin( request )
											}
										>
											{ __( 'Refuse', 'beyond-elysium' ) }
										</button>
									</span>
								) }
							</li>
						) ) }
					</ul>
				) }
			</section>

			<section className="be-chronicle-players__add">
				<h3>{ __( 'Invite a player', 'beyond-elysium' ) }</h3>
				<label className="be-chronicle-players__search-label">
					{ __( 'Their email address', 'beyond-elysium' ) }
					<input
						type="text"
						inputMode="email"
						className="be-chronicle-players__search be-chronicle-players__search"
						value={ email }
						placeholder={ __(
							'An email address, or three letters of a name to look someone up',
							'beyond-elysium'
						) }
						onChange={ ( e ) => setEmail( e.target.value ) }
					/>
				</label>
				{ lookup.length > 0 && (
					<ul className="be-chronicle-players__list">
						{ lookup.map( ( user ) => (
							<li key={ user.id }>
								<span className="be-chronicle-players__name">
									{ user.display_name }
									{ user.email && (
										<span className="be-chronicle-players__email">
											{ user.email }
										</span>
									) }
								</span>
								{ user.email && (
									<button
										type="button"
										className="be-chronicle-players__button"
										onClick={ () =>
											setEmail( user.email ?? '' )
										}
									>
										{ __(
											'Use this email',
											'beyond-elysium'
										) }
									</button>
								) }
							</li>
						) ) }
					</ul>
				) }

				<p className="be-chronicle-players__hint">
					{ __(
						'Tick their characters. Someone who already has an OWbN account with this email becomes a player now; anyone else is linked the first time they sign in with it.',
						'beyond-elysium'
					) }
				</p>
				<CharacterPicker
					rows={ pickerRows( characters, filter ) }
					selected={ selection }
					onToggle={ ( id ) =>
						setSelection( ( prev ) => toggled( prev, id ) )
					}
					filter={ filter }
					onFilter={ setFilter }
				/>
				<label className="be-chronicle-players__checkbox">
					<input
						type="checkbox"
						checked={ sendEmail }
						onChange={ ( e ) => setSendEmail( e.target.checked ) }
					/>
					{ __(
						'Email them an invitation if they have no account yet',
						'beyond-elysium'
					) }
				</label>
				<p>
					<button
						type="button"
						className="be-chronicle-players__button"
						disabled={ ! canInvite }
						onClick={ invite }
					>
						{ __( 'Invite', 'beyond-elysium' ) }
					</button>
				</p>
			</section>

			<section className="be-chronicle-players__waiting">
				<h3>
					{ sprintf(
						/* translators: %d: how many invites wait for someone to sign in */
						__( 'Waiting to sign in (%d)', 'beyond-elysium' ),
						invites.length
					) }
				</h3>
				{ invites.length === 0 ? (
					<p className="be-chronicle-players__hint">
						{ __( 'No invites are waiting.', 'beyond-elysium' ) }
					</p>
				) : (
					<ul className="be-chronicle-players__list">
						{ invites.map( ( pending ) => (
							<li key={ pending.id }>
								<span className="be-chronicle-players__name">
									{ pending.email }
									<span className="be-chronicle-players__email">
										{ pending.characters.length > 0
											? pending.characters
													.map( ( c ) => c.name )
													.join( ', ' )
											: __(
													'no characters',
													'beyond-elysium'
												) }
									</span>
									<span className="be-chronicle-players__email">
										{ pending.invited_by
											? sprintf(
													/* translators: 1: who sent the invite, 2: when */
													__(
														'sent by %1$s, %2$s',
														'beyond-elysium'
													),
													pending.invited_by,
													pending.invited_at.slice(
														0,
														10
													)
												)
											: pending.invited_at.slice(
													0,
													10
												) }
									</span>
								</span>
								<button
									type="button"
									className="be-chronicle-players__button be-chronicle-players__button--remove"
									disabled={ busy !== null }
									onClick={ () => cancel( pending ) }
								>
									{ __( 'Cancel', 'beyond-elysium' ) }
								</button>
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
								<span className="be-chronicle-players__actions">
									<button
										type="button"
										className="be-chronicle-players__button"
										disabled={ busy !== null }
										onClick={ () => openAdd( player ) }
									>
										{ __(
											'Add characters',
											'beyond-elysium'
										) }
									</button>
									<button
										type="button"
										className="be-chronicle-players__button be-chronicle-players__button--remove"
										disabled={ busy !== null }
										onClick={ () => remove( player ) }
									>
										{ __( 'Remove', 'beyond-elysium' ) }
									</button>
								</span>
								<ul className="be-chronicle-players__characters">
									{ player.characters.length === 0 && (
										<li className="be-chronicle-players__email">
											{ __(
												'No characters linked.',
												'beyond-elysium'
											) }
										</li>
									) }
									{ player.characters.map( ( c ) => (
										<li key={ c.id }>
											<span>{ c.name }</span>
											<button
												type="button"
												className="be-chronicle-players__unlink"
												disabled={ busy !== null }
												aria-label={ sprintf(
													/* translators: %s: a character's name */
													__(
														'Unlink %s',
														'beyond-elysium'
													),
													c.name
												) }
												onClick={ () =>
													unlink(
														player,
														c.id,
														c.name
													)
												}
											>
												{ __(
													'Unlink',
													'beyond-elysium'
												) }
											</button>
										</li>
									) ) }
								</ul>
								{ addFor === player.wp_user_id && (
									<div className="be-chronicle-players__add-for">
										<CharacterPicker
											rows={ pickerRows(
												characters,
												addFilter,
												player.wp_user_id
											) }
											selected={ addSelection }
											onToggle={ ( id ) =>
												setAddSelection( ( prev ) =>
													toggled( prev, id )
												)
											}
											filter={ addFilter }
											onFilter={ setAddFilter }
										/>
										<p>
											<button
												type="button"
												className="be-chronicle-players__button"
												disabled={
													addSelection.size === 0 ||
													busy !== null
												}
												onClick={ () =>
													addCharacters( player )
												}
											>
												{ __(
													'Link ticked characters',
													'beyond-elysium'
												) }
											</button>{ ' ' }
											<button
												type="button"
												className="be-chronicle-players__button"
												onClick={ () =>
													setAddFor( null )
												}
											>
												{ __(
													'Close',
													'beyond-elysium'
												) }
											</button>
										</p>
									</div>
								) }
							</li>
						) ) }
					</ul>
				) }
			</section>
		</div>
	);
}

export default ChroniclePlayers;
