/**
 * QueryResults renders a paginated, sortable table of character query results,
 * with a per-row match-reason column and a CSV export button. Used by QueryTool's
 * Search tab to display the output of a run query. The CSV export covers only
 * the currently loaded page of rows, not the full result set.
 *
 * Also the interface for `Change_Engine::bulk_award_xp()` (BE_PROCESS/0.99.2-workflow.md,
 * "Bulk XP award has no interface") - the REST route and client method both already
 * existed and worked, with nothing in `src/` ever calling them. A query's result set is
 * exactly the "a group of characters" the endpoint was built for, so the award action lives
 * here rather than as a new screen. Gated on `be_manage_characters`, matching
 * `resolve_approval_level()`'s own manager-only default for `xp_earn`/`xp_adjust`.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import api from '../../api/client';
import type { QueryResultCharacter } from '../../types/query';
import './QueryResults.css';

export interface QueryResultsProps {
	gameSlug: string;
	items: QueryResultCharacter[];
	total: number;
	page: number;
	perPage: number;
	onPageChange: ( page: number ) => void;
	onSort: ( field: string, direction: 'asc' | 'desc' ) => void;
	sortField?: string;
	sortDirection?: 'asc' | 'desc';
}

const COLUMNS: { key: string; label: string }[] = [
	{ key: 'name', label: __( 'Name', 'beyond-elysium' ) },
	{ key: 'stack_slug', label: __( 'Type', 'beyond-elysium' ) },
	{ key: 'status', label: __( 'Status', 'beyond-elysium' ) },
];

/**
 * Renders a paginated table of query results with sortable columns and a per-row
 * match-reason column explaining why each character matched. Includes a CSV
 * export button and Previous/Next pagination controls.
 */
