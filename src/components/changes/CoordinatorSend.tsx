/**
 * What a Storyteller sees where a waiting change needs a Coordinator: the choice when they press Approve, the line
 * that says where a sent change stands, and the dialog that sends it to the Archivist Toolkit as them.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import {
	defaultOffices,
	lineForPick,
	requestParts,
	sendBody,
} from '../../lib/coordinatorSend';
import { errorMessage } from '../../lib/errorMessage';
import { formatStoredTime } from '../../lib/storedTime';
import type {
	CoordinatorOffice,
	CoordinatorRequest,
	CoordinatorSendLine,
	CoordinatorSendResponse,
	CoordinatorStatus,
	PendingPick,
	ToolkitCharacter,
	ToolkitRule,
} from '../../types/coordinator';
import Modal from '../shared/Modal';
import './CoordinatorSend.css';

/**
 * The names of the Coordinators a list of changes asks for, once each, as a phrase ("the Assamite Coordinator").
 */
function coordinatorPhrase( names: string[] ): string {
	if ( names.length === 0 ) {
		return __( 'a Coordinator', 'beyond-elysium' );
	}
	return names
		.map( ( name ) =>
			sprintf(
				/* translators: %s: a Coordinator's office, such as Assamite */
				__( 'the %s Coordinator', 'beyond-elysium' ),
				name
			)
		)
		.join( ` ${ __( 'and', 'beyond-elysium' ) } ` );
}

export interface CoordinatorChoiceProps {
	kind: 'approval' | 'notify';
	names: string[];
	onSend: () => void;
	onApproveNow: () => void;
	onCancel: () => void;
}

/**
 * The choice under a row's Approve button: send it to the Coordinator, or approve it now.
 */
export function CoordinatorChoice( {
	kind,
	names,
	onSend,
	onApproveNow,
	onCancel,
}: CoordinatorChoiceProps ) {
	return (
		<div className="be-coordinator-choice" role="group">
			<p className="be-coordinator-choice__text">
				{ kind === 'approval'
					? sprintf(
							/* translators: %s: the Coordinator this needs, such as "the Assamite Coordinator" */
							__(
								'This needs %s’s approval. Do this when the Coordinator has already approved it, by email or otherwise.',
								'beyond-elysium'
							),
							coordinatorPhrase( names )
						)
					: sprintf(
							/* translators: %s: the Coordinator to tell, such as "the Assamite Coordinator" */
							__(
								'Tell %s about this. You can approve it and send it in as the notice.',
								'beyond-elysium'
							),
							coordinatorPhrase( names )
						) }
			</p>
			<div className="be-coordinator-choice__buttons">
				<button type="button" onClick={ onSend }>
					{ kind === 'approval'
						? __( 'Send to the Coordinator', 'beyond-elysium' )
						: __( 'Approve and send it in', 'beyond-elysium' ) }
				</button>
				<button type="button" onClick={ onApproveNow }>
					{ kind === 'approval'
						? __( 'Approve now', 'beyond-elysium' )
						: __( 'Approve only', 'beyond-elysium' ) }
				</button>
				<button type="button" onClick={ onCancel }>
					{ __( 'Cancel', 'beyond-elysium' ) }
				</button>
			</div>
		</div>
	);
}

export interface CoordinatorRequestLineProps {
	gameSlug: string;
	request: CoordinatorRequest;
	/**
	 * Called after a read or a failed read, so the queue shows what is now true.
	 */
	onChanged: () => void;
	onSendAgain: () => void;
}

/**
 * Where a change sent to the Toolkit stands, with a way to read it now and to open it there.
 */
