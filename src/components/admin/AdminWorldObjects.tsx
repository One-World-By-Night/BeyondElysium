/**
 * Admin page for managing World Objects (items and locations).
 *
 * Renders a game picker plus the shared WorldObjectManager widget, giving
 * staff a wp-admin entry point for creating and editing a chronicle's
 * items and locations.
 */
import { __ } from '@wordpress/i18n';
import WorldObjectManager from '../world/WorldObjectManager';
import { useAdminGameSelector } from '../../lib/useAdminGameSelector';
import { GameFilterBar } from './GameFilterBar';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

/**
 * Renders the Items & Locations admin page. Fetches the list of games,
 * lets the user pick one from a dropdown, and displays the
 * WorldObjectManager widget scoped to that game's slug. Shows a loading
 * state while games are being fetched and a message prompting game
 * creation when none exist.
 */
export function AdminWorldObjects() {
	const { games, gameSlug, setGameSlug, loading } = useAdminGameSelector();

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h1>{ __( 'Items & Locations', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="world-objects" />
			</div>

			<GameFilterBar
				games={ games }
				gameSlug={ gameSlug }
				onGameChange={ setGameSlug }
				loading={ loading }
			>
				{ /* key={gameSlug} remounts the manager so its internal state resets per game. */ }
				{ gameSlug && (
					<WorldObjectManager
						key={ gameSlug }
						gameSlug={ gameSlug }
						showEditor
					/>
				) }
			</GameFilterBar>
		</div>
	);
}

export default AdminWorldObjects;
