/**
 * Storyteller queue of pending character changes awaiting review. Lists every pending
 * change across the game's characters with filters by character, change type, and
 * approval level, plus per-row and bulk approve/reject controls. A reject requires a
 * note explaining why; approvals do not.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { describeChange } from '../../lib/describeChange';
import { everyPage } from '../../lib/everyPage';
import { batchApproval, toggleSelection } from '../../lib/queueSelection';
import type { QueueSelection } from '../../lib/queueSelection';
import type { ApprovalLevel } from '../../types';
import type { Character, ChangeType, QueueChange } from '../../types/character';
import { errorMessage } from '../../lib/errorMessage';
import HelpButton from '../shared/HelpButton';
import './ApprovalQueue.css';

export interface ApprovalQueueProps {
	gameSlug: string;
}

/**
 * The submitter's display name, or their user id if the account no longer exists
 * (1.0.0-review F-116 - this used to always show the raw id).
 */
function submitterLabel( item: QueueChange ): string {
	return (
		item.submitted_by_name ??
		sprintf(
			/* translators: %d: WordPress user id */
			__( '#%d', 'beyond-elysium' ),
			item.submitted_by
		)
	);
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
	const [ items, setItems ] = useState< QueueChange[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ characterId, setCharacterId ] = useState< number | '' >( '' );
	const [ changeType, setChangeType ] = useState< ChangeType | '' >( '' );
	const [ approvalLevel, setApprovalLevel ] = useState< ApprovalLevel | '' >(
		''
	);
	const [ selected, setSelected ] = useState< QueueSelection >( new Map() );
	const [ rejecting, setRejecting ] = useState< number | null >( null );
	const [ rejectNote, setRejectNote ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ waitingCount, setWaitingCount ] = useState( 0 );

	useEffect( () => {
		// A player never sees either list (403), which counts as zero waiting - not an error
		// worth surfacing on a page whose whole point is the Storyteller-review queue.
		Promise.all( [
			api
				.submissions( gameSlug )
				.list()
				.then(
					( rows ) => rows.length,
					() => 0
				),
			api
				.transfers( gameSlug )
				.list()
				.then(
					( rows ) =>
						rows.filter(
							( row ) =>
								row.direction === 'inbound' &&
								row.state === 'offered'
						).length,
					() => 0
				),
		] ).then( ( [ submissionCount, transferCount ] ) =>
			setWaitingCount( submissionCount + transferCount )
		);
	}, [ gameSlug ] );

	useEffect( () => {
		// Every character, not the route's first 100 (1.0.0-review F-100).
		everyPage( ( pageNumber ) =>
			api
				.characters( gameSlug )
				.listPaginated( { page: pageNumber, per_page: 100 } )
		)
			.then( setCharacters )
			.catch( () => {
				setCharacters( [] );
				setError(
					__(
						'Failed to load the character filter list.',
						'beyond-elysium'
					)
				);
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
		api.changes( gameSlug )
			.queue( {
				status: 'pending',
				character_id: characterId || undefined,
				change_type: changeType || undefined,
				// Filtered by the server, so the page and its total count only this level (F-099).
				approval_level: approvalLevel || undefined,
				page,
				per_page: 20,
			} )
			.then( ( result ) => {
				setItems( result.items );
				setTotal( result.total );
				setLoading( false );
			} )
			.catch( ( err: unknown ) => {
				setError(
					errorMessage(
						err,
						__(
							'Failed to load the approval queue.',
							'beyond-elysium'
						)
					)
				);
				setLoading( false );
			} );
	}

	useEffect( load, [
		gameSlug,
		characterId,
		changeType,
		approvalLevel,
		page,
	] ); // eslint-disable-line react-hooks/exhaustive-deps

	/**
	 * Applies a filter from its first page, with nothing ticked: a tick belongs to the
	 * list it was made on, and an earlier page number can run past the filtered list's end
	 * (1.0.0-review F-099).
	 */
	function applyFilter( set: () => void ) {
		set();
		setPage( 1 );
		setSelected( new Map() );
	}

	function toggleSelected( item: QueueChange ) {
		setSelected( ( prev ) =>
			toggleSelection( prev, item.id, item.review_token )
		);
	}

	/**
	 * The review token the queue issued for one change - what the reviewer was shown.
	 */
	function tokenFor( id: number ): string | undefined {
		return items.find( ( item ) => item.id === id )?.review_token;
	}

	/**
	 * Approves a single pending change by id. Sends the approval to the API and, on
	 * success, reloads the queue so the approved row drops out of the pending list; on
	 * failure, shows the error message instead.
	 */
	async function approveOne( id: number ) {
		try {
			await api.changes( gameSlug ).review( id, {
				status: 'approved',
				review_token: tokenFor( id ),
			} );
			load();
		} catch ( err: unknown ) {
			// Refused as already reviewed or edited since it was shown: reload so the row is current.
			// load() clears the error first, so the message is set after it.
			load();
			setError(
				errorMessage(
					err,
					__( 'Failed to load the approval queue.', 'beyond-elysium' )
				)
			);
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
			await api.changes( gameSlug ).review( id, {
				status: 'rejected',
				notes: rejectNote.trim(),
				review_token: tokenFor( id ),
			} );
			setRejecting( null );
			setRejectNote( '' );
			load();
		} catch ( err: unknown ) {
			load();
			setError(
				errorMessage(
					err,
					__( 'Failed to load the approval queue.', 'beyond-elysium' )
				)
			);
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
			// Each change's token from when it was ticked, on whichever page (F-101).
			const { ids, tokens } = batchApproval( selected );
			const result = await api
				.changes( gameSlug )
				.batchApprove( ids, tokens );
			setSelected( new Map() );
			// load() clears any error first, so the skipped notice is set after it.
			load();
			if ( result.skipped.length > 0 ) {
				setError(
					sprintf(
						// translators: %d: number of changes not approved.
						__(
							'%d change(s) were not approved - they were already reviewed or edited after the queue loaded. The queue has been reloaded.',
							'beyond-elysium'
						),
						result.skipped.length
					)
				);
			}
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Failed to load the approval queue.', 'beyond-elysium' )
				)
			);
		}
	}

	return (
		<div className="be-approval-queue">
			<div className="be-help-heading">
				<h2>{ __( 'Approval Queue', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="approval-queue" />
			</div>

			{ waitingCount > 0 && window.beyondElysium?.importPageUrl && (
				<p className="be-approval-queue__waiting-pointer">
					<a href={ window.beyondElysium.importPageUrl }>
						{ sprintf(
							/* translators: %d: number of transfers and player-sent sheets waiting for review */
							_n(
								'%d sheet is waiting for review on the Import page.',
								'%d sheets are waiting for review on the Import page.',
								waitingCount,
								'beyond-elysium'
							),
							waitingCount
						) }
					</a>
				</p>
			) }

			<div className="be-approval-queue__filters">
				<select
					value={ characterId }
					onChange={ ( e ) =>
						applyFilter( () =>
							setCharacterId(
								e.target.value ? Number( e.target.value ) : ''
							)
						)
					}
				>
					<option value="">
						{ __( 'All characters', 'beyond-elysium' ) }
					</option>
					{ characters.map( ( c ) => (
						<option key={ c.id } value={ c.id }>
							{ c.name }
						</option>
					) ) }
				</select>

				<select
					value={ changeType }
					onChange={ ( e ) =>
						applyFilter( () =>
							setChangeType( e.target.value as ChangeType | '' )
						)
					}
				>
					<option value="">
						{ __( 'All change types', 'beyond-elysium' ) }
					</option>
					{ CHANGE_TYPES.map( ( t ) => (
						<option key={ t } value={ t }>
							{ t }
						</option>
					) ) }
				</select>

				<select
					value={ approvalLevel }
					onChange={ ( e ) =>
						applyFilter( () =>
							setApprovalLevel(
								e.target.value as ApprovalLevel | ''
							)
						)
					}
				>
					<option value="">
						{ __( 'All approval levels', 'beyond-elysium' ) }
					</option>
					<option value="auto">auto</option>
					<option value="st">st</option>
				</select>

				<button
					type="button"
					disabled={ selected.size === 0 }
					onClick={ approveSelected }
				>
					{ sprintf(
						/* translators: %d: number of changes ticked for approval */
						__( 'Approve Selected (%d)', 'beyond-elysium' ),
						selected.size
					) }
				</button>
			</div>

			{ error && (
				<div className="be-approval-queue__error" role="alert">
					{ error }
				</div>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : items.length === 0 ? (
				<p>{ __( 'Nothing pending.', 'beyond-elysium' ) }</p>
			) : (
				<div className="be-table-box">
					<table className="be-approval-queue__table be-responsive-table">
						<thead>
							<tr>
								<th />
								<th>{ __( 'Character', 'beyond-elysium' ) }</th>
								<th>{ __( 'Change', 'beyond-elysium' ) }</th>
								<th>{ __( 'XP', 'beyond-elysium' ) }</th>
								<th>{ __( 'Level', 'beyond-elysium' ) }</th>
								<th>
									{ __(
										'Approval Reason',
										'beyond-elysium'
									) }
								</th>
								<th>
									{ __( 'Submitted by', 'beyond-elysium' ) }
								</th>
								<th>{ __( 'When', 'beyond-elysium' ) }</th>
								<th>{ __( 'Actions', 'beyond-elysium' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ items.map( ( item ) => (
								<tr key={ item.id }>
									<td
										data-label={ __(
											'Select',
											'beyond-elysium'
										) }
									>
										<input
											type="checkbox"
											checked={ selected.has( item.id ) }
											onChange={ () =>
												toggleSelected( item )
											}
										/>
									</td>
									<td
										data-label={ __(
											'Character',
											'beyond-elysium'
										) }
									>
										{ item.character_name ??
											`#${ item.character_id }` }
									</td>
									<td
										data-label={ __(
											'Change',
											'beyond-elysium'
										) }
									>
										{ describeChange(
											item.change_type,
											item.change_data
										) }
									</td>
									<td
										data-label={ __(
											'XP',
											'beyond-elysium'
										) }
									>
										{ item.xp_cost >= 0 ? '+' : '' }
										{ item.xp_cost }
									</td>
									{ /* Level/Submitted by/When: real information, but not what an ST triaging between
									 * scenes needs first (mobile-sheet-design.md §5.5) - collapsed behind one native
									 * disclosure per card at phone width rather than three more stacked rows. Desktop
									 * keeps them as plain columns; .be-approval-queue__detail-toggle only renders at
									 * phone width (Admin.css-style: display:none by default, shown in the media query). */ }
									<td
										className="be-approval-queue__detail-toggle"
										data-label=""
									>
										<details>
											<summary>
												{ __(
													'Details',
													'beyond-elysium'
												) }
											</summary>
											<dl className="be-approval-queue__detail-list">
												<dt>
													{ __(
														'Level',
														'beyond-elysium'
													) }
												</dt>
												<dd>{ item.approval_level }</dd>
												<dt>
													{ __(
														'Submitted by',
														'beyond-elysium'
													) }
												</dt>
												<dd>
													{ submitterLabel( item ) }
												</dd>
												<dt>
													{ __(
														'When',
														'beyond-elysium'
													) }
												</dt>
												<dd>{ item.submitted_at }</dd>
											</dl>
										</details>
									</td>
									<td
										data-label={ __(
											'Level',
											'beyond-elysium'
										) }
										className="be-approval-queue__detail-column"
									>
										{ item.approval_level }
									</td>
									<td
										className="be-approval-queue__reason"
										data-label={ __(
											'Approval Reason',
											'beyond-elysium'
										) }
									>
										{ item.reason }
									</td>
									<td
										data-label={ __(
											'Submitted by',
											'beyond-elysium'
										) }
										className="be-approval-queue__detail-column"
									>
										{ submitterLabel( item ) }
									</td>
									<td
										data-label={ __(
											'When',
											'beyond-elysium'
										) }
										className="be-approval-queue__detail-column"
									>
										{ item.submitted_at }
									</td>
									<td
										className="be-approval-queue__actions"
										data-label={ __(
											'Actions',
											'beyond-elysium'
										) }
									>
										<button
											type="button"
											onClick={ () =>
												approveOne( item.id )
											}
										>
											{ __(
												'Approve',
												'beyond-elysium'
											) }
										</button>
										{ rejecting === item.id ? (
											<>
												<input
													type="text"
													placeholder={ __(
														'Reason (required)',
														'beyond-elysium'
													) }
													value={ rejectNote }
													onChange={ ( e ) =>
														setRejectNote(
															e.target.value
														)
													}
												/>
												<button
													type="button"
													disabled={
														! rejectNote.trim()
													}
													onClick={ () =>
														confirmReject( item.id )
													}
												>
													{ __(
														'Confirm Reject',
														'beyond-elysium'
													) }
												</button>
												<button
													type="button"
													onClick={ () => {
														setRejecting( null );
														setRejectNote( '' );
													} }
												>
													{ __(
														'Cancel',
														'beyond-elysium'
													) }
												</button>
											</>
										) : (
											<button
												type="button"
												onClick={ () =>
													setRejecting( item.id )
												}
											>
												{ __(
													'Reject',
													'beyond-elysium'
												) }
											</button>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			<div className="be-approval-queue__pagination">
				<button
					type="button"
					disabled={ page <= 1 }
					onClick={ () => setPage( ( p ) => p - 1 ) }
				>
					{ __( 'Previous', 'beyond-elysium' ) }
				</button>
				<span>
					{ sprintf(
						/* translators: 1: current page number, 2: total number of matching changes */
						__( 'Page %1$d (%2$d total)', 'beyond-elysium' ),
						page,
						total
					) }
				</span>
				<button
					type="button"
					disabled={ page * 20 >= total }
					onClick={ () => setPage( ( p ) => p + 1 ) }
				>
					{ __( 'Next', 'beyond-elysium' ) }
				</button>
			</div>
		</div>
	);
}

export default ApprovalQueue;