export function CoordinatorRequestLine( {
	gameSlug,
	request,
	onChanged,
	onSendAgain,
}: CoordinatorRequestLineProps ) {
	const [ reading, setReading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	async function checkNow() {
		setReading( true );
		setError( null );
		try {
			const result = await api
				.coordinator( gameSlug )
				.check( request.id );
			if ( result.message ) {
				setError( result.message );
			}
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__(
						'The Archivist Toolkit could not be read.',
						'beyond-elysium'
					)
				)
			);
		}
		setReading( false );
		onChanged();
	}

	const unconfirmed =
		request.state === 'unconfirmed' || request.state === 'sending';
	const shownError = error ?? request.check_error ?? null;

	return (
		<div className="be-coordinator-line">
			<p className="be-coordinator-line__status">
				{ requestParts( request ).join( ' · ' ) }
				{ request.checked_at
					? ` · ${ sprintf(
							/* translators: %s: when the Toolkit was last read */
							__( 'checked %s', 'beyond-elysium' ),
							formatStoredTime( request.checked_at, {
								time: true,
							} )
						) }`
					: '' }
			</p>
			{ shownError && (
				<p className="be-coordinator-line__error">{ shownError }</p>
			) }
			<div className="be-coordinator-line__buttons">
				{ unconfirmed ? (
					<button type="button" onClick={ onSendAgain }>
						{ __( 'Send again', 'beyond-elysium' ) }
					</button>
				) : (
					<button
						type="button"
						disabled={ reading }
						onClick={ checkNow }
					>
						{ __( 'Check now', 'beyond-elysium' ) }
					</button>
				) }
				{ request.entry_url && (
					<a
						className="be-coordinator-line__link"
						href={ request.entry_url }
						target="_blank"
						rel="noreferrer noopener"
					>
						{ __( 'Open in the Toolkit', 'beyond-elysium' ) }
					</a>
				) }
			</div>
		</div>
	);
}

export interface CoordinatorSendDialogProps {
	gameSlug: string;
	status: CoordinatorStatus;
	characterId: number;
	characterName: string;
	/**
	 * What is being sent: waiting changes, or a new character's picks.
	 */
	lines: CoordinatorSendLine[];
	purpose: 'approval' | 'notify';
	onClose: () => void;
	/**
	 * Called once everything went, so the queue reloads.
	 */
	onSent: () => void;
}

/**
 * The pop-up that sends waiting changes to the Archivist Toolkit as the Storyteller, or in a demo shows what would go.
 */
