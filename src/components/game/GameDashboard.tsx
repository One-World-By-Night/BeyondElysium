/**
 * Game landing page.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import api from '../../api/client';
import { PlayerDashboard } from './PlayerDashboard';
import { GameStats } from './GameStats';
import { canIn } from '../../lib/chronicleCapabilities';
import type { MyCapabilities } from '../../types';
import HelpButton from '../shared/HelpButton';
import './GameDashboard.css';

export interface GameDashboardProps {
	gameSlug: string;
	sheetPageUrl?: string;
	approvalQueueUrl?: string;
	rosterUrl?: string;
	plotsUrl?: string;
	/**
	 * What the person can do in this chronicle, when the page resolved it.
	 */
	capabilities?: MyCapabilities;
}

/**
 * Renders the game landing page.
 */
export function GameDashboard( {
	gameSlug,
	sheetPageUrl,
	approvalQueueUrl,
	rosterUrl,
	plotsUrl,
	capabilities,
}: GameDashboardProps ) {
	const canManage = canIn( 'be_manage_characters', capabilities );

	// My Queue's own two assigned-to-me sections.
	const [ waitingOnMe, setWaitingOnMe ] = useState< number | null >( null );

	useEffect( () => {
		if ( ! canManage ) {
			return;
		}
		api.myQueue( gameSlug )
			.get()
			.then( ( result ) =>
				setWaitingOnMe( result.downtime.length + result.plots.length )
			)
			.catch( () => setWaitingOnMe( null ) );
	}, [ gameSlug, canManage ] );

	if ( ! canManage ) {
		return (
			<PlayerDashboard
				gameSlug={ gameSlug }
				sheetPageUrl={ sheetPageUrl }
			/>
		);
	}

	const links = [
		approvalQueueUrl && {
			label: __( 'Approval Queue', 'beyond-elysium' ),
			href: approvalQueueUrl,
		},
		rosterUrl && {
			label: __( 'Characters', 'beyond-elysium' ),
			href: rosterUrl,
		},
		plotsUrl && {
			label: __( 'Plots & Rumors', 'beyond-elysium' ),
			href: plotsUrl,
		},
	].filter( Boolean ) as { label: string; href: string }[];

	return (
		<div className="be-game-dashboard">
			<div className="be-help-heading">
				<h2>{ __( 'Dashboard', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="game-dashboard" />
			</div>
			<GameStats
				gameSlug={ gameSlug }
				links={ links }
				waitingOnMe={ waitingOnMe }
			/>
		</div>
	);
}

export default GameDashboard;
