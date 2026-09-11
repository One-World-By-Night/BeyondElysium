/**
 * Storyteller queue of pending character changes awaiting review. Lists every pending
 * change across the game's characters with filters by character, change type, and
 * approval level, plus per-row and bulk approve/reject controls. A reject requires a
 * note explaining why; approvals do not.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { describeChange } from '../../lib/describeChange';
import type { ApprovalLevel } from '../../types';
import type { Character, ChangeType, QueueChange } from '../../types/character';
import './ApprovalQueue.css';

export interface ApprovalQueueProps {
	gameSlug: string;
}

interface RestError {
	message?: string;
}

/**
 * Extracts a human-readable message from a caught API error. Returns the error's own
 * message when present, otherwise falls back to a generic failure message so the UI
 * always has something readable to display.
 */
function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null && ( error as RestError ).message ) {
		return ( error as RestError ).message as string;
	}
	return __( 'Failed to load the approval queue.', 'beyond-elysium' );
}

const CHANGE_TYPES: ChangeType[] = [
	'add_trait',
	'remove_trait',
	'modify_trait',
	'modify_resource',
	'modify_identity',
	'xp_earn',
	'xp_adjust',
	'import_note',
];

/**
 * Renders the pending-changes queue for every character in the game, with filters for
 * character, change type, and approval level. Supports approving or rejecting a single
 * change, selecting multiple changes for a bulk approval, and paginating through results.
 */
