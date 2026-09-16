/**
 * QueryResults renders a paginated, sortable table of character query results,
 * with a per-row match-reason column and a CSV export button. Used by QueryTool's
 * Search tab to display the output of a run query. The CSV export covers only
 * the currently loaded page of rows, not the full result set.
 *
 * Also the interface for `Change_Engine::bulk_award_xp()` (BE_PROCESS/releases/0.99.2-workflow.md,
 * "Bulk XP award has no interface") - the REST route and client method both already
 * existed and worked, with nothing in `src/` ever calling them. A query's result set is
 * exactly the "a group of characters" the endpoint was built for, so the award action lives
 * here rather than as a new screen. Gated on `be_manage_characters`, matching
 * `resolve_approval_level()`'s own manager-only default for `xp_earn`/`xp_adjust`.
 *
 * Two more bulk actions (bulk-operations-design.md) share the exact same shape: resetting
 * a resource pool's temporary rating back to its permanent one, and setting the same status
 * on every selected character. Neither needed new mechanism - both are ordinary desktop
 * query-result actions, gated and selected the same way the bulk-XP-award form already is.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import api from '../../api/client';
import type { QueryResultCharacter } from '../../types/query';
import type { SchemaBlock, ResourcePoolDefinition } from '../../types';
import './QueryResults.css';

export interface QueryResultsProps {
	gameSlug: string;
	/** One of Field_Registry::QUERYABLE_INVENTORIES - gates the bulk-XP-award form to 'char'. */
	inventory: string;
	/** This inventory's own result columns (query-inventories.php's result_columns), owned by the caller. */
	columns: { key: string; label: string }[];
	items: QueryResultCharacter[];
	total: number;
	page: number;
	perPage: number;
	onPageChange: ( page: number ) => void;
	onSort: ( field: string, direction: 'asc' | 'desc' ) => void;
	sortField?: string;
	sortDirection?: 'asc' | 'desc';
	/** Names the search these results came from (searchKey()); a selection belongs to one search. */
	searchKey?: string;
}

/**
 * Reads a column's display value off a result row. A world object's own
 * columns (name, description/'notes') sit at the top level same as a
 * character's; everything else queryable on it lives under its decoded
 * `properties` object (item_type, level, and so on) - the same `properties`
 * vs `column` split Query_Engine::resolve_value() reads server-side.
 */
function cellValue( item: QueryResultCharacter, key: string ): string {
	const direct = item[ key ];
	if ( direct !== undefined ) {
		return String( direct ?? '' );
	}
	const properties = item.properties as Record< string, unknown > | undefined;
	return String( properties?.[ key ] ?? '' );
}

/**
 * Renders a paginated table of query results with sortable columns and a per-row
 * match-reason column explaining why each character matched. Includes a CSV
 * export button and Previous/Next pagination controls. The bulk-XP-award form
 * is offered only for the `char` inventory: on any other inventory, `items`
 * are addressed by `be_world_objects.id`, and awarding XP to whichever
 * *characters* happen to share those primary keys would be silent data
 * corruption behind a plausible success message
 * (query-beyond-characters-design.md §9.3, the highest-severity risk in the
 * whole feature).
 */
