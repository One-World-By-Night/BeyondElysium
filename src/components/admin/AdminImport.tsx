/**
 * Admin page for importing chronicle content.
 * Combines the GEX character/world-object importer and the full .gv3
 * chronicle game-file importer into one page, switching between them
 * with tabs gated on the viewer's import capabilities.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import ImportTool from '../import/ImportTool';
import GameImportTool from '../import/GameImportTool';
import type { Game } from '../../types';
import './Admin.css';

type Tab = 'gex' | 'gv3';

/**
 * Renders the Import admin screen.
 * Hosts two import tools behind tabs - character and world-object GEX
 * files, and full chronicle .gv3 game files - showing only the tabs the
 * viewer's capabilities allow.
 */
export function AdminImport() {
	const [ games, setGames ] = useState<Game[]>( [] );
	const [ gameSlug, setGameSlug ] = useState( '' );
	const [ loading, setLoading ] = useState( true );

	const canImportGex = window.beyondElysium?.capabilities?.be_import ?? false;
	const canImportGameFile = window.beyondElysium?.capabilities?.be_manage_games ?? false;
	const [ tab, setTab ] = useState<Tab>( canImportGex ? 'gex' : 'gv3' );

	useEffect( () => {
		if ( ! canImportGex ) {
			setLoading( false );
			return;
		}
		api.games
			.list()
			.then( ( result ) => {
				setGames( result );
				if ( result.length > 0 ) {
					setGameSlug( result[ 0 ].slug );
				}
				setLoading( false );
			} )
			.catch( () => setLoading( false ) );
	}, [ canImportGex ] );

	return (
		<div className="be-admin">
			<h1>{ __( 'Import', 'beyond-elysium' ) }</h1>

			{ canImportGex && canImportGameFile && (
				<div className="be-admin__tabs">
					<button
						type="button"
						className={ tab === 'gex' ? 'be-admin__tab be-admin__tab--active' : 'be-admin__tab' }
						onClick={ () => setTab( 'gex' ) }
					>
						{ __( 'Characters & World Objects', 'beyond-elysium' ) }
					</button>
					<button
						type="button"
						className={ tab === 'gv3' ? 'be-admin__tab be-admin__tab--active' : 'be-admin__tab' }
						onClick={ () => setTab( 'gv3' ) }
					>
						{ __( 'Full Game File', 'beyond-elysium' ) }
					</button>
				</div>
			) }

			{ tab === 'gex' && canImportGex && (
				loading ? (
					<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
				) : games.length === 0 ? (
					<p>{ __( 'No games exist yet - create one under Beyond Elysium → Games first.', 'beyond-elysium' ) }</p>
				) : (
					<>
						<div className="be-admin__filters">
							<label>
								{ __( 'Game', 'beyond-elysium' ) }{ ' ' }
								<select value={ gameSlug } onChange={ ( e ) => setGameSlug( e.target.value ) }>
									{ games.map( ( g ) => (
										<option key={ g.slug } value={ g.slug }>
											{ g.name }
										</option>
									) ) }
								</select>
							</label>
						</div>

						{ /* key={gameSlug} remounts the wizard fresh when the chronicle changes. */ }
						{ gameSlug && <ImportTool key={ gameSlug } gameSlug={ gameSlug } /> }
					</>
				)
			) }

			{ tab === 'gv3' && canImportGameFile && <GameImportTool /> }
		</div>
	);
}

export default AdminImport;