export function ApprovalQueue( { gameSlug }: ApprovalQueueProps ) {
	const [ items, setItems ] = useState<QueueChange[]>( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ characters, setCharacters ] = useState<Character[]>( [] );
	const [ characterId, setCharacterId ] = useState<number | ''>( '' );
	const [ changeType, setChangeType ] = useState<ChangeType | ''>( '' );
	// approval_level is computed, not stored, so this filter is applied to the fetched page only.
	const [ approvalLevel, setApprovalLevel ] = useState<ApprovalLevel | ''>( '' );
	const [ selected, setSelected ] = useState<Set<number>>( new Set() );
	const [ rejecting, setRejecting ] = useState<number | null>( null );
	const [ rejectNote, setRejectNote ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );

	useEffect( () => {
		api
			.characters( gameSlug )
			.list( { per_page: 100 } )
			.then( setCharacters )
			.catch( () => {
				setCharacters( [] );
				setError( __( 'Failed to load the character filter list.', 'beyond-elysium' ) );
			} );
	}, [ gameSlug ] );

	/**
	 * Fetches one page of pending changes matching the selected character and change-type
	 * filters from the API and stores the results and total count. Re-runs whenever the
	 * filters or page number change.
	 */
	function load() {
		setLoading( true );
		setError( null );
		api
			.changes( gameSlug )
			.queue( {
				status: 'pending',
				character_id: characterId || undefined,
				change_type: changeType || undefined,
				page,
				per_page: 20,
			} )
			.then( ( result ) => {
				setItems( result.items );
				setTotal( result.total );
				setLoading( false );
			} )
			.catch( ( err: unknown ) => {
				setError( errorMessage( err ) );
				setLoading( false );
			} );
	}

	useEffect( load, [ gameSlug, characterId, changeType, page ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const visible = approvalLevel ? items.filter( ( item ) => item.approval_level === approvalLevel ) : items;

	function toggleSelected( id: number ) {
		setSelected( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( id ) ) {
				next.delete( id );
			} else {
				next.add( id );
			}
			return next;
		} );
	}

	/**
	 * Approves a single pending change by id. Sends the approval to the API and, on
	 * success, reloads the queue so the approved row drops out of the pending list; on
	 * failure, shows the error message instead.
	 */
	async function approveOne( id: number ) {
		try {
			await api.changes( gameSlug ).review( id, { status: 'approved' } );
			load();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		}
	}

	/**
	 * Submits a rejection for the change currently being rejected, using the typed note
	 * as the required reason. Sends the rejection to the API, clears the reject form, and
	 * reloads the queue on success.
	 */
	async function confirmReject( id: number ) {
		if ( ! rejectNote.trim() ) {
			return;
		}
		try {
			await api.changes( gameSlug ).review( id, { status: 'rejected', notes: rejectNote.trim() } );
			setRejecting( null );
			setRejectNote( '' );
			load();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		}
	}

	/**
	 * Approves every currently selected change in one batch request. Sends the selected
	 * ids to the API, then clears the selection and reloads the queue so the approved
	 * rows drop out of the pending list.
	 */
	async function approveSelected() {
		if ( selected.size === 0 ) {
			return;
		}
		try {
			await api.changes( gameSlug ).batchApprove( [ ...selected ] );
			setSelected( new Set() );
			load();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		}
	}

	return (
		<div className="be-approval-queue">
			<h2>{ __( 'Approval Queue', 'beyond-elysium' ) }</h2>

			<div className="be-approval-queue__filters">
				<select value={ characterId } onChange={ ( e ) => setCharacterId( e.target.value ? Number( e.target.value ) : '' ) }>
					<option value="">{ __( 'All characters', 'beyond-elysium' ) }</option>
					{ characters.map( ( c ) => (
						<option key={ c.id } value={ c.id }>
							{ c.name }
						</option>
					) ) }
				</select>

				<select value={ changeType } onChange={ ( e ) => setChangeType( e.target.value as ChangeType | '' ) }>
					<option value="">{ __( 'All change types', 'beyond-elysium' ) }</option>
					{ CHANGE_TYPES.map( ( t ) => (
						<option key={ t } value={ t }>
							{ t }
						</option>
					) ) }
				</select>

				<select value={ approvalLevel } onChange={ ( e ) => setApprovalLevel( e.target.value as ApprovalLevel | '' ) }>
					<option value="">{ __( 'All approval levels', 'beyond-elysium' ) }</option>
					<option value="auto">auto</option>
					<option value="st">st</option>
					<option value="coordinator">coordinator</option>
				</select>

				<button type="button" disabled={ selected.size === 0 } onClick={ approveSelected }>
					{ sprintf( __( 'Approve Selected (%d)', 'beyond-elysium' ), selected.size ) }
				</button>
			</div>

			{ error && (
				<div className="be-approval-queue__error" role="alert">
					{ error }
				</div>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : visible.length === 0 ? (
				<p>{ __( 'Nothing pending.', 'beyond-elysium' ) }</p>
			) : (
				<table className="be-approval-queue__table">
					<thead>
						<tr>
							<th />
							<th>{ __( 'Character', 'beyond-elysium' ) }</th>
							<th>{ __( 'Change', 'beyond-elysium' ) }</th>
							<th>{ __( 'XP', 'beyond-elysium' ) }</th>
							<th>{ __( 'Level', 'beyond-elysium' ) }</th>
							<th>{ __( 'Approval Reason', 'beyond-elysium' ) }</th>
							<th>{ __( 'Submitted by', 'beyond-elysium' ) }</th>
							<th>{ __( 'When', 'beyond-elysium' ) }</th>
							<th>{ __( 'Actions', 'beyond-elysium' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ visible.map( ( item ) => (
							<tr key={ item.id }>
								<td>
									<input
										type="checkbox"
										checked={ selected.has( item.id ) }
										onChange={ () => toggleSelected( item.id ) }
									/>
								</td>
								<td>{ item.character_name ?? `#${ item.character_id }` }</td>
								<td>{ describeChange( item.change_type, item.change_data ) }</td>
								<td>
									{ item.xp_cost >= 0 ? '+' : '' }
									{ item.xp_cost }
								</td>
								<td>{ item.approval_level }</td>
								<td className="be-approval-queue__reason">{ item.reason }</td>
								<td>{ item.submitted_by }</td>
								<td>{ item.submitted_at }</td>
								<td className="be-approval-queue__actions">
									<button type="button" onClick={ () => approveOne( item.id ) }>
										{ __( 'Approve', 'beyond-elysium' ) }
									</button>
									{ rejecting === item.id ? (
										<>
											<input
												type="text"
												placeholder={ __( 'Reason (required)', 'beyond-elysium' ) }
												value={ rejectNote }
												onChange={ ( e ) => setRejectNote( e.target.value ) }
											/>
											<button type="button" disabled={ ! rejectNote.trim() } onClick={ () => confirmReject( item.id ) }>
												{ __( 'Confirm Reject', 'beyond-elysium' ) }
											</button>
											<button
												type="button"
												onClick={ () => {
													setRejecting( null );
													setRejectNote( '' );
												} }
											>
												{ __( 'Cancel', 'beyond-elysium' ) }
											</button>
										</>
									) : (
										<button type="button" onClick={ () => setRejecting( item.id ) }>
											{ __( 'Reject', 'beyond-elysium' ) }
										</button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<div className="be-approval-queue__pagination">
				<button type="button" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) }>
					{ __( 'Previous', 'beyond-elysium' ) }
				</button>
				<span>
					{ sprintf( __( 'Page %1$d (%2$d total)', 'beyond-elysium' ), page, total ) }
				</span>
				<button type="button" disabled={ page * 20 >= total } onClick={ () => setPage( ( p ) => p + 1 ) }>
					{ __( 'Next', 'beyond-elysium' ) }
				</button>
			</div>
		</div>
	);
}

export default ApprovalQueue;
