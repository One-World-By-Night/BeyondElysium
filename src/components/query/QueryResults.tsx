/**
 * QueryResults renders a paginated, sortable table of character query results,
 * with a per-row match-reason column and a CSV export button. Used by QueryTool's
 * Search tab to display the output of a run query. The CSV export covers only
 * the currently loaded page of rows, not the full result set.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import type { QueryResultCharacter } from '../../types/query';
import './QueryResults.css';

export interface QueryResultsProps {
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
export function QueryResults( { items, total, page, perPage, onPageChange, onSort, sortField, sortDirection }: QueryResultsProps ) {
	const [ exporting, setExporting ] = useState( false );

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
								{ COLUMNS.map( ( col ) => (
									<td key={ col.key }>{ String( item[ col.key ] ?? '' ) }</td>
								) ) }
								<td>{ item.match_reason }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

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