export function QueryResults( { gameSlug, items, total, page, perPage, onPageChange, onSort, sortField, sortDirection }: QueryResultsProps ) {
	const [ exporting, setExporting ] = useState( false );
	const [ selected, setSelected ] = useState<Set<number>>( new Set() );
	const [ awarding, setAwarding ] = useState( false );
	const [ amount, setAmount ] = useState( '' );
	const [ reason, setReason ] = useState( '' );
	const [ awardMessage, setAwardMessage ] = useState<string | null>( null );

	const canManage = window.beyondElysium?.capabilities?.be_manage_characters ?? false;

	function toggleSelected( id: number ) {
		setSelected( ( prev ) => {
			const next = new Set( prev );
			next.has( id ) ? next.delete( id ) : next.add( id );
			return next;
		} );
	}

	function toggleSelectAllOnPage() {
		const pageIds = items.map( ( i ) => i.id );
		const allSelected = pageIds.every( ( id ) => selected.has( id ) );
		setSelected( ( prev ) => {
			const next = new Set( prev );
			pageIds.forEach( ( id ) => ( allSelected ? next.delete( id ) : next.add( id ) ) );
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
					__( 'Awarded %1$d XP to %2$d character(s).', 'beyond-elysium' ),
					result.amount,
					result.awarded
				)
			);
			setSelected( new Set() );
			setAmount( '' );
			setReason( '' );
		} catch {
			setAwardMessage( __( 'Failed to award XP. Nothing was changed.', 'beyond-elysium' ) );
		} finally {
			setAwarding( false );
		}
	}

	function toggleSort( key: string ) {
		const nextDirection = sortField === key && sortDirection === 'asc' ? 'desc' : 'asc';
		onSort( key, nextDirection );
	}

	function exportCsv() {
		setExporting( true );
		try {
			const header = [ ...COLUMNS.map( ( c ) => c.label ), __( 'Match Reason', 'beyond-elysium' ) ];
			const rows   = items.map( ( item ) => [
				...COLUMNS.map( ( c ) => String( item[ c.key ] ?? '' ) ),
				item.match_reason ?? '',
			] );
			const csv = [ header, ...rows ]
				.map( ( row ) => row.map( ( cell ) => `"${ String( cell ).replace( /"/g, '""' ) }"` ).join( ',' ) )
				.join( '\n' );

			const blob = new Blob( [ csv ], { type: 'text/csv' } );
			const url  = URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href     = url;
			link.download = 'query-results.csv';
			link.click();
			URL.revokeObjectURL( url );
		} finally {
			setExporting( false );
		}
	}

	const allOnPageSelected = items.length > 0 && items.every( ( i ) => selected.has( i.id ) );

	return (
		<div className="be-query-results">
			<div className="be-query-results__toolbar">
				<span>{ sprintf( __( '%d total', 'beyond-elysium' ), total ) }</span>
				<button type="button" onClick={ exportCsv } disabled={ exporting || items.length === 0 }>
					{ __( 'Export CSV', 'beyond-elysium' ) }
				</button>
			</div>

			{ items.length === 0 ? (
				<p>{ __( 'No characters match this query.', 'beyond-elysium' ) }</p>
			) : (
				<table className="be-query-results__table">
					<thead>
						<tr>
							{ canManage && (
								<th>
									<input
										type="checkbox"
										checked={ allOnPageSelected }
										onChange={ toggleSelectAllOnPage }
										aria-label={ __( 'Select all on this page', 'beyond-elysium' ) }
									/>
								</th>
							) }
							{ COLUMNS.map( ( col ) => (
								<th key={ col.key }>
									<button type="button" className="be-query-results__sort" onClick={ () => toggleSort( col.key ) }>
										{ col.label }
										{ sortField === col.key && ( sortDirection === 'asc' ? ' ▲' : ' ▼' ) }
									</button>
								</th>
							) ) }
							<th>{ __( 'Match Reason', 'beyond-elysium' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( item ) => (
							<tr key={ item.id }>
								{ canManage && (
									<td>
										<input
											type="checkbox"
											checked={ selected.has( item.id ) }
											onChange={ () => toggleSelected( item.id ) }
											aria-label={ sprintf( __( 'Select %s', 'beyond-elysium' ), item.name ) }
										/>
									</td>
								) }
								{ COLUMNS.map( ( col ) => (
									<td key={ col.key }>{ String( item[ col.key ] ?? '' ) }</td>
								) ) }
								<td>{ item.match_reason }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ canManage && selected.size > 0 && (
				<form className="be-query-results__bulk-award" onSubmit={ submitBulkAward }>
					<span>{ sprintf( __( '%d selected', 'beyond-elysium' ), selected.size ) }</span>
					<input
						type="number"
						value={ amount }
						onChange={ ( e ) => setAmount( e.target.value ) }
						placeholder={ __( 'XP amount', 'beyond-elysium' ) }
						aria-label={ __( 'XP amount to award', 'beyond-elysium' ) }
						required
					/>
					<input
						type="text"
						value={ reason }
						onChange={ ( e ) => setReason( e.target.value ) }
						placeholder={ __( 'Reason (required)', 'beyond-elysium' ) }
						aria-label={ __( 'Reason for this award', 'beyond-elysium' ) }
						required
					/>
					<button type="submit" disabled={ awarding }>
						{ __( 'Award XP to selected', 'beyond-elysium' ) }
					</button>
				</form>
			) }
			{ awardMessage && <p className="be-query-results__award-message" role="status">{ awardMessage }</p> }

			<div className="be-query-results__pagination">
				<button type="button" disabled={ page <= 1 } onClick={ () => onPageChange( page - 1 ) }>
					{ __( 'Previous', 'beyond-elysium' ) }
				</button>
				<span>{ sprintf( __( 'Page %d', 'beyond-elysium' ), page ) }</span>
				<button type="button" disabled={ page * perPage >= total } onClick={ () => onPageChange( page + 1 ) }>
					{ __( 'Next', 'beyond-elysium' ) }
				</button>
			</div>
		</div>
	);
}

export default QueryResults;
