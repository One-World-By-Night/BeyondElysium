/**
 * The reports a Storyteller may run in one chronicle, each with what it holds and a link that prints it.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { ReportRow } from '../../api/client';
import HelpButton from '../shared/HelpButton';
import ReportTable from './ReportTable';
import { reportShapeLabel } from '../../lib/reportShape';
import './ToolkitReports.css';

export interface ToolkitReportsProps {
	gameSlug: string;
}

const STATISTICS = [ 'merits', 'flaws', 'influences' ] as const;

/**
 * Renders the reports list for one chronicle.
 */
export function ToolkitReports( { gameSlug }: ToolkitReportsProps ) {
	const [ rows, setRows ] = useState< ReportRow[] | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ statField, setStatField ] = useState< string >( 'merits' );
	const [ order, setOrder ] = useState( 'character' );
	const [ viewing, setViewing ] = useState< string | null >( null );

	useEffect( () => {
		setRows( null );
		api.reports( gameSlug )
			.list()
			.then( setRows )
			.catch( () =>
				setError(
					__( 'Failed to load the reports.', 'beyond-elysium' )
				)
			);
	}, [ gameSlug ] );

	return (
		<div className="be-toolkit-reports">
			<div className="be-help-heading">
				<h2>{ __( 'Reports', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="reports" />
			</div>
			{ error && (
				<div className="be-game-dashboard__error" role="alert">
					{ error }
				</div>
			) }
			{ ! rows && ! error && (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) }
			{ rows && rows.length === 0 && (
				<p>
					{ __(
						'No reports are open to you here.',
						'beyond-elysium'
					) }
				</p>
			) }
			{ rows && rows.length > 0 && (
				<ul className="be-toolkit-reports__list">
					{ rows.map( ( report ) => (
						<li
							key={ report.key }
							className="be-toolkit-reports__item"
						>
							<div className="be-toolkit-reports__text">
								<strong>{ report.title }</strong>
								<span className="be-toolkit-reports__shape">
									{ reportShapeLabel( report.shape ) }
								</span>
								{ report.summary && <p>{ report.summary }</p> }
							</div>
							<div className="be-toolkit-reports__controls">
								{ report.key === 'statistics-report' && (
									<label>
										<span>
											{ __( 'Count', 'beyond-elysium' ) }
										</span>
										<select
											value={ statField }
											onChange={ ( e ) =>
												setStatField( e.target.value )
											}
										>
											{ STATISTICS.map( ( field ) => (
												<option
													key={ field }
													value={ field }
												>
													{
														{
															merits: __(
																'Merits',
																'beyond-elysium'
															),
															flaws: __(
																'Flaws',
																'beyond-elysium'
															),
															influences: __(
																'Influences',
																'beyond-elysium'
															),
														}[ field ]
													}
												</option>
											) ) }
										</select>
									</label>
								) }
								{ report.key === 'bonds' && (
									<>
										<label>
											<span>
												{ __(
													'Order by',
													'beyond-elysium'
												) }
											</span>
											<select
												value={ order }
												onChange={ ( e ) =>
													setOrder( e.target.value )
												}
											>
												<option value="character">
													{ __(
														'Character',
														'beyond-elysium'
													) }
												</option>
												<option value="bonded_to">
													{ __(
														'Bonded to',
														'beyond-elysium'
													) }
												</option>
											</select>
										</label>
										<button
											type="button"
											className="be-toolkit-reports__button"
											aria-pressed={ viewing === 'bonds' }
											onClick={ () =>
												setViewing(
													viewing === 'bonds'
														? null
														: 'bonds'
												)
											}
										>
											{ viewing === 'bonds'
												? __( 'Hide', 'beyond-elysium' )
												: __(
														'View',
														'beyond-elysium'
													) }
										</button>
									</>
								) }
								<a
									className="be-toolkit-reports__print"
									href={ api
										.reports( gameSlug )
										.pdfUrl( report.key, {
											...( report.key ===
											'statistics-report'
												? {
														statField,
														statType:
															'distribution',
													}
												: {} ),
											...( report.key === 'bonds'
												? { order }
												: {} ),
										} ) }
								>
									{ __( 'Print', 'beyond-elysium' ) }
								</a>
							</div>
							{ report.key === 'bonds' && viewing === 'bonds' && (
								<div className="be-toolkit-reports__view">
									<ReportTable
										gameSlug={ gameSlug }
										reportKey="bonds"
										order={ order }
									/>
								</div>
							) }
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

export default ToolkitReports;
