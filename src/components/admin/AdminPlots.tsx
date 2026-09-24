/**
 * Admin page for the staff plot dashboard.
 */
import { __ } from '@wordpress/i18n';
import PlotManager from '../apr/PlotManager';
import { useAdminGameSelector } from '../../lib/useAdminGameSelector';
import { GameFilterBar } from './GameFilterBar';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

/**
 * Renders the Plots admin screen.
 */
export function AdminPlots() {
	const { games, gameSlug, setGameSlug, loading } = useAdminGameSelector();

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h1>{ __( 'Plots', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="admin-plots" />
			</div>

			<GameFilterBar
				games={ games }
				gameSlug={ gameSlug }
				onGameChange={ setGameSlug }
				loading={ loading }
			>
				{ /* key={gameSlug} remounts PlotManager so its internal state resets when the chronicle changes. */ }
				{ gameSlug && (
					<PlotManager key={ gameSlug } gameSlug={ gameSlug } />
				) }
			</GameFilterBar>
		</div>
	);
}

export default AdminPlots;
