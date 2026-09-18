/**
 * Admin page for game night scheduling and sign-in (1.1.0 §3.1).
 * Renders a chronicle picker plus the shared GameNights component,
 * giving staff a dedicated wp-admin surface alongside the front-end
 * Storyteller Toolkit tab.
 */
import { __ } from '@wordpress/i18n';
import GameNights from '../game/GameNights';
import { useAdminGameSelector } from '../../lib/useAdminGameSelector';
import { GameFilterBar } from './GameFilterBar';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

export function AdminGameNights() {
	const { games, gameSlug, setGameSlug, loading } = useAdminGameSelector();

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h1>{ __( 'Game Nights', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="admin-game-nights" />
			</div>

			<GameFilterBar
				games={ games }
				gameSlug={ gameSlug }
				onGameChange={ setGameSlug }
				loading={ loading }
			>
				{ /* key={gameSlug} remounts GameNights so its internal state resets when the
				 * chronicle changes. No capabilities prop, matching AdminPlots.tsx: GameNights'
				 * own canIn() falls back to the site-wide window.beyondElysium.capabilities
				 * snapshot, which add_submenu_page()'s own be_manage_sessions gate already
				 * guarantees true for anyone who could open this page at all. */ }
				{ gameSlug && (
					<GameNights key={ gameSlug } gameSlug={ gameSlug } />
				) }
			</GameFilterBar>
		</div>
	);
}

export default AdminGameNights;
