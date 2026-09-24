/**
 * Admin page for release batches.
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
				{ gameSlug && (
					<ReleaseBatches key={ gameSlug } gameSlug={ gameSlug } />
				) }
			</GameFilterBar>
		</div>
	);
}

export default AdminReleaseBatches;