export function QueryResults( {
	gameSlug,
	inventory,
	columns,
	items,
	total,
	page,
	perPage,
	onPageChange,
	onSort,
	sortField,
	sortDirection,
	searchKey = '',
}: QueryResultsProps ) {
	const [ exporting, setExporting ] = useState( false );
	const [ selected, setSelected ] = useState< Set< number > >( new Set() );
	const [ awarding, setAwarding ] = useState( false );
	const [ amount, setAmount ] = useState( '' );
	const [ reason, setReason ] = useState( '' );
	const [ awardMessage, setAwardMessage ] = useState< string | null >( null );

	const [ resourcePoolBlocks, setResourcePoolBlocks ] = useState<
		SchemaBlock[]
	>( [] );
	const [ poolBlockSlug, setPoolBlockSlug ] = useState( '' );
	const [ poolName, setPoolName ] = useState( '' );
	const [ resetting, setResetting ] = useState( false );
	const [ resetMessage, setResetMessage ] = useState< string | null >( null );

	const [ statusOptions, setStatusOptions ] = useState< string[] >( [] );
	const [ bulkStatus, setBulkStatus ] = useState( '' );
	const [ settingStatus, setSettingStatus ] = useState( false );
	const [ statusMessage, setStatusMessage ] = useState< string | null >(
		null
	);

	// A selection belongs to the search it was made on. In another inventory the same numeric ids
	// mean different entities; in another search of the same inventory a checked row may no longer
	// be on screen, and a bulk action would still reach it (1.0.0-review F-075). Paging and sorting
	// are the same search and keep it.
	useEffect( () => {
		setSelected( new Set() );
	}, [ inventory, searchKey ] );

	const canBulkManage =
		inventory === 'char' &&
		( window.beyondElysium?.capabilities?.be_manage_characters ?? false );

	// Sourced from the server rather than hardcoded a second time client-side - the same
	// "options" pattern Approval_Rules_Controller's own vocabulary route already established.
	useEffect( () => {
		if ( ! canBulkManage ) {
			return;
		}
		api.schemaBlocks
			.list( {
				section_type: 'resource_pool',
				game_slug: gameSlug,
				per_page: 100,
			} )
			.then( setResourcePoolBlocks )
			.catch( () => setResourcePoolBlocks( [] ) );
		api.characters( gameSlug )
			.statuses()
			.then( ( { statuses } ) => setStatusOptions( statuses ) )
			.catch( () => setStatusOptions( [] ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ canBulkManage, gameSlug ] );

	const activePoolBlock = resourcePoolBlocks.find(
		( b ) => b.slug === poolBlockSlug
	);
	const poolsForActiveBlock = activePoolBlock
		? ( activePoolBlock.definition as ResourcePoolDefinition ).pools
		: [];

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

	function toggleSelectAllOnPage() {
		const pageIds = items.map( ( i ) => i.id );
		const allSelected = pageIds.every( ( id ) => selected.has( id ) );
		setSelected( ( prev ) => {
			const next = new Set( prev );
			pageIds.forEach( ( id ) =>
				allSelected ? next.delete( id ) : next.add( id )
			);
			return next;
		} );
	}

	async function submitBulkAward( e: React.FormEvent ) {
		e.preventDefault();
		const parsedAmount = Number( amount );
		if ( ! parsedAmount || selected.size === 0 || ! reason.trim() ) {
			return;
		}
		setAwarding( true );
		setAwardMessage( null );
		try {
			const result = await api.experience( gameSlug ).bulkAward( {
				character_ids: Array.from( selected ),
				amount: parsedAmount,
				reason: reason.trim(),
			} );
			setAwardMessage(
				sprintf(
					/* translators: 1: number of characters awarded, 2: XP amount */
					__(
						'Awarded %1$d XP to %2$d character(s).',
						'beyond-elysium'
					),
					result.amount,
					result.awarded
				)
			);
			setSelected( new Set() );
			setAmount( '' );
			setReason( '' );
		} catch {
			setAwardMessage(
				__(
					'Failed to award XP. Nothing was changed.',
					'beyond-elysium'
				)
			);
		} finally {
			setAwarding( false );
		}
	}

	async function submitBulkReset( e: React.FormEvent ) {
		e.preventDefault();
		if ( selected.size === 0 || ! poolBlockSlug || ! poolName ) {
			return;
		}
		setResetting( true );
		setResetMessage( null );
		try {
			const result = await api.resourcePools( gameSlug ).bulkReset( {
				character_ids: Array.from( selected ),
				block_slug: poolBlockSlug,
				pool_name: poolName,
			} );
			setResetMessage(
				sprintf(
					/* translators: 1: pool name, 2: number of characters reset */
					__(
						'Reset %1$s to permanent for %2$d character(s).',
						'beyond-elysium'
					),
					result.pool_name,
					result.reset
				)
			);
			setSelected( new Set() );
		} catch {
			setResetMessage(
				__(
					'Failed to reset the pool. Nothing was changed.',
					'beyond-elysium'
				)
			);
		} finally {
			setResetting( false );
		}
	}

	async function submitBulkStatus( e: React.FormEvent ) {
		e.preventDefault();
		if ( selected.size === 0 || ! bulkStatus ) {
			return;
		}
		setSettingStatus( true );
		setStatusMessage( null );
		try {
			const result = await api.characters( gameSlug ).bulkStatus( {
				character_ids: Array.from( selected ),
				status: bulkStatus,
			} );
			const failed = result.results.filter( ( r ) => ! r.success ).length;
			setStatusMessage(
				failed > 0
					? sprintf(
							/* translators: 1: number of characters updated, 2: number that failed */
							__(
								'Updated %1$d character(s); %2$d could not be updated.',
								'beyond-elysium'
							),
							result.updated,
							failed
					  )
					: sprintf(
							/* translators: %d: number of characters updated */
							__( 'Updated %d character(s).', 'beyond-elysium' ),
							result.updated
					  )
			);
			setSelected( new Set() );
		} catch {
			setStatusMessage(
				__(
					'Failed to update status. Nothing was changed.',
					'beyond-elysium'
				)
			);
		} finally {
			setSettingStatus( false );
		}
	}

	function toggleSort( key: string ) {
		const nextDirection =
			sortField === key && sortDirection === 'asc' ? 'desc' : 'asc';
		onSort( key, nextDirection );
	}

	function exportCsv() {
		setExporting( true );
		try {
			const header = [
				...columns.map( ( c ) => c.label ),
				__( 'Match Reason', 'beyond-elysium' ),
			];
			const rows = items.map( ( item ) => [
				...columns.map( ( c ) => cellValue( item, c.key ) ),
				item.match_reason ?? '',
			] );
			const csv = [ header, ...rows ]
				.map( ( row ) =>
					row
						.map(
							( cell ) =>
								`"${ String( cell ).replace( /"/g, '""' ) }"`
						)
						.join( ',' )
				)
				.join( '\n' );

			const blob = new Blob( [ csv ], { type: 'text/csv' } );
			const url = URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = 'query-results.csv';
			link.click();
			URL.revokeObjectURL( url );
		} finally {
			setExporting( false );
		}
	}

	const allOnPageSelected =
		items.length > 0 && items.every( ( i ) => selected.has( i.id ) );

	return (
		<div className="be-query-results">
			<div className="be-query-results__toolbar">
				<span>
					{
						/* translators: %d: total number of matching results */
						sprintf( __( '%d total', 'beyond-elysium' ), total )
					}
				</span>
				<button
					type="button"
					onClick={ exportCsv }
					disabled={ exporting || items.length === 0 }
				>
					{ __( 'Export CSV', 'beyond-elysium' ) }
				</button>
			</div>

			{ items.length === 0 ? (
				<p>{ __( 'Nothing matches this query.', 'beyond-elysium' ) }</p>
			) : (
				<div className="be-table-box">
					<table className="be-query-results__table be-responsive-table">
						<thead>
							<tr>
								{ canBulkManage && (
									<th>
										<input
											type="checkbox"
											checked={ allOnPageSelected }
											onChange={ toggleSelectAllOnPage }
											aria-label={ __(
												'Select all on this page',
												'beyond-elysium'
											) }
										/>
									</th>
								) }
								{ columns.map( ( col ) => (
									<th key={ col.key }>
										<button
											type="button"
											className="be-query-results__sort"
											onClick={ () =>
												toggleSort( col.key )
											}
										>
											{ col.label }
											{ sortField === col.key &&
												( sortDirection === 'asc'
													? ' ▲'
													: ' ▼' ) }
										</button>
									</th>
								) ) }
								<th>
									{ __( 'Match Reason', 'beyond-elysium' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ items.map( ( item ) => (
								<tr key={ item.id }>
									{ canBulkManage && (
										<td
											data-label={ __(
												'Select',
												'beyond-elysium'
											) }
										>
											<input
												type="checkbox"
												checked={ selected.has(
													item.id
												) }
												onChange={ () =>
													toggleSelected( item.id )
												}
												aria-label={ sprintf(
													/* translators: %s: the result row's own name */
													__(
														'Select %s',
														'beyond-elysium'
													),
													item.name
												) }
											/>
										</td>
									) }
									{ columns.map( ( col ) => (
										<td
											key={ col.key }
											data-label={ col.label }
										>
											{ cellValue( item, col.key ) }
										</td>
									) ) }
									<td
										data-label={ __(
											'Match Reason',
											'beyond-elysium'
										) }
									>
										{ item.match_reason }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ canBulkManage && selected.size > 0 && (
				<form
					className="be-query-results__bulk-award"
					onSubmit={ submitBulkAward }
				>
					<span>
						{ sprintf(
							/* translators: %d: number of results ticked for the bulk XP award */
							__( '%d selected', 'beyond-elysium' ),
							selected.size
						) }
					</span>
					<input
						type="number"
						value={ amount }
						onChange={ ( e ) => setAmount( e.target.value ) }
						placeholder={ __( 'XP amount', 'beyond-elysium' ) }
						aria-label={ __(
							'XP amount to award',
							'beyond-elysium'
						) }
						required
					/>
					<input
						type="text"
						value={ reason }
						onChange={ ( e ) => setReason( e.target.value ) }
						placeholder={ __(
							'Reason (required)',
							'beyond-elysium'
						) }
						aria-label={ __(
							'Reason for this award',
							'beyond-elysium'
						) }
						required
					/>
					<button type="submit" disabled={ awarding }>
						{ __( 'Award XP to selected', 'beyond-elysium' ) }
					</button>
				</form>
			) }
			{ awardMessage && (
				<p className="be-query-results__award-message" role="status">
					{ awardMessage }
				</p>
			) }

			{ canBulkManage &&
				selected.size > 0 &&
				resourcePoolBlocks.length > 0 && (
					<form
						className="be-query-results__bulk-award"
						onSubmit={ submitBulkReset }
					>
						<span>
							{ sprintf(
								/* translators: %d: number of results ticked for the bulk pool reset */
								__( '%d selected', 'beyond-elysium' ),
								selected.size
							) }
						</span>
						<select
							value={ poolBlockSlug }
							onChange={ ( e ) => {
								setPoolBlockSlug( e.target.value );
								setPoolName( '' );
							} }
							aria-label={ __(
								'Resource pool block',
								'beyond-elysium'
							) }
							required
						>
							<option value="">
								{ __( 'Pool block…', 'beyond-elysium' ) }
							</option>
							{ resourcePoolBlocks.map( ( block ) => (
								<option key={ block.slug } value={ block.slug }>
									{ block.name }
								</option>
							) ) }
						</select>
						<select
							value={ poolName }
							onChange={ ( e ) => setPoolName( e.target.value ) }
							aria-label={ __( 'Pool', 'beyond-elysium' ) }
							disabled={ ! poolBlockSlug }
							required
						>
							<option value="">
								{ __( 'Pool…', 'beyond-elysium' ) }
							</option>
							{ poolsForActiveBlock.map( ( pool ) => (
								<option key={ pool.name } value={ pool.name }>
									{ pool.name }
								</option>
							) ) }
						</select>
						<button type="submit" disabled={ resetting }>
							{ __(
								'Reset temporary to permanent',
								'beyond-elysium'
							) }
						</button>
					</form>
				) }
			{ resetMessage && (
				<p className="be-query-results__award-message" role="status">
					{ resetMessage }
				</p>
			) }

			{ canBulkManage &&
				selected.size > 0 &&
				statusOptions.length > 0 && (
					<form
						className="be-query-results__bulk-award"
						onSubmit={ submitBulkStatus }
					>
						<span>
							{ sprintf(
								/* translators: %d: number of results ticked for the bulk status change */
								__( '%d selected', 'beyond-elysium' ),
								selected.size
							) }
						</span>
						<select
							value={ bulkStatus }
							onChange={ ( e ) =>
								setBulkStatus( e.target.value )
							}
							aria-label={ __( 'New status', 'beyond-elysium' ) }
							required
						>
							<option value="">
								{ __( 'Set status…', 'beyond-elysium' ) }
							</option>
							{ statusOptions.map( ( status ) => (
								<option key={ status } value={ status }>
									{ status }
								</option>
							) ) }
						</select>
						<button type="submit" disabled={ settingStatus }>
							{ __(
								'Set status for selected',
								'beyond-elysium'
							) }
						</button>
					</form>
				) }
			{ statusMessage && (
				<p className="be-query-results__award-message" role="status">
					{ statusMessage }
				</p>
			) }

			<div className="be-query-results__pagination">
				<button
					type="button"
					disabled={ page <= 1 }
					onClick={ () => onPageChange( page - 1 ) }
				>
					{ __( 'Previous', 'beyond-elysium' ) }
				</button>
				<span>
					{
						/* translators: %d: current page number */
						sprintf( __( 'Page %d', 'beyond-elysium' ), page )
					}
				</span>
				<button
					type="button"
					disabled={ page * perPage >= total }
					onClick={ () => onPageChange( page + 1 ) }
				>
					{ __( 'Next', 'beyond-elysium' ) }
				</button>
			</div>
		</div>
	);
}

export default QueryResults;