export function CoordinatorSendDialog( {
	gameSlug,
	status,
	characterId,
	characterName,
	lines,
	purpose,
	onClose,
	onSent,
}: CoordinatorSendDialogProps ) {
	const live = status.mode === 'live';
	const [ uuid, setUuid ] = useState< string | null | undefined >(
		live ? undefined : null
	);
	const [ linkTerm, setLinkTerm ] = useState( characterName );
	const [ found, setFound ] = useState< ToolkitCharacter[] | null >( null );
	const [ ruleTerm, setRuleTerm ] = useState( () => lines[ 0 ]?.term ?? '' );
	const [ ruleResults, setRuleResults ] = useState< ToolkitRule[] >( [] );
	const [ ticked, setTicked ] = useState< ToolkitRule[] >( [] );
	const [ offices, setOffices ] = useState< CoordinatorOffice[] >( [] );
	const [ officeTicks, setOfficeTicks ] = useState< string[] >( [] );
	const [ note, setNote ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ outcome, setOutcome ] = useState< CoordinatorSendResponse | null >(
		null
	);

	const names = Array.from(
		new Set( lines.flatMap( ( line ) => line.coordinators ) )
	);

	function searchCharacters( term: string ) {
		if ( ! live || term.trim().length < 2 ) {
			return;
		}
		api.coordinator( gameSlug )
			.searchCharacters( characterId, term.trim() )
			.then( ( result ) => setFound( result.results ) )
			.catch( ( err: unknown ) => {
				setFound( [] );
				setError(
					errorMessage(
						err,
						__(
							'The Archivist Toolkit could not be searched.',
							'beyond-elysium'
						)
					)
				);
			} );
	}

	function searchRules( term: string ) {
		if ( ! live || term.trim().length < 2 ) {
			return;
		}
		api.coordinator( gameSlug )
			.rules( term.trim() )
			.then( ( result ) => setRuleResults( result.results ) )
			.catch( () => setRuleResults( [] ) );
	}

	useEffect( () => {
		if ( ! live ) {
			return;
		}
		api.coordinator( gameSlug )
			.link( characterId )
			.then( ( result ) => {
				setUuid( result.uuid );
				if ( ! result.uuid ) {
					searchCharacters( characterName );
				}
			} )
			.catch( () => setUuid( null ) );
		searchRules( ruleTerm );
		api.coordinator( gameSlug )
			.offices()
			.then( ( result ) => {
				setOffices( result.offices );
				setOfficeTicks( defaultOffices( names, result.offices ) );
			} )
			.catch( () => setOffices( [] ) );
		// Runs once when the dialog opens.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	async function link( toolkit: ToolkitCharacter ) {
		setError( null );
		try {
			const result = await api
				.coordinator( gameSlug )
				.setLink( characterId, toolkit.uuid );
			setUuid( result.uuid );
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'The link could not be saved.', 'beyond-elysium' )
				)
			);
		}
	}

	async function unlink() {
		setError( null );
		try {
			await api.coordinator( gameSlug ).clearLink( characterId );
			setUuid( null );
			setFound( null );
			searchCharacters( characterName );
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'The link could not be removed.', 'beyond-elysium' )
				)
			);
		}
	}

	function toggleRule( rule: ToolkitRule ) {
		setTicked( ( prev ) =>
			prev.some( ( item ) => item.id === rule.id )
				? prev.filter( ( item ) => item.id !== rule.id )
				: [ ...prev, rule ]
		);
	}

	function toggleOffice( slug: string ) {
		setOfficeTicks( ( prev ) =>
			prev.includes( slug )
				? prev.filter( ( item ) => item !== slug )
				: [ ...prev, slug ]
		);
	}

	async function send() {
		setBusy( true );
		setError( null );
		try {
			const response = await api.coordinator( gameSlug ).send(
				sendBody( {
					characterId,
					...( lines.some( ( line ) => line.key !== undefined )
						? {
								pickKeys: lines
									.map( ( line ) => line.key )
									.filter( ( key ): key is string => !! key ),
							}
						: {
								changeIds: lines
									.map( ( line ) => line.id )
									.filter(
										( id ): id is number => id !== undefined
									),
							} ),
					rules: ticked,
					coordinators: officeTicks,
					note,
					purpose,
				} )
			);
			const results = response.results ?? [];
			const allWent =
				response.mode === 'live' &&
				results.length > 0 &&
				results.every(
					( result ) =>
						result.state === 'sent' || result.state === 'filed'
				);
			if ( allWent ) {
				onSent();
				return;
			}
			setOutcome( response );
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'It could not be sent.', 'beyond-elysium' )
				)
			);
		}
		setBusy( false );
	}

	const unlinked = live && uuid === null;
	const loading = live && uuid === undefined;
	const canSend =
		! busy &&
		! loading &&
		( ! live || ( uuid !== null && uuid !== undefined ) );
	const unconfirmed = ( outcome?.results ?? [] ).some(
		( result ) => result.state === 'unconfirmed'
	);
	const stoppedMessage =
		( outcome?.results ?? [] ).find( ( result ) => result.message )
			?.message ?? null;

	return (
		<Modal
			title={
				live
					? __( 'Send to the Coordinator', 'beyond-elysium' )
					: __( 'Show what would be sent', 'beyond-elysium' )
			}
			onClose={ onClose }
			className="be-coordinator-modal"
			footer={
				<div className="be-coordinator-dialog__buttons">
					<button type="button" onClick={ onClose } disabled={ busy }>
						{ outcome
							? __( 'Close', 'beyond-elysium' )
							: __( 'Cancel', 'beyond-elysium' ) }
					</button>
					{ ( ! outcome || unconfirmed ) && (
						<button
							type="button"
							className="button-primary"
							disabled={ ! canSend }
							onClick={ send }
						>
							{ unconfirmed
								? __( 'Send again', 'beyond-elysium' )
								: live
									? purpose === 'notify'
										? __(
												'Approve and send it in',
												'beyond-elysium'
											)
										: __(
												'Send to the Coordinator',
												'beyond-elysium'
											)
									: __(
											'Show what would be sent',
											'beyond-elysium'
										) }
						</button>
					) }
				</div>
			}
		>
			<div className="be-coordinator-dialog">
				<ul className="be-coordinator-dialog__lines">
					{ lines.map( ( line ) => (
						<li key={ line.id ?? line.key }>
							{ line.label }
							{ line.reason ? ` — ${ line.reason }` : '' }
						</li>
					) ) }
				</ul>

				{ error && (
					<div className="be-coordinator-dialog__error" role="alert">
						{ error }
					</div>
				) }

				{ outcome?.mode === 'preview' &&
					( outcome.previews ?? [] ).map( ( preview ) => (
						<div
							className="be-coordinator-dialog__notice"
							key={
								preview.change_id ??
								preview.key ??
								preview.body.title
							}
						>
							<dl className="be-coordinator-dialog__preview">
								<dt>{ __( 'Request', 'beyond-elysium' ) }</dt>
								<dd>{ preview.body.title }</dd>
								<dt>{ __( 'Form', 'beyond-elysium' ) }</dt>
								<dd>{ preview.body.form_slug }</dd>
								<dt>{ __( 'Chronicle', 'beyond-elysium' ) }</dt>
								<dd>{ preview.body.chronicle_slug }</dd>
								<dt>
									{ __(
										'Regulation rules',
										'beyond-elysium'
									) }
								</dt>
								<dd>
									{ preview.body.rules.length > 0
										? preview.body.rules.join( ', ' )
										: __(
												'None chosen',
												'beyond-elysium'
											) }
								</dd>
								<dt>
									{ __(
										'Coordinators who must approve',
										'beyond-elysium'
									) }
								</dt>
								<dd>
									{ preview.body.meta.involved_coordinators
										? preview.body.meta
												.involved_coordinators
										: __(
												'None chosen',
												'beyond-elysium'
											) }
								</dd>
								<dt>{ __( 'Note', 'beyond-elysium' ) }</dt>
								<dd>{ preview.body.note }</dd>
							</dl>
						</div>
					) ) }

				{ outcome?.mode === 'preview' && (
					<p className="be-coordinator-dialog__hint">
						{ __(
							'On an OWbN chronicle this goes to the Archivist Toolkit as an R&U Request. The demo sends nothing.',
							'beyond-elysium'
						) }
					</p>
				) }

				{ outcome?.mode === 'live' && stoppedMessage && (
					<div
						className="be-coordinator-dialog__notice"
						role="status"
					>
						{ unconfirmed
							? `${ stoppedMessage } ${ __(
									'It may have gone through. Sending again is safe: it will not be filed twice.',
									'beyond-elysium'
								) }`
							: stoppedMessage }
					</div>
				) }

				{ ! outcome && (
					<>
						{ live && (
							<div className="be-coordinator-dialog__field">
								<span>
									{ __(
										'Archivist Toolkit record',
										'beyond-elysium'
									) }
								</span>
								{ uuid ? (
									<p className="be-coordinator-dialog__hint">
										{ sprintf(
											/* translators: %s: a character's name */
											__(
												'%s is linked to its record in the Toolkit.',
												'beyond-elysium'
											),
											characterName
										) }{ ' ' }
										<button
											type="button"
											onClick={ unlink }
										>
											{ __(
												'Change the link',
												'beyond-elysium'
											) }
										</button>
									</p>
								) : (
									<>
										<div className="be-coordinator-dialog__search">
											<input
												className="be-coordinator-dialog__input"
												type="search"
												value={ linkTerm }
												aria-label={ __(
													'Search the Toolkit for this character',
													'beyond-elysium'
												) }
												onChange={ ( e ) =>
													setLinkTerm(
														e.target.value
													)
												}
												onKeyDown={ ( e ) => {
													if ( e.key === 'Enter' ) {
														e.preventDefault();
														searchCharacters(
															linkTerm
														);
													}
												} }
											/>
											<button
												type="button"
												onClick={ () =>
													searchCharacters( linkTerm )
												}
											>
												{ __(
													'Search',
													'beyond-elysium'
												) }
											</button>
										</div>
										{ found !== null &&
											found.length === 0 && (
												<p className="be-coordinator-dialog__hint">
													{ sprintf(
														/* translators: %s: a character's name */
														__(
															'%s is not in the Archivist Toolkit yet. Register them there first.',
															'beyond-elysium'
														),
														characterName
													) }
												</p>
											) }
										<ul className="be-coordinator-dialog__list">
											{ ( found ?? [] ).map( ( row ) => (
												<li key={ row.uuid }>
													<button
														type="button"
														onClick={ () =>
															link( row )
														}
													>
														{ [
															row.name,
															row.player,
															row.chronicle,
															row.pc_npc.toUpperCase(),
														]
															.filter( Boolean )
															.join( ' · ' ) }
														{ row.exact
															? ` (${ __( 'same name', 'beyond-elysium' ) })`
															: '' }
													</button>
												</li>
											) ) }
										</ul>
									</>
								) }
							</div>
						) }

						{ live && (
							<fieldset className="be-coordinator-dialog__field">
								<legend>
									{ __(
										'Regulation rules',
										'beyond-elysium'
									) }
								</legend>
								<div className="be-coordinator-dialog__search">
									<input
										className="be-coordinator-dialog__input"
										type="search"
										value={ ruleTerm }
										aria-label={ __(
											'Search the Toolkit’s rules',
											'beyond-elysium'
										) }
										onChange={ ( e ) =>
											setRuleTerm( e.target.value )
										}
										onKeyDown={ ( e ) => {
											if ( e.key === 'Enter' ) {
												e.preventDefault();
												searchRules( ruleTerm );
											}
										} }
									/>
									<button
										type="button"
										onClick={ () =>
											searchRules( ruleTerm )
										}
									>
										{ __( 'Search', 'beyond-elysium' ) }
									</button>
								</div>
								<ul className="be-coordinator-dialog__list">
									{ [
										...ticked,
										...ruleResults.filter(
											( rule ) =>
												! ticked.some(
													( item ) =>
														item.id === rule.id
												)
										),
									].map( ( rule ) => (
										<li key={ rule.id }>
											<label className="be-coordinator-dialog__check">
												<input
													type="checkbox"
													checked={ ticked.some(
														( item ) =>
															item.id === rule.id
													) }
													onChange={ () =>
														toggleRule( rule )
													}
												/>
												<span>{ rule.label }</span>
											</label>
										</li>
									) ) }
								</ul>
							</fieldset>
						) }

						{ live && offices.length > 0 && (
							<fieldset className="be-coordinator-dialog__field">
								<legend>
									{ __(
										'Coordinators who must approve',
										'beyond-elysium'
									) }
								</legend>
								<ul className="be-coordinator-dialog__list">
									{ offices.map( ( office ) => (
										<li key={ office.slug }>
											<label className="be-coordinator-dialog__check">
												<input
													type="checkbox"
													checked={ officeTicks.includes(
														office.slug
													) }
													onChange={ () =>
														toggleOffice(
															office.slug
														)
													}
												/>
												<span>{ office.name }</span>
											</label>
										</li>
									) ) }
								</ul>
							</fieldset>
						) }

						<label className="be-coordinator-dialog__field">
							<span>
								{ __(
									'Note to the Coordinator',
									'beyond-elysium'
								) }
							</span>
							<textarea
								className="be-coordinator-dialog__input"
								rows={ 3 }
								maxLength={ 1000 }
								value={ note }
								onChange={ ( e ) => setNote( e.target.value ) }
							/>
						</label>

						<p className="be-coordinator-dialog__hint">
							{ live
								? sprintf(
										/* translators: 1: the Storyteller's name, 2: their email */
										__(
											'This files an R&U Request in the Archivist Toolkit as you (%1$s, %2$s). Next: your HST signs it at Staff Review, then the Coordinator.',
											'beyond-elysium'
										),
										status.sender.name,
										status.sender.email
									)
								: __(
										'This shows the R&U Request that would be filed in the Archivist Toolkit as you. The demo sends nothing.',
										'beyond-elysium'
									) }
							{ ticked.length === 0 && officeTicks.length === 0
								? ` ${ __(
										'With no rule and no Coordinator chosen, it goes to the character’s clan or sect Coordinator.',
										'beyond-elysium'
									) }`
								: '' }
						</p>

						{ unlinked && (
							<p className="be-coordinator-dialog__hint">
								{ __(
									'Pick the character’s record above before sending.',
									'beyond-elysium'
								) }
							</p>
						) }
					</>
				) }
			</div>
		</Modal>
	);
}

