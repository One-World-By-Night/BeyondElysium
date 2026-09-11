/**
 * Admin page for managing World Objects (items and locations).
 *
 * Renders a game picker plus the shared WorldObjectManager widget, giving
 * staff a wp-admin entry point for creating and editing a chronicle's
 * items and locations.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import WorldObjectManager from '../world/WorldObjectManager';
import type { Game } from '../../types';
import './Admin.css';

/**
 * Renders the Items & Locations admin page. Fetches the list of games,
 * lets the user pick one from a dropdown, and displays the
 * WorldObjectManager widget scoped to that game's slug. Shows a loading
 * state while games are being fetched and a message prompting game
 * creation when none exist.
 */
export function AdminWorldObjects() {
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
			<h1>{ __( 'Items & Locations', 'beyond-elysium' ) }</h1>

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

					{ /* key={gameSlug} remounts the manager so its internal state resets per game. */ }
					{ gameSlug && <WorldObjectManager key={ gameSlug } gameSlug={ gameSlug } showEditor /> }
				</>
			) }
		</div>
	);
}

export default AdminWorldObjects;
