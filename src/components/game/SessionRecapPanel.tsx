/**
 * A Storyteller-only recap of one game night: key events, player decisions, each NPC's status, a cliffhanger,
 * and prep for next time - plus a button to draft it from that session's own attendance and after-game reports.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { errorMessage } from '../../lib/errorMessage';
import Modal from '../shared/Modal';
import HelpButton from '../shared/HelpButton';
import { AiDemoModal } from '../shared/AiDemoNotice';
import { useIsDemo } from '../shared/useIsDemo';
import type { GameSession } from '../../types/session';
import '../shared/AiAssistButton.css';
import './SessionRecapPanel.css';

export type NpcStatus = 'alive' | 'injured' | 'dead' | 'unknown';

export interface NpcInvolved {
	name: string;
	status: NpcStatus;
}

export interface Recap {
	key_events: string;
	player_decisions: string;
	npcs_involved: NpcInvolved[];
	cliffhanger: string;
	prep: string;
}

export interface SessionRecapPanelProps {
	gameSlug: string;
	session: GameSession;
	onSaved: ( updated: GameSession ) => void;
}

/**
 * Whether any one of a recap's five fields is still empty.
 */
export function hasEmptyRecapField( recap: Recap ): boolean {
	return (
		recap.key_events.trim() === '' ||
		recap.player_decisions.trim() === '' ||
		recap.npcs_involved.length === 0 ||
		recap.cliffhanger.trim() === '' ||
		recap.prep.trim() === ''
	);
}

/**
 * Fills in only the fields `current` has nothing in yet, from whatever `drafted` answered for them. A field
 * `current` already has something in never changes, even if the draft disagrees with it.
 */
export function mergeDraftedRecap( current: Recap, drafted: Recap ): Recap {
	return {
		key_events:
			current.key_events.trim() === '' && drafted.key_events.trim() !== ''
				? drafted.key_events
				: current.key_events,
		player_decisions:
			current.player_decisions.trim() === '' &&
			drafted.player_decisions.trim() !== ''
				? drafted.player_decisions
				: current.player_decisions,
		npcs_involved:
			current.npcs_involved.length === 0 &&
			drafted.npcs_involved.length > 0
				? drafted.npcs_involved
				: current.npcs_involved,
		cliffhanger:
			current.cliffhanger.trim() === '' &&
			drafted.cliffhanger.trim() !== ''
				? drafted.cliffhanger
				: current.cliffhanger,
		prep:
			current.prep.trim() === '' && drafted.prep.trim() !== ''
				? drafted.prep
				: current.prep,
	};
}

const STATUSES: NpcStatus[] = [ 'alive', 'injured', 'dead', 'unknown' ];

function statusLabel( status: NpcStatus ): string {
	switch ( status ) {
		case 'alive':
			return __( 'Alive', 'beyond-elysium' );
		case 'injured':
			return __( 'Injured', 'beyond-elysium' );
		case 'dead':
			return __( 'Dead', 'beyond-elysium' );
		default:
			return __( 'Unknown', 'beyond-elysium' );
	}
}