export interface PendingCoordinatorPicksProps {
	gameSlug: string;
	characterId: number;
	characterName: string;
}

/**
 * The picks of a pending new character that ask for a Coordinator, with where each one's request stands and a way to
 * send them. Shows nothing where the chronicle does not send or no pick asks.
 */
export function PendingCoordinatorPicks( {
	gameSlug,
	characterId,
	characterName,
}: PendingCoordinatorPicksProps ) {
	const [ status, setStatus ] = useState< CoordinatorStatus | null >( null );
	const [ picks, setPicks ] = useState< PendingPick[] >( [] );
	const [ dialog, setDialog ] = useState< {
		keys: string[];
		purpose: 'approval' | 'notify';
	} | null >( null );

	function load() {
		api.coordinator( gameSlug )
			.picks( characterId )
			.then( ( result ) => setPicks( result.picks ) )
			.catch( () => setPicks( [] ) );
	}

	useEffect( () => {
		api.coordinator( gameSlug )
			.status()
			.then( ( result ) => setStatus( result.mode ? result : null ) )
			.catch( () => setStatus( null ) );
		load();
		// Reads again when the character changes.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ gameSlug, characterId ] );

	if ( ! status || picks.length === 0 ) {
		return null;
	}

	const open = ( pick: PendingPick ) =>
		!! pick.request &&
		[ 'sending', 'unconfirmed', 'sent' ].includes( pick.request.state );
	const toApprove = picks.filter(
		( pick ) => pick.signal.kind === 'approval' && ! pick.request
	);
	const toNotify = picks.filter(
		( pick ) => pick.signal.kind === 'notify' && ! pick.request
	);

	return (
		<div className="be-coordinator-picks">
			<h5>{ __( 'Coordinator approval', 'beyond-elysium' ) }</h5>
			<ul>
				{ picks.map( ( pick ) => (
					<li key={ pick.key }>
						<strong>{ pick.name }</strong>
						{ ` — ${ coordinatorPhrase( pick.signal.coordinators ) }` }
						{ pick.request && open( pick ) && (
							<CoordinatorRequestLine
								gameSlug={ gameSlug }
								request={ pick.request }
								onChanged={ load }
								onSendAgain={ () =>
									setDialog( {
										keys: [ pick.key ],
										purpose:
											pick.request?.purpose ?? 'approval',
									} )
								}
							/>
						) }
						{ pick.request && ! open( pick ) && (
							<p className="be-coordinator-line__status">
								{ requestParts( pick.request ).join( ' · ' ) }
							</p>
						) }
					</li>
				) ) }
			</ul>
			<div className="be-coordinator-choice__buttons">
				{ toApprove.length > 0 && (
					<button
						type="button"
						onClick={ () =>
							setDialog( {
								keys: toApprove.map( ( pick ) => pick.key ),
								purpose: 'approval',
							} )
						}
					>
						{ __( 'Send to the Coordinator', 'beyond-elysium' ) }
					</button>
				) }
				{ toNotify.length > 0 && (
					<button
						type="button"
						onClick={ () =>
							setDialog( {
								keys: toNotify.map( ( pick ) => pick.key ),
								purpose: 'notify',
							} )
						}
					>
						{ __( 'Send them in as a notice', 'beyond-elysium' ) }
					</button>
				) }
			</div>
			<p className="be-coordinator-dialog__hint">
				{ __(
					'The character becomes active when the Coordinator has approved each pick that asks for one. You can also make it active yourself at any time.',
					'beyond-elysium'
				) }
			</p>
			{ dialog && (
				<CoordinatorSendDialog
					gameSlug={ gameSlug }
					status={ status }
					characterId={ characterId }
					characterName={ characterName }
					lines={ picks
						.filter( ( pick ) => dialog.keys.includes( pick.key ) )
						.map( lineForPick ) }
					purpose={ dialog.purpose }
					onClose={ () => setDialog( null ) }
					onSent={ () => {
						setDialog( null );
						load();
					} }
				/>
			) }
		</div>
	);
}
