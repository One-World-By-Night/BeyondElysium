/**
 * Admin page for game night scheduling and sign-in.
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
				{ gameSlug && (
					<GameNights key={ gameSlug } gameSlug={ gameSlug } />
				) }
			</GameFilterBar>
		</div>
	);
}

export default AdminGameNights;
