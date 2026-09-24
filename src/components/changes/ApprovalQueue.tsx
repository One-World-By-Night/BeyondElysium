/**
 * Storyteller queue of pending character changes awaiting review.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { describeChange } from '../../lib/describeChange';
import { everyPage } from '../../lib/everyPage';
import { batchApproval, toggleSelection } from '../../lib/queueSelection';
import type { QueueSelection } from '../../lib/queueSelection';
import {
	canApprove,
	costNeededMessage,
	isCostPending,
	MAX_CUSTOM_PRICE,
	parsePrice,
	priceTotal,
	priceUnitLabel,
} from '../../lib/queuePrice';
import type { ApprovalLevel } from '../../types';
import type { Character, ChangeType, QueueChange } from '../../types/character';
import { errorMessage } from '../../lib/errorMessage';
import HelpButton from '../shared/HelpButton';
import './ApprovalQueue.css';

export interface ApprovalQueueProps {
	gameSlug: string;
}

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
 * The price box for a change waiting for one: what it is priced per (a dot, or the whole pick), the number field, and
 * the total as it is typed.
 */
function PriceField( {
	item,
	value,
	onChange,
}: {
	item: QueueChange;
	value: string;
	onChange: ( text: string ) => void;
} ) {
	const basis = item.cost_units ?? null;
	const total = priceTotal( value, basis );
	return (
		<label className="be-approval-queue__price">
			<span>{ priceUnitLabel( basis?.per ?? 'dot' ) }</span>
			<input
				type="number"
				className="be-approval-queue__price-input"
				min={ 0 }
				max={ MAX_CUSTOM_PRICE }
				step={ 1 }
				inputMode="numeric"
				required
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
			{ total !== null && basis && (
				<span className="be-approval-queue__price-total">
					{ basis.per === 'dot' &&
						sprintf(
							/* translators: %d: how many dots the price is multiplied by */
							_n(
								'× %d dot',
								'× %d dots',
								basis.units,
								'beyond-elysium'
							),
							basis.units
						) }{ ' ' }
					{ basis.negative
						? sprintf(
								/* translators: %d: the total price in XP, recorded on a flaw's row */
								__(
									'= %d XP, recorded - a flaw deducts nothing',
									'beyond-elysium'
								),
								total
						  )
						: sprintf(
								/* translators: %d: the total price in XP */
								__( '= %d XP', 'beyond-elysium' ),
								total
						  ) }
				</span>
			) }
		</label>
	);
}

/**
 * Renders the pending-changes queue for every character in the game, with filters for character, change type, and
 * approval level.
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
	// What the Storyteller has typed as the price of each change waiting for one.
	const [ prices, setPrices ] = useState< Record< number, string > >( {} );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ waitingCount, setWaitingCount ] = useState( 0 );

	useEffect( () => {
		// A player never sees either list (403).
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
		// Every character, not the route's first 100.
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
	 * Fetches one page of pending changes matching the selected character and change-type filters from the API and stores
	 * the results and total count.
	 */
	function load() {
		setLoading( true );
		setError( null );
		api.changes( gameSlug )
			.queue( {
				status: 'pending',
				character_id: characterId || undefined,
				change_type: changeType || undefined,
				// Filtered by the server.
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
	 * Applies a filter from its first page, with nothing ticked.
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
	 * The review token the queue issued for one change.
	 */
	function tokenFor( id: number ): string | undefined {
		return items.find( ( item ) => item.id === id )?.review_token;
	}

	/**
	 * Approves a single pending change by id.
	 */
	async function approveOne( item: QueueChange ) {
		const id = item.id;
		// A change waiting for a price goes with the one typed for it; the server refuses it without.
		const price = isCostPending( item ) ? parsePrice( prices[ id ] ) : null;
		try {
			await api.changes( gameSlug ).review( id, {
				status: 'approved',
				review_token: tokenFor( id ),
				...( price !== null ? { xp_cost: price } : {} ),
			} );
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
	 * Submits a rejection for the change currently being rejected, using the typed note as the required reason.
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
	 * Approves every currently selected change in one batch request.
	 */
	async function approveSelected() {
		if ( selected.size === 0 ) {
			return;
		}
		try {
			// Each change's token from when it was ticked, on whichever page.
			const { ids, tokens } = batchApproval( selected );
			const result = await api
				.changes( gameSlug )
				.batchApprove( ids, tokens );
			setSelected( new Map() );
			load();
			const notices: string[] = [];
			if ( result.skipped.length > 0 ) {
				notices.push(
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
			// Waiting for a price is a different thing from skipped: the reviewer has to open each one.
			if ( ( result.needs_cost ?? [] ).length > 0 ) {
				notices.push(
					costNeededMessage( ( result.needs_cost ?? [] ).length )
				);
			}
			if ( notices.length > 0 ) {
				setError( notices.join( ' ' ) );
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
											disabled={ isCostPending( item ) }
											title={
												isCostPending( item )
													? __(
															'Set a price first - this cannot be approved in a batch.',
															'beyond-elysium'
													  )
													: undefined
											}
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
										{ isCostPending( item ) ? (
											<span className="be-approval-queue__unpriced">
												{ __(
													'Needs a price',
													'beyond-elysium'
												) }
											</span>
										) : (
											<>
												{ item.xp_cost >= 0 ? '+' : '' }
												{ item.xp_cost }
											</>
										) }
									</td>
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
										{ isCostPending( item ) && (
											<PriceField
												item={ item }
												value={
													prices[ item.id ] ?? ''
												}
												onChange={ ( text ) =>
													setPrices( ( prev ) => ( {
														...prev,
														[ item.id ]: text,
													} ) )
												}
											/>
										) }
										<button
											type="button"
											disabled={
												! canApprove( item, prices )
											}
											onClick={ () => approveOne( item ) }
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
