/**
 * Storyteller Toolkit: My Queue.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { canAny } from '../../lib/chronicleCapabilities';
import { storytellerTabUrl, STORYTELLER_TABS } from '../../lib/pluginPages';
import { CastingBrief } from './CastingBrief';
import type { MyCapabilities } from '../../types';
import type { StaffQueue as StaffQueueData } from '../../types/staffQueue';
import HelpButton from '../shared/HelpButton';
import CollapsiblePanel from '../shared/CollapsiblePanel';
import './StaffQueue.css';

/**
 * A queue's summary row keeps its count visible when folded.
 */
function queueHeading( label: string, count: number ): string {
	return count === 0
		? label
		: sprintf(
				/* translators: 1: queue name, 2: number of items waiting in it */
				__( '%1$s (%2$d)', 'beyond-elysium' ),
				label,
				count
		  );
}

export interface StaffQueueProps {
	gameSlug: string;
	capabilities?: MyCapabilities;
}

import { errorMessage } from '../../lib/errorMessage';

const EMPTY: StaffQueueData = {
	downtime: [],
	plots: [],
	castings: [],
	unassigned: { downtime: 0, plots: 0 },
};

export function StaffQueue( { gameSlug, capabilities }: StaffQueueProps ) {
	const canView = canAny(
		[ 'be_manage_plots', 'be_manage_characters' ],
		capabilities
	);

	const [ data, setData ] = useState< StaffQueueData >( EMPTY );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ openCastingId, setOpenCastingId ] = useState< number | null >(
		null
	);

	useEffect( () => {
		if ( ! canView ) {
			return;
		}
		setLoading( true );
		setError( null );
		api.myQueue( gameSlug )
			.get()
			.then( ( result ) => {
				setData( result );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					errorMessage(
						err,
						__( 'Failed to load My Queue.', 'beyond-elysium' )
					)
				);
				setLoading( false );
			} );
	}, [ gameSlug, canView ] );

	if ( ! canView ) {
		return (
			<div className="be-staff-queue">
				<div className="be-staff-queue__denied">
					<h2>{ __( 'Storytellers only', 'beyond-elysium' ) }</h2>
					<p>
						{ __(
							'My Queue is run by your Storytellers and Narrators.',
							'beyond-elysium'
						) }
					</p>
				</div>
			</div>
		);
	}

	if ( openCastingId !== null ) {
		return (
			<div className="be-staff-queue">
				<CastingBrief
					gameSlug={ gameSlug }
					castingId={ openCastingId }
					onClose={ () => setOpenCastingId( null ) }
				/>
			</div>
		);
	}

	return (
		<div className="be-staff-queue">
			<div className="be-help-heading">
				<h2>{ __( 'My Queue', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="my-queue" />
			</div>

			{ error && (
				<div className="be-staff-queue__error" role="alert">
					{ error }
				</div>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : (
				<>
					<CollapsiblePanel
						id="staff-queue:downtime"
						className="be-staff-queue__section"
						heading={
							<h3>
								{ queueHeading(
									__( 'Downtime', 'beyond-elysium' ),
									data.downtime.length
								) }
							</h3>
						}
					>
						{ data.downtime.length === 0 ? (
							<p>
								{ __(
									'Nothing unanswered is assigned to you.',
									'beyond-elysium'
								) }
							</p>
						) : (
							<ul className="be-staff-queue__list">
								{ data.downtime.map( ( row ) => (
									<li key={ row.plot_id }>
										<a
											href={ `${ storytellerTabUrl(
												STORYTELLER_TABS.plots
											) }&open_plot=${ row.plot_id }` }
										>
											<strong>
												{ row.character_name }
											</strong>
											{ row.game_date && (
												<span className="be-st-badge">
													{ row.game_date }
												</span>
											) }
											<span>
												{ sprintf(
													/* translators: %d: number of actions */
													__(
														'%d action(s)',
														'beyond-elysium'
													),
													row.action_count
												) }
											</span>
										</a>
									</li>
								) ) }
							</ul>
						) }
					</CollapsiblePanel>

					<CollapsiblePanel
						id="staff-queue:plots"
						className="be-staff-queue__section"
						heading={
							<h3>
								{ queueHeading(
									__( 'Plots', 'beyond-elysium' ),
									data.plots.length
								) }
							</h3>
						}
					>
						{ data.plots.length === 0 ? (
							<p>
								{ __(
									'No plots assigned to you are waiting on a reply.',
									'beyond-elysium'
								) }
							</p>
						) : (
							<ul className="be-staff-queue__list">
								{ data.plots.map( ( row ) => (
									<li key={ row.plot_id }>
										<a
											href={ `${ storytellerTabUrl(
												STORYTELLER_TABS.plots
											) }&open_plot=${ row.plot_id }` }
										>
											<strong>{ row.title }</strong>
										</a>
									</li>
								) ) }
							</ul>
						) }
					</CollapsiblePanel>

					<CollapsiblePanel
						id="staff-queue:castings"
						className="be-staff-queue__section"
						heading={
							<h3>
								{ queueHeading(
									__( 'Castings', 'beyond-elysium' ),
									data.castings.length
								) }
							</h3>
						}
					>
						{ data.castings.length === 0 ? (
							<p>
								{ __(
									"You aren't cast to play any NPC for an upcoming session.",
									'beyond-elysium'
								) }
							</p>
						) : (
							<ul className="be-staff-queue__list">
								{ data.castings.map( ( row ) => (
									<li key={ row.casting_id }>
										<button
											type="button"
											className="be-staff-queue__link-button"
											onClick={ () =>
												setOpenCastingId(
													row.casting_id
												)
											}
										>
											<strong>
												{ row.character_name }
											</strong>
											<span className="be-st-badge">
												{ row.game_date }
											</span>
										</button>
									</li>
								) ) }
							</ul>
						) }
					</CollapsiblePanel>

					<CollapsiblePanel
						id="staff-queue:unassigned"
						className="be-staff-queue__section"
						heading={
							<h3>
								{ queueHeading(
									__( 'Unassigned', 'beyond-elysium' ),
									data.unassigned.downtime +
										data.unassigned.plots
								) }
							</h3>
						}
					>
						<p>
							{ sprintf(
								/* translators: 1: unanswered downtime count, 2: unanswered plot count */
								__(
									'%1$d unanswered downtime, %2$d plot(s) waiting, with nobody assigned.',
									'beyond-elysium'
								),
								data.unassigned.downtime,
								data.unassigned.plots
							) }
						</p>
					</CollapsiblePanel>
				</>
			) }
		</div>
	);
}

export default StaffQueue;
