/**
 * Storyteller Toolkit: game nights (1.1.0 §3.1) - the calendar of sessions, a form to
 * schedule one, and, per session, the sign-in roster and awarding attendance XP. Mounted
 * from both the front-end toolkit page and the wp-admin Game Nights screen.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { canIn } from '../../lib/chronicleCapabilities';
import { errorMessage } from '../../lib/errorMessage';
import type { MyCapabilities } from '../../types';
import type {
	GameSession,
	AfterGameReport,
	SpotlightRow,
} from '../../types/session';
import type { NpcProfile } from '../../types/character';
import type { NpcCasting, EligibleMember } from '../../types/npcCasting';
import { SignInRoster } from './SignInRoster';
import HelpButton from '../shared/HelpButton';
import './GameNights.css';

export interface GameNightsProps {
	gameSlug: string;
	capabilities?: MyCapabilities;
}

/** A MySQL datetime ("2026-10-02 17:00:00") to a <input type="datetime-local"> value. */
function toDatetimeLocalValue( mysql: string | null | undefined ): string {
	return mysql ? mysql.replace( ' ', 'T' ).slice( 0, 16 ) : '';
}

/** The reverse of toDatetimeLocalValue() - back to a MySQL datetime. */
function toMysqlDatetime( localValue: string ): string {
	return localValue.length === 16
		? `${ localValue.replace( 'T', ' ' ) }:00`
		: localValue.replace( 'T', ' ' );
}

