/**
 * Admin screen for the staff-facing Query Tool.
 */
import { __ } from '@wordpress/i18n';
import QueryTool from '../query/QueryTool';
import { useAdminGameSelector } from '../../lib/useAdminGameSelector';
import { GameFilterBar } from './GameFilterBar';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

/**
 * Renders the Query Tool admin page.
 */
export function AdminQuery() {
	const { games, gameSlug, setGameSlug, loading } = useAdminGameSelector();

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h1>{ __( 'Query Tool', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="query-tool" />
			</div>

			<GameFilterBar
				games={ games }
				gameSlug={ gameSlug }
				onGameChange={ setGameSlug }
				loading={ loading }
			>
				{ gameSlug && <QueryTool gameSlug={ gameSlug } /> }
			</GameFilterBar>
		</div>
	);
}

export default AdminQuery;
