/**
 * Admin page for the staff plot dashboard.
 * Renders a chronicle picker plus the shared PlotManager component,
 * giving staff a dedicated wp-admin surface for managing a chronicle's
 * plot threads.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import PlotManager from '../apr/PlotManager';
import type { Game } from '../../types';
import './Admin.css';

/**
 * Renders the Plots admin screen.
 * Lets staff pick a chronicle and manage its plot threads through the
 * shared PlotManager component, which is scoped to one chronicle at a
 * time.
 */
export function AdminPlots() {
	const [ games, setGames ] = useState<Game[]>( [] );
	const [ gameSlug, setGameSlug ] = useState( '' );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
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
	}, [] );

	return (
		<div className="be-admin">
			<h1>{ __( 'Plots', 'beyond-elysium' ) }</h1>

			{ loading ? (
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

					{ /* key={gameSlug} remounts PlotManager so its internal state resets when the chronicle changes. */ }
					{ gameSlug && <PlotManager key={ gameSlug } gameSlug={ gameSlug } /> }
				</>
			) }
		</div>
	);
}

export default AdminPlots;
