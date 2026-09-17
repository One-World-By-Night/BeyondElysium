/**
 * Admin page for game night scheduling and sign-in (1.1.0 §3.1).
 * Renders a chronicle picker plus the shared GameNights component,
 * giving staff a dedicated wp-admin surface alongside the front-end
 * Storyteller Toolkit tab.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import GameNights from '../game/GameNights';
import type { Game } from '../../types';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

export function AdminGameNights() {
	const [ games, setGames ] = useState< Game[] >( [] );
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
			<div className="be-help-heading">
				<h1>{ __( 'Game Nights', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="admin-game-nights" />
			</div>

			{ loading ? (
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

					{ /* key={gameSlug} remounts GameNights so its internal state resets when the
					 * chronicle changes. No capabilities prop, matching AdminPlots.tsx: GameNights'
					 * own canIn() falls back to the site-wide window.beyondElysium.capabilities
					 * snapshot, which add_submenu_page()'s own be_manage_sessions gate already
					 * guarantees true for anyone who could open this page at all. */ }
					{ gameSlug && (
						<GameNights key={ gameSlug } gameSlug={ gameSlug } />
					) }
				</>
			) }
		</div>
	);
}

export default AdminGameNights;
