/**
 * Admin page for managing World Objects (items and locations).
 */
import { __ } from '@wordpress/i18n';
import WorldObjectManager from '../world/WorldObjectManager';
import { useAdminGameSelector } from '../../lib/useAdminGameSelector';
import { GameFilterBar } from './GameFilterBar';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

/**
 * Renders the Items & Locations admin page.
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
