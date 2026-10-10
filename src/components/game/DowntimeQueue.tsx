/**
 * Storyteller Toolkit: the downtime queue.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { canIn } from '../../lib/chronicleCapabilities';
import { queueChargeLabel } from '../../lib/answerCharge';
import {
	characterSheetUrl,
	storytellerTabUrl,
	STORYTELLER_TABS,
} from '../../lib/pluginPages';
import { AssigneePicker } from '../shared/AssigneePicker';
import TabStrip from '../shared/TabStrip';
import type { MyCapabilities } from '../../types';
import type { GameSession } from '../../types/session';
import type { DowntimeQueueRow } from '../../types/downtime';
import type { StaffMember } from '../../types/staffQueue';
import HelpButton from '../shared/HelpButton';
import './DowntimeQueue.css';

export interface DowntimeQueueProps {
	gameSlug: string;
	capabilities?: MyCapabilities;
}

import { errorMessage } from '../../lib/errorMessage';

const RELEASE_STATE_LABELS: Record< string, string > = {
	not_answered: __( 'Not answered', 'beyond-elysium' ),
	immediate: __( 'Posted', 'beyond-elysium' ),
	draft: __( 'Draft', 'beyond-elysium' ),
	scheduled: __( 'Scheduled', 'beyond-elysium' ),
	released: __( 'Released', 'beyond-elysium' ),
};

const WINDOW_STATE_LABELS: Record< string, string > = {
	none: __( 'No window', 'beyond-elysium' ),
	not_open: __( 'Not open yet', 'beyond-elysium' ),
	open: __( 'Open', 'beyond-elysium' ),
	closed: __( 'Closed', 'beyond-elysium' ),
};

/**
 * Where one connected entity links to: a character or NPC's own sheet, or the Items & Locations tab for an item or
 * location (there is no per-object deep link).
 */
function connectionUrl(
	connection: DowntimeQueueRow[ 'connections' ][ number ],
	gameSlug: string
): string {
	if ( connection.type === 'character' || connection.type === 'npc' ) {
		return characterSheetUrl( connection.id, gameSlug );
	}
	return storytellerTabUrl( STORYTELLER_TABS.worldObjects );
}

/**
 * "closes in 3 days" / "closed 2 days ago" from a deadline string, or null with nothing to say.
 */
function relativeDeadline( deadline: string | null ): string | null {
	if ( ! deadline ) {
		return null;
	}
	const then = new Date( deadline.replace( ' ', 'T' ) ).getTime();
	if ( Number.isNaN( then ) ) {
		return null;
	}
	const days = Math.round( ( then - Date.now() ) / ( 24 * 60 * 60 * 1000 ) );
	if ( days > 0 ) {
		return sprintf(
			/* translators: %d: number of days */
			__( 'closes in %d day(s)', 'beyond-elysium' ),
			days
		);
	}
	if ( days < 0 ) {
		return sprintf(
			/* translators: %d: number of days */
			__( 'closed %d day(s) ago', 'beyond-elysium' ),
			Math.abs( days )
		);
	}
	return __( 'closes today', 'beyond-elysium' );
}

