/**
 * Admin page for release batches (1.1.0 §3.2). Renders a chronicle picker plus the
 * shared ReleaseBatches component - one tab of the wp-admin Plots hub.
 */
import { __ } from '@wordpress/i18n';
import ReleaseBatches from '../game/ReleaseBatches';
import { useAdminGameSelector } from '../../lib/useAdminGameSelector';
import { GameFilterBar } from './GameFilterBar';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

export function AdminReleaseBatches() {
	const { games, gameSlug, setGameSlug, loading } = useAdminGameSelector();

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h1>{ __( 'Releases', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="admin-release-batches" />
			</div>

			<GameFilterBar
				games={ games }
				gameSlug={ gameSlug }
				onGameChange={ setGameSlug }
				loading={ loading }
			>
				{ /* key={gameSlug} remounts ReleaseBatches so its internal state resets when
				 * the chronicle changes. No capabilities prop, matching AdminPlots.tsx: canIn()
				 * falls back to the site-wide window.beyondElysium.capabilities snapshot, which
				 * this page's own be_manage_plots gate already guarantees true for anyone who
				 * could open it at all. */ }
				{ gameSlug && (
					<ReleaseBatches key={ gameSlug } gameSlug={ gameSlug } />
				) }
			</GameFilterBar>
		</div>
	);
}

export default AdminReleaseBatches;
