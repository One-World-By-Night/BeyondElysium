/**
 * Admin screen for the 19 GV301 reports (reports-cards-batch-design.md).
 *
 * Loads the list of games for a chronicle picker, then the report registry
 * for the selected game, and offers a signed-PDF download link per report -
 * the same direct-link download pattern CharacterSheet.tsx already
 * established for the signed character sheet.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { useAdminGameSelector } from '../../lib/useAdminGameSelector';
import { GameFilterBar } from './GameFilterBar';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

interface ReportRow {
	key: string;
	title: string;
	shape: string;
	entity: string | null;
}

/**
 * Renders the Reports admin page. Fetches games and, once one is picked,
 * the report registry for it, offering a "Generate PDF" link per report.
 * The Statistics Report additionally takes a field name, since (unlike
 * Merits and Flaws/Influence) its statfield is chosen at generation time.
 */
export function AdminReports() {
	const { games, gameSlug, setGameSlug, loading } = useAdminGameSelector();
	const [ reportList, setReportList ] = useState< ReportRow[] >( [] );
	const [ statField, setStatField ] = useState( 'merits' );

	useEffect( () => {
		if ( ! gameSlug ) {
			return;
		}
		api.reports( gameSlug )
			.list()
			.then( setReportList )
			.catch( () => setReportList( [] ) );
	}, [ gameSlug ] );

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h1>{ __( 'Reports', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="reports" />
			</div>

			<GameFilterBar
				games={ games }
				gameSlug={ gameSlug }
				onGameChange={ setGameSlug }
				loading={ loading }
			>
				<table className="be-admin__table">
					<thead>
						<tr>
							<th>{ __( 'Report', 'beyond-elysium' ) }</th>
							<th>{ __( 'Shape', 'beyond-elysium' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ reportList.map( ( report ) => (
							<tr key={ report.key }>
								<td>{ report.title }</td>
								<td>{ report.shape }</td>
								<td>
									{ report.key === 'statistics-report' && (
										<select
											value={ statField }
											onChange={ ( e ) =>
												setStatField( e.target.value )
											}
											style={ {
												marginRight: '0.5rem',
											} }
										>
											<option value="merits">
												{ __(
													'Merits',
													'beyond-elysium'
												) }
											</option>
											<option value="flaws">
												{ __(
													'Flaws',
													'beyond-elysium'
												) }
											</option>
											<option value="influences">
												{ __(
													'Influences',
													'beyond-elysium'
												) }
											</option>
										</select>
									) }
									<a
										className="button"
										href={ api.reports( gameSlug ).pdfUrl(
											report.key,
											report.key === 'statistics-report'
												? {
														statField,
														statType:
															'distribution',
												  }
												: {}
										) }
									>
										{ __(
											'Generate PDF',
											'beyond-elysium'
										) }
									</a>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</GameFilterBar>
		</div>
	);
}

export default AdminReports;