export function SessionRecapPanel( {
	gameSlug,
	session,
	onSaved,
}: SessionRecapPanelProps ) {
	const [ keyEvents, setKeyEvents ] = useState( '' );
	const [ playerDecisions, setPlayerDecisions ] = useState( '' );
	const [ npcsInvolved, setNpcsInvolved ] = useState< NpcInvolved[] >( [] );
	const [ cliffhanger, setCliffhanger ] = useState( '' );
	const [ prep, setPrep ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const [ isOpen, setIsOpen ] = useState( false );
	const [ drafting, setDrafting ] = useState( false );
	const [ draftError, setDraftError ] = useState< string | null >( null );
	const [ drafted, setDrafted ] = useState< Recap | null >( null );
	const isDemo = useIsDemo( gameSlug );

	useEffect( () => {
		setKeyEvents( session.recap?.key_events ?? '' );
		setPlayerDecisions( session.recap?.player_decisions ?? '' );
		setNpcsInvolved( session.recap?.npcs_involved ?? [] );
		setCliffhanger( session.recap?.cliffhanger ?? '' );
		setPrep( session.recap?.prep ?? '' );
		setSaved( false );
		setError( null );
	}, [ session ] );

	const hasEmptyField = hasEmptyRecapField( {
		key_events: keyEvents,
		player_decisions: playerDecisions,
		npcs_involved: npcsInvolved,
		cliffhanger,
		prep,
	} );

	function addNpc() {
		setNpcsInvolved( [ ...npcsInvolved, { name: '', status: 'alive' } ] );
	}

	function updateNpc( index: number, patch: Partial< NpcInvolved > ) {
		setNpcsInvolved(
			npcsInvolved.map( ( npc, i ) =>
				i === index ? { ...npc, ...patch } : npc
			)
		);
	}

	function removeNpc( index: number ) {
		setNpcsInvolved( npcsInvolved.filter( ( _, i ) => i !== index ) );
	}

	async function save() {
		setSaving( true );
		setError( null );
		setSaved( false );
		try {
			const updated = await api.sessions( gameSlug ).update( session.id, {
				recap: {
					key_events: keyEvents,
					player_decisions: playerDecisions,
					npcs_involved: npcsInvolved.filter(
						( npc ) => npc.name.trim() !== ''
					),
					cliffhanger,
					prep,
				},
			} );
			onSaved( updated );
			setSaved( true );
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Failed to save the recap.', 'beyond-elysium' )
				)
			);
		} finally {
			setSaving( false );
		}
	}

	function openDraft() {
		setDrafted( null );
		setDraftError( null );
		setIsOpen( true );
	}

	async function generateDraft() {
		if ( isDemo ) {
			return;
		}
		setDrafting( true );
		setDraftError( null );
		try {
			const response = await api
				.aiAssist( gameSlug )
				.draftRecap( { session_id: session.id } );
			setDrafted( response.data );
		} catch ( err: unknown ) {
			const message =
				typeof err === 'object' && err !== null && 'message' in err
					? String( ( err as { message?: unknown } ).message )
					: __( 'Something went wrong.', 'beyond-elysium' );
			setDraftError( message );
		} finally {
			setDrafting( false );
		}
	}

	function acceptDraft() {
		if ( drafted ) {
			const merged = mergeDraftedRecap(
				{
					key_events: keyEvents,
					player_decisions: playerDecisions,
					npcs_involved: npcsInvolved,
					cliffhanger,
					prep,
				},
				drafted
			);
			setKeyEvents( merged.key_events );
			setPlayerDecisions( merged.player_decisions );
			setNpcsInvolved( merged.npcs_involved );
			setCliffhanger( merged.cliffhanger );
			setPrep( merged.prep );
		}
		setIsOpen( false );
	}

	return (
		<div className="be-session-recap-panel">
			<div className="be-help-heading">
				<h3>{ __( 'Recap', 'beyond-elysium' ) }</h3>
				<HelpButton helpKey="game-nights" />
			</div>
			<p className="description">
				{ __(
					'Storyteller-only. Players never see this.',
					'beyond-elysium'
				) }
			</p>

			<button
				type="button"
				className="be-ai-assist-button"
				onClick={ openDraft }
				disabled={ ! isDemo && ! hasEmptyField }
			>
				{ __( 'Draft recap', 'beyond-elysium' ) }
			</button>

			{ error && (
				<div className="be-session-recap-panel__error" role="alert">
					{ error }
				</div>
			) }

			<label>
				{ __( 'Key events', 'beyond-elysium' ) }
				<textarea
					value={ keyEvents }
					onChange={ ( e ) => setKeyEvents( e.target.value ) }
					rows={ 3 }
				/>
			</label>

			<label>
				{ __( 'Player decisions', 'beyond-elysium' ) }
				<textarea
					value={ playerDecisions }
					onChange={ ( e ) => setPlayerDecisions( e.target.value ) }
					rows={ 3 }
				/>
			</label>

			<fieldset className="be-session-recap-panel__npcs">
				<legend>{ __( 'NPCs involved', 'beyond-elysium' ) }</legend>
				{ npcsInvolved.map( ( npc, index ) => (
					<div
						key={ index }
						className="be-session-recap-panel__npc-row"
					>
						<input
							type="text"
							value={ npc.name }
							placeholder={ __( 'Name…', 'beyond-elysium' ) }
							onChange={ ( e ) =>
								updateNpc( index, { name: e.target.value } )
							}
						/>
						<select
							value={ npc.status }
							onChange={ ( e ) =>
								updateNpc( index, {
									status: e.target.value as NpcStatus,
								} )
							}
						>
							{ STATUSES.map( ( status ) => (
								<option key={ status } value={ status }>
									{ statusLabel( status ) }
								</option>
							) ) }
						</select>
						<button
							type="button"
							className="be-st-button be-st-button--quiet"
							onClick={ () => removeNpc( index ) }
						>
							{ __( 'Remove', 'beyond-elysium' ) }
						</button>
					</div>
				) ) }
				<button
					type="button"
					className="be-st-button be-st-button--quiet"
					onClick={ addNpc }
				>
					{ __( '+ Add an NPC', 'beyond-elysium' ) }
				</button>
			</fieldset>

			<label>
				{ __( 'Cliffhanger', 'beyond-elysium' ) }
				<textarea
					value={ cliffhanger }
					onChange={ ( e ) => setCliffhanger( e.target.value ) }
					rows={ 2 }
				/>
			</label>

			<label>
				{ __( 'Prep for next time', 'beyond-elysium' ) }
				<textarea
					value={ prep }
					onChange={ ( e ) => setPrep( e.target.value ) }
					rows={ 2 }
				/>
			</label>

			{ saved && (
				<p className="be-session-recap-panel__saved">
					{ __( 'Saved.', 'beyond-elysium' ) }
				</p>
			) }
			<button
				type="button"
				className="be-st-button"
				disabled={ saving }
				onClick={ save }
			>
				{ saving
					? __( 'Saving…', 'beyond-elysium' )
					: __( 'Save recap', 'beyond-elysium' ) }
			</button>

			{ isOpen && isDemo && (
				<AiDemoModal
					title={ __( 'Draft recap', 'beyond-elysium' ) }
					onClose={ () => setIsOpen( false ) }
				/>
			) }
			{ isOpen && ! isDemo && (
				<Modal
					title={ __( 'Draft recap', 'beyond-elysium' ) }
					onClose={ () => setIsOpen( false ) }
					footer={
						drafted ? (
							<>
								<button
									type="button"
									onClick={ generateDraft }
									disabled={ drafting }
								>
									{ __( 'Regenerate', 'beyond-elysium' ) }
								</button>
								<button
									type="button"
									className="button-primary"
									onClick={ acceptDraft }
								>
									{ __(
										'Apply to empty fields',
										'beyond-elysium'
									) }
								</button>
							</>
						) : (
							<>
								<button
									type="button"
									onClick={ () => setIsOpen( false ) }
								>
									{ __( 'Cancel', 'beyond-elysium' ) }
								</button>
								<button
									type="button"
									className="button-primary"
									onClick={ generateDraft }
									disabled={ drafting }
								>
									{ drafting
										? __( 'Drafting…', 'beyond-elysium' )
										: __( 'Draft', 'beyond-elysium' ) }
								</button>
							</>
						)
					}
				>
					{ draftError && (
						<p
							className="be-session-recap-panel__error"
							role="alert"
						>
							{ draftError }
						</p>
					) }
					{ drafted ? (
						<div className="be-session-recap-panel__preview">
							<p className="description">
								{ __(
									'Review before applying - nothing is saved until you click Save recap afterward.',
									'beyond-elysium'
								) }
							</p>
							{ drafted.key_events.trim() !== '' && (
								<div className="be-session-recap-panel__field">
									<strong>
										{ __( 'Key events', 'beyond-elysium' ) }
									</strong>
									<p>{ drafted.key_events }</p>
								</div>
							) }
							{ drafted.player_decisions.trim() !== '' && (
								<div className="be-session-recap-panel__field">
									<strong>
										{ __(
											'Player decisions',
											'beyond-elysium'
										) }
									</strong>
									<p>{ drafted.player_decisions }</p>
								</div>
							) }
							{ drafted.npcs_involved.length > 0 && (
								<div className="be-session-recap-panel__field">
									<strong>
										{ __(
											'NPCs involved',
											'beyond-elysium'
										) }
									</strong>
									<ul>
										{ drafted.npcs_involved.map(
											( npc, i ) => (
												<li key={ i }>
													{ npc.name } —{ ' ' }
													{ statusLabel(
														npc.status
													) }
												</li>
											)
										) }
									</ul>
								</div>
							) }
							{ drafted.cliffhanger.trim() !== '' && (
								<div className="be-session-recap-panel__field">
									<strong>
										{ __(
											'Cliffhanger',
											'beyond-elysium'
										) }
									</strong>
									<p>{ drafted.cliffhanger }</p>
								</div>
							) }
							{ drafted.prep.trim() !== '' && (
								<div className="be-session-recap-panel__field">
									<strong>
										{ __(
											'Prep for next time',
											'beyond-elysium'
										) }
									</strong>
									<p>{ drafted.prep }</p>
								</div>
							) }
						</div>
					) : (
						<p className="description">
							{ __(
								"Sends this session's attendance and after-game reports, and asks for a draft recap. Nothing is sent from another session.",
								'beyond-elysium'
							) }
						</p>
					) }
				</Modal>
			) }
		</div>
	);
}

export default SessionRecapPanel;
