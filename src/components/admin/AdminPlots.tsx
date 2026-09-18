/**
 * Admin page for the staff plot dashboard.
 * Renders a chronicle picker plus the shared PlotManager component,
 * giving staff a dedicated wp-admin surface for managing a chronicle's
 * plot threads.
 */
import { __ } from '@wordpress/i18n';
import PlotManager from '../apr/PlotManager';
import { useAdminGameSelector } from '../../lib/useAdminGameSelector';
import { GameFilterBar } from './GameFilterBar';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

/**
 * Renders the Plots admin screen.
 * Lets staff pick a chronicle and manage its plot threads through the
 * shared PlotManager component, which is scoped to one chronicle at a
 * time.
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
