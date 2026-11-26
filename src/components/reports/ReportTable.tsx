/**
 * A report that is a table, on screen, with the link that prints it.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { TableDocument } from '../../types/reports';
import './ReportTable.css';

export interface ReportTableProps {
	gameSlug: string;
	reportKey: string;
	/**
	 * How a report that can be ordered is ordered.
	 */
	order?: string;
}

/**
 * Renders one table report for one chronicle.
 */
export function ReportTable( {
	gameSlug,
	reportKey,
	order,
}: ReportTableProps ) {
	const [ data, setData ] = useState< TableDocument | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		setData( null );
		setError( null );
		api.reports( gameSlug )
			.document( reportKey, { order } )
			.then( ( result ) => setData( result as unknown as TableDocument ) )
			.catch( () =>
				setError( __( 'Failed to load the report.', 'beyond-elysium' ) )
			);
	}, [ gameSlug, reportKey, order ] );

	if ( error ) {
		return (
			<div className="be-report-table__error" role="alert">
				{ error }
			</div>
		);
	}
	if ( ! data ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	return (
		<div className="be-report-table">
			<div className="be-report-table__head">
				<h3>{ data.title }</h3>
				<a
					className="be-report-table__print"
					href={ api
						.reports( gameSlug )
						.pdfUrl( reportKey, { order } ) }
				>
					{ __( 'Print', 'beyond-elysium' ) }
				</a>
			</div>
			{ data.rows.length === 0 ? (
				<p>{ data.note || __( 'No results.', 'beyond-elysium' ) }</p>
			) : (
				<div className="be-table-box">
					<table className="be-report-table__table be-responsive-table">
						<thead>
							<tr>
								{ data.columns.map( ( column ) => (
									<th key={ column }>{ column }</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ data.rows.map( ( row, index ) => (
								<tr key={ index }>
									{ row.map( ( cell, at ) => (
										<td
											key={ data.columns[ at ] }
											data-label={ data.columns[ at ] }
										>
											{ cell }
										</td>
									) ) }
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}

export default ReportTable;