export function DowntimeQueue( {
	gameSlug,
	capabilities,
}: DowntimeQueueProps ) {
	const canManage = canIn( 'be_manage_plots', capabilities );

	const [ sessions, setSessions ] = useState< GameSession[] >( [] );
	const [ gameDate, setGameDate ] = useState( '' );
	const [ rows, setRows ] = useState< DowntimeQueueRow[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ filter, setFilter ] = useState< 'unanswered' | 'all' >(
		'unanswered'
	);
	const [ staff, setStaff ] = useState< StaffMember[] >( [] );
	const [ assigneeFilter, setAssigneeFilter ] = useState( 'all' );
	// This arrives as the string "1", not the number 1.
	const currentUserId = Number( window.beyondElysium?.currentUserId ?? 0 );

	useEffect( () => {
		api.myQueue( gameSlug )
			.staff()
			.then( setStaff )
			.catch( () => setStaff( [] ) );
	}, [ gameSlug ] );

	useEffect( () => {
		api.sessions( gameSlug )
			.list()
			.then( ( result ) => {
				setSessions( result );
				if ( result.length > 0 ) {
					setGameDate( result[ result.length - 1 ].game_date );
				}
				setLoading( false );
			} )
			.catch( () => {
				setError(
					__( 'Failed to load game sessions.', 'beyond-elysium' )
				);
				setLoading( false );
			} );
	}, [ gameSlug ] );

	function loadQueue() {
		if ( ! gameDate ) {
			return;
		}
		setLoading( true );
		setError( null );
		api.downtime( gameSlug )
			.queue( gameDate )
			.then( ( result ) => {
				setRows( result );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					errorMessage(
						err,
						__(
							'Failed to load the downtime queue.',
							'beyond-elysium'
						)
					)
				);
				setLoading( false );
			} );
	}

	useEffect( loadQueue, [ gameSlug, gameDate ] );

	function assign( plotId: number, assignedTo: number | null ) {
		setRows( ( prev ) =>
			prev.map( ( r ) =>
				r.plot_id === plotId ? { ...r, assigned_to: assignedTo } : r
			)
		);
		api.plots( gameSlug )
			.update( plotId, { assigned_to: assignedTo } )
			.catch( loadQueue );
	}

	if ( ! canManage ) {
		return (
			<div className="be-downtime-queue">
				<div className="be-downtime-queue__denied">
					<h2>{ __( 'Storytellers only', 'beyond-elysium' ) }</h2>
					<p>
						{ __(
							'The downtime queue is run by your Storytellers and Narrators.',
							'beyond-elysium'
						) }
					</p>
				</div>
			</div>
		);
	}

	const session = sessions.find( ( s ) => s.game_date === gameDate ) ?? null;
	const deadlineNote = session
		? relativeDeadline( session.downtime_deadline_at )
		: null;
	const visibleRows = rows
		.filter( ( r ) => filter === 'all' || ! r.answered )
		.filter( ( r ) => {
			if ( assigneeFilter === 'all' ) {
				return true;
			}
			if ( assigneeFilter === 'me' ) {
				return r.assigned_to === currentUserId;
			}
			return r.assigned_to === Number( assigneeFilter );
		} );

	return (
		<div className="be-downtime-queue">
			<div className="be-help-heading">
				<h2>{ __( 'Downtime', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="downtime-queue" />
			</div>

			{ error && (
				<div className="be-downtime-queue__error" role="alert">
					{ error }
				</div>
			) }

			<div className="be-downtime-queue__filters">
				<label>
					{ __( 'Game date', 'beyond-elysium' ) }{ ' ' }
					<select
						value={ gameDate }
						onChange={ ( e ) => setGameDate( e.target.value ) }
					>
						{ sessions.map( ( s ) => (
							<option key={ s.game_date } value={ s.game_date }>
								{ s.game_date }
							</option>
						) ) }
					</select>
				</label>
				{ deadlineNote && (
					<span className="be-st-badge">{ deadlineNote }</span>
				) }
				<TabStrip
					tabs={ [
						{
							key: 'unanswered',
							label: __( 'Unanswered', 'beyond-elysium' ),
						},
						{ key: 'all', label: __( 'All', 'beyond-elysium' ) },
					] }
					active={ filter }
					onChange={ ( key ) =>
						setFilter( key as 'unanswered' | 'all' )
					}
				/>
				<label>
					{ __( 'Assigned to', 'beyond-elysium' ) }{ ' ' }
					<select
						value={ assigneeFilter }
						onChange={ ( e ) =>
							setAssigneeFilter( e.target.value )
						}
					>
						<option value="all">
							{ __( 'All', 'beyond-elysium' ) }
						</option>
						<option value="me">
							{ __( 'Assigned to me', 'beyond-elysium' ) }
						</option>
						{ staff.map( ( member ) => (
							<option key={ member.id } value={ member.id }>
								{ member.name }
							</option>
						) ) }
					</select>
				</label>
			</div>

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : ! gameDate ? (
				<p>
					{ __(
						'No game sessions exist yet - create one under Game Nights first.',
						'beyond-elysium'
					) }
				</p>
			) : visibleRows.length === 0 ? (
				<p>
					{ filter === 'unanswered'
						? __(
								'Nothing unanswered for this date.',
								'beyond-elysium'
							)
						: __(
								'No action plots for this date.',
								'beyond-elysium'
							) }
				</p>
			) : (
				<ul className="be-downtime-queue__list">
					{ visibleRows.map( ( row ) => (
						<li key={ row.plot_id }>
							<a
								className="be-downtime-queue__row"
								href={ `${ storytellerTabUrl(
									STORYTELLER_TABS.plots
								) }&open_plot=${ row.plot_id }` }
							>
								<strong>{ row.character_name }</strong>
								{ row.player_name && (
									<span className="be-downtime-queue__player">
										{ row.player_name }
									</span>
								) }
								<span>
									{ sprintf(
										/* translators: %d: number of actions */
										__( '%d action(s)', 'beyond-elysium' ),
										row.action_count
									) }
								</span>
								<span className="be-st-badge">
									{ row.answered
										? __( 'Answered', 'beyond-elysium' )
										: __( 'Unanswered', 'beyond-elysium' ) }
								</span>
								{ row.answered && (
									<span className="be-st-badge be-st-badge--held">
										{ RELEASE_STATE_LABELS[
											row.answer_release_state
										] ?? row.answer_release_state }
									</span>
								) }
								{ queueChargeLabel( row.charge ) && (
									<span className="be-st-badge be-st-badge--charge">
										{ queueChargeLabel( row.charge ) }
									</span>
								) }
								<span className="be-st-badge be-st-badge--audience">
									{ WINDOW_STATE_LABELS[ row.window_state ] ??
										row.window_state }
								</span>
							</a>
							{ row.connections.length > 0 && (
								<span className="be-downtime-queue__connections">
									{ row.connections.map( ( connection ) => (
										<a
											key={ `${ connection.type }-${ connection.id }` }
											href={ connectionUrl(
												connection,
												gameSlug
											) }
											className={ `be-downtime-queue__connection be-downtime-queue__connection--${ connection.type }` }
										>
											{ connection.name }
										</a>
									) ) }
								</span>
							) }
							<span className="be-downtime-queue__assignee">
								<AssigneePicker
									gameSlug={ gameSlug }
									value={ row.assigned_to }
									onChange={ ( assignedTo ) =>
										assign( row.plot_id, assignedTo )
									}
								/>
							</span>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

export default DowntimeQueue;
