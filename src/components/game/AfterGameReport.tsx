/**
 * "After-Game Report" (1.1.0 §3.14, A1) - My Chronicle's own tab for a player to write one
 * report per character per session: what did your character do, what do you want next,
 * anything for staff. Editable until the session's own reports_due_at; a Storyteller reads
 * and marks it read, never edits it.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import HelpButton from '../shared/HelpButton';
import HtmlEditor from '../shared/HtmlEditor';
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
	const didDraft = useRef( '' );
	const wantsDraft = useRef( '' );
	const toStaffDraft = useRef( '' );
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
		didDraft.current = '';
		wantsDraft.current = '';
		toStaffDraft.current = '';
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
					didDraft.current = mine.did ?? '';
					wantsDraft.current = mine.wants ?? '';
					toStaffDraft.current = mine.to_staff ?? '';
					// HtmlEditor is uncontrolled - setting the drafts alone would never
					// reach an already-mounted TinyMCE instance, so this re-key (below)
					// depends on `existing` to force a fresh mount with the real content.
					setExisting( mine );
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
				did: didDraft.current,
				wants: wantsDraft.current,
				to_staff: toStaffDraft.current,
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

	// HtmlEditor is uncontrolled (TinyMCE owns the DOM after mount) - a fresh key forces
	// a real remount, the only way to load newly-fetched content into it. Changes on every
	// session/character switch, and once more when an existing report's real content
	// arrives asynchronously (the 'new'-to-real-id transition inside the effect above).
	const editorKey = `${ sessionId || 'none' }-${ characterId || 'none' }-${
		existing?.id ?? 'new'
	}`;

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

						<div className="be-after-game-report__field">
							<span>
								{ __(
									'What did your character do?',
									'beyond-elysium'
								) }
							</span>
							<HtmlEditor
								id={ `be-agr-did-${ editorKey }` }
								defaultValue={ didDraft.current }
								readOnly={ closed }
								rows={ 4 }
								onChange={ ( html ) => {
									didDraft.current = html;
								} }
							/>
						</div>

						<div className="be-after-game-report__field">
							<span>
								{ __(
									'What do you want next?',
									'beyond-elysium'
								) }
							</span>
							<HtmlEditor
								id={ `be-agr-wants-${ editorKey }` }
								defaultValue={ wantsDraft.current }
								readOnly={ closed }
								rows={ 4 }
								onChange={ ( html ) => {
									wantsDraft.current = html;
								} }
							/>
						</div>

						<div className="be-after-game-report__field">
							<span>
								{ __(
									'Anything for staff?',
									'beyond-elysium'
								) }
							</span>
							<HtmlEditor
								id={ `be-agr-to-staff-${ editorKey }` }
								defaultValue={ toStaffDraft.current }
								readOnly={ closed }
								rows={ 3 }
								onChange={ ( html ) => {
									toStaffDraft.current = html;
								} }
							/>
						</div>

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
