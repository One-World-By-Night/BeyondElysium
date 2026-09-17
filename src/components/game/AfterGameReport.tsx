/**
 * "After-Game Report" (1.1.0 §3.14, A1) - My Chronicle's own tab for a player to write one
 * report per character per session: what did your character do, what do you want next,
 * anything for staff. Editable until the session's own reports_due_at; a Storyteller reads
 * and marks it read, never edits it.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import HelpButton from '../shared/HelpButton';
import type { Character } from '../../types/character';
import type {
	AfterGameReport as Report,
	GameSession,
} from '../../types/session';
import './AfterGameReport.css';

export interface AfterGameReportProps {
	gameSlug: string;
}

export function AfterGameReport( { gameSlug }: AfterGameReportProps ) {
	const [ sessions, setSessions ] = useState< GameSession[] >( [] );
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ sessionId, setSessionId ] = useState( '' );
	const [ characterId, setCharacterId ] = useState( '' );
	const [ existing, setExisting ] = useState< Report | null >( null );
	const [ did, setDid ] = useState( '' );
	const [ wants, setWants ] = useState( '' );
	const [ toStaff, setToStaff ] = useState( '' );
	const [ closed, setClosed ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		const today = new Date().toISOString().slice( 0, 10 );
		api.sessions( gameSlug )
			.list( { to: today } )
			.then( ( rows ) =>
				setSessions(
					rows
						.slice()
						.sort( ( a, b ) =>
							b.game_date.localeCompare( a.game_date )
						)
				)
			)
			.catch( () => setSessions( [] ) );
		api.characters( gameSlug )
			.myCharacters()
			.then( setCharacters )
			.catch( () => setCharacters( [] ) );
	}, [ gameSlug ] );

	useEffect( () => {
		setExisting( null );
		setDid( '' );
		setWants( '' );
		setToStaff( '' );
		setClosed( false );
		setSaved( false );
		if ( ! sessionId || ! characterId ) {
			return;
		}
		const session = sessions.find( ( s ) => String( s.id ) === sessionId );
		if ( session?.reports_due_at ) {
			setClosed( new Date( session.reports_due_at ) < new Date() );
		}
		api.sessions( gameSlug )
			.getReports( Number( sessionId ) )
			.then( ( reports ) => {
				const mine = reports.find(
					( r ) => String( r.character_id ) === characterId
				);
				if ( mine ) {
					setExisting( mine );
					setDid( mine.did ?? '' );
					setWants( mine.wants ?? '' );
					setToStaff( mine.to_staff ?? '' );
				}
			} )
			.catch( () => {} );
	}, [ gameSlug, sessionId, characterId, sessions ] );

	async function submit( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! sessionId || ! characterId ) {
			return;
		}
		setSaving( true );
		setError( null );
		setSaved( false );
		try {
			const data = {
				character_id: Number( characterId ),
				did,
				wants,
				to_staff: toStaff,
			};
			const result = existing
				? await api
						.sessions( gameSlug )
						.updateReport( Number( sessionId ), data )
				: await api
						.sessions( gameSlug )
						.createReport( Number( sessionId ), data );
			setExisting( result );
			setSaved( true );
		} catch {
			setError( __( 'Failed to save this report.', 'beyond-elysium' ) );
		} finally {
			setSaving( false );
		}
	}

	return (
		<div className="be-after-game-report">
			<div className="be-help-heading">
				<h2>{ __( 'After-Game Report', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="after-game-report" />
			</div>

			<form onSubmit={ submit }>
				<label className="be-after-game-report__field">
					<span>{ __( 'Session', 'beyond-elysium' ) }</span>
					<select
						value={ sessionId }
						onChange={ ( e ) => setSessionId( e.target.value ) }
					>
						<option value="">
							{ __( 'Choose a session…', 'beyond-elysium' ) }
						</option>
						{ sessions.map( ( s ) => (
							<option key={ s.id } value={ s.id }>
								{ s.game_date }
							</option>
						) ) }
					</select>
				</label>

				<label className="be-after-game-report__field">
					<span>{ __( 'Character', 'beyond-elysium' ) }</span>
					<select
						value={ characterId }
						onChange={ ( e ) => setCharacterId( e.target.value ) }
					>
						<option value="">
							{ __( 'Choose a character…', 'beyond-elysium' ) }
						</option>
						{ characters.map( ( c ) => (
							<option key={ c.id } value={ c.id }>
								{ c.name }
							</option>
						) ) }
					</select>
				</label>

				{ sessionId && characterId && (
					<>
						{ closed && (
							<p
								className="be-after-game-report__closed"
								role="alert"
							>
								{ __(
									'Reports for this session are no longer open.',
									'beyond-elysium'
								) }
							</p>
						) }

						<label className="be-after-game-report__field">
							<span>
								{ __(
									'What did your character do?',
									'beyond-elysium'
								) }
							</span>
							<textarea
								value={ did }
								onChange={ ( e ) => setDid( e.target.value ) }
								disabled={ closed }
								rows={ 4 }
							/>
						</label>

						<label className="be-after-game-report__field">
							<span>
								{ __(
									'What do you want next?',
									'beyond-elysium'
								) }
							</span>
							<textarea
								value={ wants }
								onChange={ ( e ) => setWants( e.target.value ) }
								disabled={ closed }
								rows={ 4 }
							/>
						</label>

						<label className="be-after-game-report__field">
							<span>
								{ __(
									'Anything for staff?',
									'beyond-elysium'
								) }
							</span>
							<textarea
								value={ toStaff }
								onChange={ ( e ) =>
									setToStaff( e.target.value )
								}
								disabled={ closed }
								rows={ 3 }
							/>
						</label>

						{ error && (
							<p
								className="be-after-game-report__error"
								role="alert"
							>
								{ error }
							</p>
						) }
						{ saved && (
							<p
								className="be-after-game-report__saved"
								role="status"
							>
								{ __( 'Saved.', 'beyond-elysium' ) }
							</p>
						) }

						<button type="submit" disabled={ saving || closed }>
							{ existing
								? __( 'Save Changes', 'beyond-elysium' )
								: __( 'Submit Report', 'beyond-elysium' ) }
						</button>
					</>
				) }
			</form>
		</div>
	);
}

export default AfterGameReport;
