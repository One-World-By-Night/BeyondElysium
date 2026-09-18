/**
 * Storyteller Toolkit: My Queue (1.1.0 §3.6) - what the current viewer is personally on the
 * hook for in this chronicle: unanswered downtime assigned to them, ordinary plots waiting on
 * their own reply, their own NPC castings for sessions today or later (§3.8), and counts of
 * what nobody has claimed yet.
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
import './StaffQueue.css';

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
					<section className="be-staff-queue__section">
						<h3>{ __( 'Downtime', 'beyond-elysium' ) }</h3>
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
					</section>

					<section className="be-staff-queue__section">
						<h3>{ __( 'Plots', 'beyond-elysium' ) }</h3>
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
					</section>

					<section className="be-staff-queue__section">
						<h3>{ __( 'Castings', 'beyond-elysium' ) }</h3>
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
					</section>

					<section className="be-staff-queue__section">
						<h3>{ __( 'Unassigned', 'beyond-elysium' ) }</h3>
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
					</section>
				</>
			) }
		</div>
	);
}

export default StaffQueue;
