/**
 * Game landing page. Renders one of two dashboards depending on the viewer's
 * capabilities: an aggregate statistics view for managers, or the player-facing
 * PlayerDashboard for everyone else. The capability check is a display choice
 * only - every underlying route re-checks access on its own.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import api from '../../api/client';
import { describeChange } from '../../lib/describeChange';
import { PlayerDashboard } from './PlayerDashboard';
import type { GameStats } from '../../types';
import './GameDashboard.css';

export interface GameDashboardProps {
	gameSlug: string;
	sheetPageUrl?: string;
	approvalQueueUrl?: string;
	rosterUrl?: string;
	plotsUrl?: string;
}

interface RestError {
	message?: string;
}

/**
 * Extracts a human-readable message from a caught API error. Returns the error's own
 * message when present, otherwise falls back to a generic failure message so the UI
 * always has something readable to display.
 */
function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null && ( error as RestError ).message ) {
		return ( error as RestError ).message as string;
	}
	return __( 'Failed to load the dashboard.', 'beyond-elysium' );
}

function sumCounts( counts: Record<string, number> ): number {
	return Object.values( counts ).reduce( ( total, n ) => total + n, 0 );
}

/**
 * Renders the game landing page. Managers see an aggregate dashboard of character, change,
 * and plot statistics with links to the approval queue, roster, and plots pages; everyone
 * else sees PlayerDashboard instead. The choice of view is a UI affordance only - each
 * underlying REST route enforces its own permission check independently.
 */
export function GameDashboard( { gameSlug, sheetPageUrl, approvalQueueUrl, rosterUrl, plotsUrl }: GameDashboardProps ) {
	const canManage = window.beyondElysium?.capabilities?.be_manage_characters ?? false;

	const [ stats, setStats ] = useState<GameStats | null>( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );

	useEffect( () => {
		if ( ! canManage ) {
			return;
		}
		setLoading( true );
		api
			.gameStats( gameSlug )
			.get()
			.then( ( result ) => {
				setStats( result );
				setLoading( false );
			} )
			.catch( ( err: unknown ) => {
				setError( errorMessage( err ) );
				setLoading( false );
			} );
	}, [ gameSlug, canManage ] );

	if ( ! canManage ) {
		return <PlayerDashboard gameSlug={ gameSlug } sheetPageUrl={ sheetPageUrl } />;
	}

	const links = [
		approvalQueueUrl && { label: __( 'Approval Queue', 'beyond-elysium' ), href: approvalQueueUrl },
		rosterUrl && { label: __( 'Characters', 'beyond-elysium' ), href: rosterUrl },
		plotsUrl && { label: __( 'Plots & Rumors', 'beyond-elysium' ), href: plotsUrl },
	].filter( Boolean ) as { label: string; href: string }[];

	return (
		<div className="be-game-dashboard">
			{ error && (
				<div className="be-game-dashboard__error" role="alert">
					{ error }
				</div>
			) }

			{ loading || ! stats ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : (
				<>
					<div className="be-game-dashboard__cards">
						<div className="be-game-dashboard__card">
							<span className="be-game-dashboard__card-value">{ sumCounts( stats.characters_by_stack ) }</span>
							<span className="be-game-dashboard__card-label">{ __( 'Characters', 'beyond-elysium' ) }</span>
							<ul className="be-game-dashboard__breakdown">
								{ Object.entries( stats.characters_by_stack ).map( ( [ stack, count ] ) => (
									<li key={ stack }>
										{ stack }: { count }
									</li>
								) ) }
							</ul>
						</div>

						<div className="be-game-dashboard__card">
							<span className="be-game-dashboard__card-value">{ stats.pending_changes }</span>
							<span className="be-game-dashboard__card-label">{ __( 'Pending Changes', 'beyond-elysium' ) }</span>
						</div>

						<div className="be-game-dashboard__card">
							<span className="be-game-dashboard__card-value">{ stats.active_plots }</span>
							<span className="be-game-dashboard__card-label">{ __( 'Active Plots', 'beyond-elysium' ) }</span>
						</div>

						<div className="be-game-dashboard__card">
							<span className="be-game-dashboard__card-value">{ sumCounts( stats.characters_by_status ) }</span>
							<span className="be-game-dashboard__card-label">{ __( 'By Status', 'beyond-elysium' ) }</span>
							<ul className="be-game-dashboard__breakdown">
								{ Object.entries( stats.characters_by_status ).map( ( [ status, count ] ) => (
									<li key={ status }>
										{ status }: { count }
									</li>
								) ) }
							</ul>
						</div>
					</div>

					{ links.length > 0 && (
						<nav className="be-game-dashboard__links">
							{ links.map( ( link ) => (
								<a key={ link.href } href={ link.href }>
									{ link.label }
								</a>
							) ) }
						</nav>
					) }

					<section className="be-game-dashboard__section">
						<h2>{ __( 'Recent Activity', 'beyond-elysium' ) }</h2>
						{ stats.recent_activity.length === 0 ? (
							<p>{ __( 'Nothing yet.', 'beyond-elysium' ) }</p>
						) : (
							<ul className="be-game-dashboard__list">
								{ stats.recent_activity.map( ( change ) => (
									<li key={ change.id }>
										{ change.character_name ?? `#${ change.character_id }` } —{ ' ' }
										{ describeChange( change.change_type, change.change_data ) }{ ' ' }
										<span className="be-game-dashboard__badge">{ change.status }</span>
									</li>
								) ) }
							</ul>
						) }
					</section>
				</>
			) }
		</div>
	);
}

export default GameDashboard;
