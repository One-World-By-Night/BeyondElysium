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
import WaitingForReview from '../import/WaitingForReview';
import type { Game } from '../../types';
import HelpButton from '../shared/HelpButton';
import TabStrip from '../shared/TabStrip';
import './Admin.css';

type Tab = 'gex' | 'gv3';

/**
 * Renders the Import admin screen.
 * Hosts two import tools behind tabs - character and world-object GEX
 * files, and full chronicle .gv3 game files - showing only the tabs the
 * viewer's capabilities allow.
 */
export function AdminImport() {
	const [ games, setGames ] = useState< Game[] >( [] );
	const [ gameSlug, setGameSlug ] = useState( '' );
	const [ loading, setLoading ] = useState( true );

	const canImportGex = window.beyondElysium?.capabilities?.be_import ?? false;
	const canImportGameFile =
		window.beyondElysium?.capabilities?.be_manage_games ?? false;
	const [ tab, setTab ] = useState< Tab >( canImportGex ? 'gex' : 'gv3' );

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
			<div className="be-help-heading">
				<h1>{ __( 'Import', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="import" />
			</div>

			{ canImportGex && canImportGameFile && (
				<TabStrip
					tabs={ [
						{
							key: 'gex',
							label: __(
								'Characters & World Objects',
								'beyond-elysium'
							),
						},
						{
							key: 'gv3',
							label: __( 'Full Game File', 'beyond-elysium' ),
						},
					] }
					active={ tab }
					onChange={ ( key ) => setTab( key as Tab ) }
				/>
			) }

			{ tab === 'gex' &&
				canImportGex &&
				( loading ? (
					<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
				) : games.length === 0 ? (
					<p>
						{ __(
							'No games exist yet - create one under Beyond Elysium → System Config → Games first.',
							'beyond-elysium'
						) }
					</p>
				) : (
					<>
						<div className="be-admin__filters">
							<label>
								{ __( 'Game', 'beyond-elysium' ) }{ ' ' }
								<select
									value={ gameSlug }
									onChange={ ( e ) =>
										setGameSlug( e.target.value )
									}
								>
									{ games.map( ( g ) => (
										<option key={ g.slug } value={ g.slug }>
											{ g.name }
										</option>
									) ) }
								</select>
							</label>
						</div>

						{ /* key={gameSlug} remounts both fresh when the chronicle changes. Waiting transfers and player-sent files wait above the wizard. */ }
						{ gameSlug && (
							<WaitingForReview
								key={ `waiting-${ gameSlug }` }
								gameSlug={ gameSlug }
							/>
						) }
						{ gameSlug && (
							<ImportTool
								key={ gameSlug }
								gameSlug={ gameSlug }
							/>
						) }
					</>
				) ) }

			{ tab === 'gv3' && canImportGameFile && <GameImportTool /> }
		</div>
	);
}

export default AdminImport;