export function GameNights( { gameSlug, capabilities }: GameNightsProps ) {
	const canManage = canIn( 'be_manage_sessions', capabilities );
	const canManageCharacters = canIn( 'be_manage_characters', capabilities );
	const canManageApr = canIn( 'be_manage_apr', capabilities );

	const [ sessions, setSessions ] = useState< GameSession[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ selected, setSelected ] = useState< GameSession | null >( null );

	const [ showNewForm, setShowNewForm ] = useState( false );
	const [ newDate, setNewDate ] = useState( '' );
	const [ newTime, setNewTime ] = useState( '' );
	const [ newPlace, setNewPlace ] = useState( '' );
	const [ creating, setCreating ] = useState( false );
	const [ createError, setCreateError ] = useState< string | null >( null );

	const [ awarding, setAwarding ] = useState( false );
	const [ awardMessage, setAwardMessage ] = useState< string | null >( null );

	// Downtime window (1.1.0 §3.3) - opens/deadline are per session, be_manage_apr only.
	const [ opensAt, setOpensAt ] = useState( '' );
	const [ deadlineAt, setDeadlineAt ] = useState( '' );
	const [ savingWindow, setSavingWindow ] = useState( false );
	const [ windowError, setWindowError ] = useState< string | null >( null );

	// Cast NPCs (1.1.0 §3.8) - who plays which NPC at the selected session.
	const [ castings, setCastings ] = useState< NpcCasting[] >( [] );
	const [ npcs, setNpcs ] = useState< NpcProfile[] >( [] );
	const [ members, setMembers ] = useState< EligibleMember[] >( [] );
	const [ castNpcId, setCastNpcId ] = useState( '' );
	const [ castMemberId, setCastMemberId ] = useState( '' );
	const [ castBrief, setCastBrief ] = useState( '' );
	const [ casting, setCasting ] = useState( false );
	const [ castError, setCastError ] = useState< string | null >( null );

	const [ settingsOpen, setSettingsOpen ] = useState( false );
	const [ attendanceXp, setAttendanceXp ] = useState( 1 );
	const [ reportXp, setReportXp ] = useState( 0 );
	// D54: must match Spotlight::DEFAULT_SPOTLIGHT_DAYS - this panel showed 14 while the
	// server actually enforced 42 until a save happened, so saving unchanged silently cut
	// the real window.
	const [ spotlightDays, setSpotlightDays ] = useState( 42 );
	const [ savingSettings, setSavingSettings ] = useState( false );

	// After-game reports (1.1.0 §3.14, A1) - read state and awarding report XP per session.
	const [ reports, setReports ] = useState< AfterGameReport[] >( [] );
	const [ awardingReportXp, setAwardingReportXp ] = useState( false );
	const [ awardReportMessage, setAwardReportMessage ] = useState<
		string | null
	>( null );

	// Spotlight (1.1.0 §3.14, A2) - chronicle-wide, so it lives alongside the session list
	// rather than inside a single session's own detail view.
	const [ spotlightOpen, setSpotlightOpen ] = useState( false );
	const [ spotlightRows, setSpotlightRows ] = useState< SpotlightRow[] >(
		[]
	);
	const [ spotlightError, setSpotlightError ] = useState< string | null >(
		null
	);

	function load() {
		setLoading( true );
		setError( null );
		api.sessions( gameSlug )
			.list()
			.then( ( result ) => {
				setSessions( result );
				setLoading( false );
			} )
			.catch( () => {
				setError(
					__( 'Failed to load game sessions.', 'beyond-elysium' )
				);
				setLoading( false );
			} );
	}

	useEffect( load, [ gameSlug ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		setOpensAt( toDatetimeLocalValue( selected?.downtime_opens_at ) );
		setDeadlineAt( toDatetimeLocalValue( selected?.downtime_deadline_at ) );
		setWindowError( null );
	}, [ selected ] );

	async function saveWindow() {
		if ( ! selected ) {
			return;
		}
		setSavingWindow( true );
		setWindowError( null );
		try {
			const updated = await api
				.sessions( gameSlug )
				.update( selected.id, {
					downtime_opens_at: opensAt
						? toMysqlDatetime( opensAt )
						: undefined,
					downtime_deadline_at: deadlineAt
						? toMysqlDatetime( deadlineAt )
						: undefined,
				} );
			setSelected( updated );
			load();
		} catch ( err ) {
			setWindowError(
				errorMessage(
					err,
					__(
						'Failed to save the downtime window.',
						'beyond-elysium'
					)
				)
			);
		} finally {
			setSavingWindow( false );
		}
	}

	function loadCastings() {
		if ( ! selected ) {
			return;
		}
		api.castings( gameSlug )
			.list( selected.id )
			.then( setCastings )
			.catch( () => setCastings( [] ) );
	}

	useEffect( () => {
		setCastError( null );
		if ( ! canManageCharacters || ! selected ) {
			return;
		}
		loadCastings();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ selected, canManageCharacters ] );

	useEffect( () => {
		if ( ! canManageCharacters ) {
			return;
		}
		api.npcs( gameSlug )
			.list()
			.then( setNpcs )
			.catch( () => setNpcs( [] ) );
		api.castings( gameSlug )
			.eligibleMembers()
			.then( setMembers )
			.catch( () => setMembers( [] ) );
	}, [ gameSlug, canManageCharacters ] );

	async function castNpc() {
		if ( ! selected || ! castNpcId || ! castMemberId ) {
			return;
		}
		setCasting( true );
		setCastError( null );
		try {
			await api.castings( gameSlug ).create( {
				session_id: selected.id,
				character_id: Number( castNpcId ),
				wp_user_id: Number( castMemberId ),
				brief: castBrief || undefined,
			} );
			setCastNpcId( '' );
			setCastMemberId( '' );
			setCastBrief( '' );
			loadCastings();
		} catch ( err ) {
			setCastError(
				errorMessage(
					err,
					__( 'Failed to cast this NPC.', 'beyond-elysium' )
				)
			);
		} finally {
			setCasting( false );
		}
	}

	async function removeCasting( id: number ) {
		try {
			await api.castings( gameSlug ).remove( id );
			loadCastings();
		} catch ( err ) {
			setCastError(
				errorMessage(
					err,
					__( 'Failed to remove this casting.', 'beyond-elysium' )
				)
			);
		}
	}

	useEffect( () => {
		if ( ! canManageCharacters ) {
			return;
		}
		api.games
			.get( gameSlug )
			.then( ( game ) => {
				const settings = game.settings as {
					sessions?: {
						attendance_xp?: number;
						report_xp?: number;
						spotlight_days?: number;
					};
				} | null;
				setAttendanceXp( settings?.sessions?.attendance_xp ?? 1 );
				setReportXp( settings?.sessions?.report_xp ?? 0 );
				setSpotlightDays( settings?.sessions?.spotlight_days ?? 42 );
			} )
			.catch( () => undefined );
	}, [ gameSlug, canManageCharacters ] );

	function loadReports() {
		if ( ! selected || ! canManageCharacters ) {
			return;
		}
		api.sessions( gameSlug )
			.getReports( selected.id )
			.then( setReports )
			.catch( () => setReports( [] ) );
	}

	useEffect( () => {
		loadReports();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ selected, canManageCharacters ] );

	if ( ! canManage ) {
		return (
			<div className="be-game-nights">
				<div className="be-game-nights__denied">
					<h2>{ __( 'Storytellers only', 'beyond-elysium' ) }</h2>
					<p>
						{ __(
							'Game night scheduling and sign-in are run by your Storytellers and Narrators.',
							'beyond-elysium'
						) }
					</p>
				</div>
			</div>
		);
	}

	async function createSession( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! newDate ) {
			return;
		}
		setCreating( true );
		setCreateError( null );
		try {
			const session = await api.sessions( gameSlug ).create( {
				game_date: newDate,
				start_time: newTime || undefined,
				place: newPlace || undefined,
			} );
			setNewDate( '' );
			setNewTime( '' );
			setNewPlace( '' );
			setShowNewForm( false );
			load();
			setSelected( session );
		} catch ( err ) {
			setCreateError(
				errorMessage(
					err,
					__( 'Failed to create this session.', 'beyond-elysium' )
				)
			);
		} finally {
			setCreating( false );
		}
	}

	async function deleteSession( session: GameSession ) {
		try {
			await api.sessions( gameSlug ).delete( session.id );
			setSelected( null );
			load();
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Failed to delete this session.', 'beyond-elysium' )
				)
			);
		}
	}

	async function awardXp( session: GameSession, force = false ) {
		setAwarding( true );
		setAwardMessage( null );
		try {
			const result = await api
				.sessions( gameSlug )
				.awardAttendanceXp( session.id, force ? { force: true } : {} );
			setAwardMessage(
				`${ result.awarded_count } characters awarded ${ result.amount } XP.`
			);
			load();
		} catch ( err ) {
			setAwardMessage(
				errorMessage(
					err,
					__( 'Failed to award attendance XP.', 'beyond-elysium' )
				)
			);
		} finally {
			setAwarding( false );
		}
	}

	async function markReportRead( reportId: number ) {
		try {
			await api.sessions( gameSlug ).markReportRead( reportId );
			loadReports();
		} catch {
			// The report row's own "Mark read" button simply stays clickable again.
		}
	}

	async function awardReportXpNow( session: GameSession, force = false ) {
		setAwardingReportXp( true );
		setAwardReportMessage( null );
		try {
			const result = await api
				.sessions( gameSlug )
				.awardReportXp( session.id, force ? { force: true } : {} );
			setAwardReportMessage(
				`${ result.awarded_count } characters awarded ${ result.amount } XP.`
			);
			load();
		} catch ( err ) {
			setAwardReportMessage(
				errorMessage(
					err,
					__( 'Failed to award report XP.', 'beyond-elysium' )
				)
			);
		} finally {
			setAwardingReportXp( false );
		}
	}

	function toggleSpotlight() {
		const opening = ! spotlightOpen;
		setSpotlightOpen( opening );
		if ( opening ) {
			setSpotlightError( null );
			api.sessions( gameSlug )
				.getSpotlight()
				.then( setSpotlightRows )
				.catch( () =>
					setSpotlightError(
						__(
							'Failed to load the spotlight check.',
							'beyond-elysium'
						)
					)
				);
		}
	}

	async function saveSettings() {
		setSavingSettings( true );
		try {
			await api.sessions( gameSlug ).updateSettings( {
				attendance_xp: attendanceXp,
				report_xp: reportXp,
				spotlight_days: spotlightDays,
			} );
		} finally {
			setSavingSettings( false );
		}
	}

	return (
		<div className="be-game-nights">
			<header className="be-game-nights__header">
				<div className="be-help-heading">
					<h2>{ __( 'Game Nights', 'beyond-elysium' ) }</h2>
					<HelpButton helpKey="game-nights" />
				</div>
				{ selected && (
					<nav className="be-game-nights__crumbs">
						<button
							type="button"
							onClick={ () => setSelected( null ) }
						>
							{ __( 'All sessions', 'beyond-elysium' ) }
						</button>
						<span>/</span>
						<span>{ selected.game_date }</span>
					</nav>
				) }
			</header>

			{ error && (
				<div className="be-game-nights__error" role="alert">
					{ error }
				</div>
			) }

			{ ! selected && (
				<>
					{ canManageCharacters && (
						<div className="be-game-nights__settings">
							<button
								type="button"
								className="be-st-button be-st-button--quiet"
								onClick={ toggleSpotlight }
								aria-expanded={ spotlightOpen }
							>
								{ __( 'Spotlight', 'beyond-elysium' ) }
							</button>
							{ spotlightOpen && (
								<div className="be-game-nights__spotlight">
									{ spotlightError && (
										<div
											className="be-game-nights__error"
											role="alert"
										>
											{ spotlightError }
										</div>
									) }
									{ spotlightRows.length === 0 &&
									! spotlightError ? (
										<p>
											{ __(
												'No active characters yet.',
												'beyond-elysium'
											) }
										</p>
									) : (
										<ul className="be-game-nights__spotlight-list">
											{ spotlightRows.map( ( row ) => (
												<li
													key={ row.character_id }
													className={
														row.flagged
															? 'be-game-nights__spotlight-row--flagged'
															: ''
													}
												>
													<strong>
														{ row.name }
													</strong>
													{ row.flagged && (
														<span className="be-st-badge">
															{ __(
																'Flagged',
																'beyond-elysium'
															) }
														</span>
													) }
													<span>
														{ __(
															'Last attended:',
															'beyond-elysium'
														) }{ ' ' }
														{ row.last_attended ??
															__(
																'never',
																'beyond-elysium'
															) }
													</span>
													<span>
														{ __(
															'Active plots:',
															'beyond-elysium'
														) }{ ' ' }
														{ row.active_plots }
													</span>
													<span>
														{ __(
															'Last staff post:',
															'beyond-elysium'
														) }{ ' ' }
														{ row.last_staff_post_at ??
															__(
																'never',
																'beyond-elysium'
															) }
													</span>
												</li>
											) ) }
										</ul>
									) }
								</div>
							) }
						</div>
					) }

					{ canManageCharacters && (
						<div className="be-game-nights__settings">
							<button
								type="button"
								className="be-st-button be-st-button--quiet"
								onClick={ () =>
									setSettingsOpen( ( o ) => ! o )
								}
							>
								{ __( 'Session settings', 'beyond-elysium' ) }
							</button>
							{ settingsOpen && (
								<div className="be-game-nights__settings-card">
									<label>
										{ __(
											'Attendance XP',
											'beyond-elysium'
										) }
										<input
											type="number"
											min={ 0 }
											value={ attendanceXp }
											onChange={ ( e ) =>
												setAttendanceXp(
													Number( e.target.value )
												)
											}
										/>
									</label>
									<label>
										{ __( 'Report XP', 'beyond-elysium' ) }
										<input
											type="number"
											min={ 0 }
											value={ reportXp }
											onChange={ ( e ) =>
												setReportXp(
													Number( e.target.value )
												)
											}
										/>
									</label>
									<label>
										{ __(
											'Spotlight days',
											'beyond-elysium'
										) }
										<input
											type="number"
											min={ 1 }
											value={ spotlightDays }
											onChange={ ( e ) =>
												setSpotlightDays(
													Number( e.target.value )
												)
											}
										/>
									</label>
									<button
										type="button"
										className="be-st-button"
										disabled={ savingSettings }
										onClick={ saveSettings }
									>
										{ __(
											'Save settings',
											'beyond-elysium'
										) }
									</button>
								</div>
							) }
						</div>
					) }

					{ showNewForm ? (
						<form
							className="be-game-nights__new-form"
							onSubmit={ createSession }
						>
							<input
								type="date"
								value={ newDate }
								onChange={ ( e ) =>
									setNewDate( e.target.value )
								}
								aria-label={ __(
									'Game date',
									'beyond-elysium'
								) }
								required
							/>
							<input
								type="text"
								placeholder={ __(
									'Start time…',
									'beyond-elysium'
								) }
								value={ newTime }
								onChange={ ( e ) =>
									setNewTime( e.target.value )
								}
							/>
							<input
								type="text"
								placeholder={ __( 'Place…', 'beyond-elysium' ) }
								value={ newPlace }
								onChange={ ( e ) =>
									setNewPlace( e.target.value )
								}
							/>
							{ createError && (
								<div
									className="be-game-nights__error"
									role="alert"
								>
									{ createError }
								</div>
							) }
							<div className="be-game-nights__new-form-actions">
								<button
									type="submit"
									className="be-st-button"
									disabled={ creating || ! newDate }
								>
									{ __( 'Create session', 'beyond-elysium' ) }
								</button>
								<button
									type="button"
									className="be-st-button be-st-button--quiet"
									onClick={ () => setShowNewForm( false ) }
								>
									{ __( 'Cancel', 'beyond-elysium' ) }
								</button>
							</div>
						</form>
					) : (
						<button
							type="button"
							className="be-st-button"
							onClick={ () => setShowNewForm( true ) }
						>
							{ __( '+ New session', 'beyond-elysium' ) }
						</button>
					) }

					{ loading ? (
						<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
					) : sessions.length === 0 ? (
						<p>
							{ __(
								'No sessions scheduled yet.',
								'beyond-elysium'
							) }
						</p>
					) : (
						<ul className="be-game-nights__list">
							{ sessions.map( ( session ) => (
								<li key={ session.id }>
									<button
										type="button"
										className="be-game-nights__session-card"
										onClick={ () => setSelected( session ) }
									>
										<strong>{ session.game_date }</strong>
										{ session.start_time && (
											<span>{ session.start_time }</span>
										) }
										{ session.place && (
											<span>{ session.place }</span>
										) }
										{ session.attendance_xp_awarded_at && (
											<span className="be-st-badge">
												{ __(
													'XP awarded',
													'beyond-elysium'
												) }
											</span>
										) }
										{ session.report_xp_awarded_at && (
											<span className="be-st-badge">
												{ __(
													'Report XP awarded',
													'beyond-elysium'
												) }
											</span>
										) }
									</button>
								</li>
							) ) }
						</ul>
					) }
				</>
			) }

			{ selected && (
				<div className="be-game-nights__detail">
					<h3>{ __( 'Sign-in', 'beyond-elysium' ) }</h3>
					<SignInRoster
						gameSlug={ gameSlug }
						sessionId={ selected.id }
					/>

					<h3>{ __( 'Attendance XP', 'beyond-elysium' ) }</h3>
					{ awardMessage && <p>{ awardMessage }</p> }
					<div className="be-game-nights__actions">
						<button
							type="button"
							className="be-st-button"
							disabled={
								awarding || !! selected.attendance_xp_awarded_at
							}
							onClick={ () => awardXp( selected ) }
						>
							{ selected.attendance_xp_awarded_at
								? __( 'XP already awarded', 'beyond-elysium' )
								: __(
										'Award attendance XP',
										'beyond-elysium'
								  ) }
						</button>
						{ selected.attendance_xp_awarded_at && (
							<button
								type="button"
								className="be-st-button be-st-button--quiet"
								disabled={ awarding }
								onClick={ () => awardXp( selected, true ) }
							>
								{ __( 'Award again anyway', 'beyond-elysium' ) }
							</button>
						) }
						<button
							type="button"
							className="be-st-button be-st-button--quiet"
							onClick={ () => deleteSession( selected ) }
						>
							{ __( 'Delete session', 'beyond-elysium' ) }
						</button>
					</div>

					{ canManageCharacters && (
						<>
							<h3>{ __( 'Reports', 'beyond-elysium' ) }</h3>
							{ reports.length === 0 ? (
								<p>
									{ __(
										'No after-game reports filed yet.',
										'beyond-elysium'
									) }
								</p>
							) : (
								<ul className="be-game-nights__list">
									{ reports.map( ( report ) => (
										<li key={ report.id }>
											<span>
												{ __(
													'Character #',
													'beyond-elysium'
												) }
												{ report.character_id }
											</span>
											{ report.read_at ? (
												<span className="be-st-badge">
													{ __(
														'Read',
														'beyond-elysium'
													) }
												</span>
											) : (
												<button
													type="button"
													className="be-st-button be-st-button--quiet"
													onClick={ () =>
														markReportRead(
															report.id
														)
													}
												>
													{ __(
														'Mark read',
														'beyond-elysium'
													) }
												</button>
											) }
										</li>
									) ) }
								</ul>
							) }
							{ awardReportMessage && (
								<p>{ awardReportMessage }</p>
							) }
							<div className="be-game-nights__actions">
								<button
									type="button"
									className="be-st-button"
									disabled={
										awardingReportXp ||
										!! selected.report_xp_awarded_at
									}
									onClick={ () =>
										awardReportXpNow( selected )
									}
								>
									{ selected.report_xp_awarded_at
										? __(
												'Report XP already awarded',
												'beyond-elysium'
										  )
										: __(
												'Award report XP',
												'beyond-elysium'
										  ) }
								</button>
								{ selected.report_xp_awarded_at && (
									<button
										type="button"
										className="be-st-button be-st-button--quiet"
										disabled={ awardingReportXp }
										onClick={ () =>
											awardReportXpNow( selected, true )
										}
									>
										{ __(
											'Award again anyway',
											'beyond-elysium'
										) }
									</button>
								) }
							</div>
						</>
					) }

					{ canManageApr && (
						<>
							<h3>
								{ __( 'Downtime window', 'beyond-elysium' ) }
							</h3>
							<p className="be-game-nights__apr-note">
								{ __(
									'Leave either field blank for no window at all - today’s behavior for a date with neither set. See Downtime.',
									'beyond-elysium'
								) }
							</p>
							{ windowError && (
								<div
									className="be-game-nights__error"
									role="alert"
								>
									{ windowError }
								</div>
							) }
							<div className="be-game-nights__window-form">
								<label>
									{ __( 'Opens at', 'beyond-elysium' ) }
									<input
										type="datetime-local"
										value={ opensAt }
										onChange={ ( e ) =>
											setOpensAt( e.target.value )
										}
									/>
								</label>
								<label>
									{ __( 'Deadline', 'beyond-elysium' ) }
									<input
										type="datetime-local"
										value={ deadlineAt }
										onChange={ ( e ) =>
											setDeadlineAt( e.target.value )
										}
									/>
								</label>
								<button
									type="button"
									className="be-st-button"
									disabled={ savingWindow }
									onClick={ saveWindow }
								>
									{ __( 'Save window', 'beyond-elysium' ) }
								</button>
							</div>
						</>
					) }

					{ canManageCharacters && (
						<>
							<h3>{ __( 'Cast NPCs', 'beyond-elysium' ) }</h3>
							{ castError && (
								<div
									className="be-game-nights__error"
									role="alert"
								>
									{ castError }
								</div>
							) }
							{ castings.length === 0 ? (
								<p>
									{ __(
										'No NPCs are cast for this session yet.',
										'beyond-elysium'
									) }
								</p>
							) : (
								<ul className="be-game-nights__list">
									{ castings.map( ( c ) => (
										<li key={ c.id }>
											<strong>
												{ npcs.find(
													( n ) =>
														n.id === c.character_id
												)?.name ??
													`#${ c.character_id }` }
											</strong>
											{ ' — ' }
											{ members.find(
												( m ) => m.id === c.wp_user_id
											)?.name ?? `#${ c.wp_user_id }` }
											<a
												href={ api
													.castings( gameSlug )
													.briefPdfUrl( c.id ) }
												target="_blank"
												rel="noreferrer"
											>
												{ __(
													'Print brief',
													'beyond-elysium'
												) }
											</a>
											<button
												type="button"
												className="be-st-button be-st-button--quiet"
												onClick={ () =>
													removeCasting( c.id )
												}
											>
												{ __(
													'Remove',
													'beyond-elysium'
												) }
											</button>
										</li>
									) ) }
								</ul>
							) }
							<div className="be-game-nights__cast-form">
								<select
									value={ castNpcId }
									onChange={ ( e ) =>
										setCastNpcId( e.target.value )
									}
									aria-label={ __(
										'NPC to cast',
										'beyond-elysium'
									) }
								>
									<option value="">
										{ __(
											'Choose an NPC…',
											'beyond-elysium'
										) }
									</option>
									{ npcs.map( ( n ) => (
										<option key={ n.id } value={ n.id }>
											{ n.name }
										</option>
									) ) }
								</select>
								<select
									value={ castMemberId }
									onChange={ ( e ) =>
										setCastMemberId( e.target.value )
									}
									aria-label={ __(
										'Member to cast',
										'beyond-elysium'
									) }
								>
									<option value="">
										{ __(
											'Choose a member…',
											'beyond-elysium'
										) }
									</option>
									{ members.map( ( m ) => (
										<option key={ m.id } value={ m.id }>
											{ m.name }
										</option>
									) ) }
								</select>
								<textarea
									placeholder={ __(
										'Brief for this game (optional)…',
										'beyond-elysium'
									) }
									value={ castBrief }
									onChange={ ( e ) =>
										setCastBrief( e.target.value )
									}
								/>
								<button
									type="button"
									className="be-st-button"
									disabled={
										casting || ! castNpcId || ! castMemberId
									}
									onClick={ castNpc }
								>
									{ __( 'Cast', 'beyond-elysium' ) }
								</button>
							</div>
						</>
					) }
				</div>
			) }
		</div>
	);
}

export default GameNights;
