/**
 * A game session's own sign-in roster.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { everyPage } from '../../lib/everyPage';
import type { Character } from '../../types/character';
import type { SessionAttendance } from '../../types/session';
import './SignInRoster.css';

export interface SignInRosterProps {
	gameSlug: string;
	sessionId: number;
}

export function SignInRoster( { gameSlug, sessionId }: SignInRosterProps ) {
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ attendance, setAttendance ] = useState< SessionAttendance[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ visitorName, setVisitorName ] = useState( '' );
	const [ visitorChronicle, setVisitorChronicle ] = useState( '' );
	const [ busyCharacterId, setBusyCharacterId ] = useState< number | null >(
		null
	);

	function load() {
		setLoading( true );
		setError( null );
		Promise.all( [
			everyPage< Character >( ( page ) =>
				api.characters( gameSlug ).listPaginated( {
					status: 'active',
					is_npc: false,
					page,
					per_page: 100,
				} )
			),
			api.sessions( gameSlug ).getAttendance( sessionId ),
		] )
			.then( ( [ chars, rows ] ) => {
				setCharacters( chars );
				setAttendance( rows );
				setLoading( false );
			} )
			.catch( () => {
				setError(
					__( 'Failed to load the sign-in roster.', 'beyond-elysium' )
				);
				setLoading( false );
			} );
	}

	useEffect( load, [ gameSlug, sessionId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	function attendanceFor(
		characterId: number
	): SessionAttendance | undefined {
		return attendance.find( ( a ) => a.character_id === characterId );
	}

	async function toggle( character: Character ) {
		const existing = attendanceFor( character.id );
		setBusyCharacterId( character.id );
		setError( null );
		try {
			if ( existing ) {
				await api
					.sessions( gameSlug )
					.removeAttendance( sessionId, existing.id );
			} else {
				await api
					.sessions( gameSlug )
					.addAttendance( sessionId, { character_id: character.id } );
			}
			load();
		} catch {
			setError(
				__( 'Failed to update this sign-in.', 'beyond-elysium' )
			);
		} finally {
			setBusyCharacterId( null );
		}
	}

	async function addVisitor( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! visitorName.trim() ) {
			return;
		}
		setError( null );
		try {
			await api.sessions( gameSlug ).addAttendance( sessionId, {
				visitor_name: visitorName.trim(),
				visitor_chronicle: visitorChronicle.trim() || undefined,
			} );
			setVisitorName( '' );
			setVisitorChronicle( '' );
			load();
		} catch {
			setError( __( 'Failed to add this visitor.', 'beyond-elysium' ) );
		}
	}

	async function removeVisitor( id: number ) {
		setError( null );
		try {
			await api.sessions( gameSlug ).removeAttendance( sessionId, id );
			load();
		} catch {
			setError(
				__( 'Failed to remove this visitor.', 'beyond-elysium' )
			);
		}
	}

	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	const visitors = attendance.filter( ( a ) => a.character_id === null );
	const presentCount = attendance.length;

	return (
		<div className="be-sign-in-roster">
			{ error && (
				<div className="be-sign-in-roster__error" role="alert">
					{ error }
				</div>
			) }

			<p className="be-sign-in-roster__count">
				{ sprintf(
					/* translators: %d: how many characters and visitors are currently signed in */
					__( '%d signed in', 'beyond-elysium' ),
					presentCount
				) }
			</p>

			<ul className="be-sign-in-roster__list">
				{ characters.map( ( character ) => {
					const present = !! attendanceFor( character.id );
					return (
						<li key={ character.id }>
							<label>
								<input
									type="checkbox"
									checked={ present }
									disabled={
										busyCharacterId === character.id
									}
									onChange={ () => toggle( character ) }
								/>
								{ character.name }
								{ character.player_name && (
									<span className="be-sign-in-roster__player">
										{ ' ' }
										({ character.player_name })
									</span>
								) }
							</label>
						</li>
					);
				} ) }
			</ul>

			{ visitors.length > 0 && (
				<>
					<h4>{ __( 'Visitors', 'beyond-elysium' ) }</h4>
					<ul className="be-sign-in-roster__list">
						{ visitors.map( ( visitor ) => (
							<li key={ visitor.id }>
								{ visitor.visitor_name }
								{ visitor.visitor_chronicle && (
									<span className="be-sign-in-roster__player">
										{ ' ' }
										({ visitor.visitor_chronicle })
									</span>
								) }
								<button
									type="button"
									className="be-st-button be-st-button--quiet"
									onClick={ () =>
										removeVisitor( visitor.id )
									}
								>
									{ __( 'Remove', 'beyond-elysium' ) }
								</button>
							</li>
						) ) }
					</ul>
				</>
			) }

			<form
				className="be-sign-in-roster__add-visitor"
				onSubmit={ addVisitor }
			>
				<input
					type="text"
					placeholder={ __( 'Visitor name…', 'beyond-elysium' ) }
					value={ visitorName }
					onChange={ ( e ) => setVisitorName( e.target.value ) }
				/>
				<input
					type="text"
					placeholder={ __(
						'Home chronicle (optional)…',
						'beyond-elysium'
					) }
					value={ visitorChronicle }
					onChange={ ( e ) => setVisitorChronicle( e.target.value ) }
				/>
				<button
					type="submit"
					className="be-st-button be-st-button--quiet"
					disabled={ ! visitorName.trim() }
				>
					{ __( '+ Add visitor', 'beyond-elysium' ) }
				</button>
			</form>
		</div>
	);
}

export default SignInRoster;
