/**
 * Admin screen for the staff-facing Query Tool.
 *
 * Loads the list of games for a chronicle picker, then hands off to the
 * shared QueryTool widget for the selected game. Exports AdminQuery as
 * both a named and default export for use on the Beyond Elysium admin menu.
 */
import { __ } from '@wordpress/i18n';
import QueryTool from '../query/QueryTool';
import { useAdminGameSelector } from '../../lib/useAdminGameSelector';
import { GameFilterBar } from './GameFilterBar';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

/**
 * Renders the Query Tool admin page. Fetches the list of games, lets the
 * user pick one from a dropdown, and displays the QueryTool widget scoped
 * to that game's slug. Shows a loading state while games are being fetched
 * and a message prompting game creation when none exist.
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
