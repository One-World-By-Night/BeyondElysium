/**
 * Admin page for release batches (1.1.0 §3.2). Renders a chronicle picker plus the
 * shared ReleaseBatches component - one tab of the wp-admin Plots hub.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import ReleaseBatches from '../game/ReleaseBatches';
import type { Game } from '../../types';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

export function AdminReleaseBatches() {
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
				<h1>{ __( 'Releases', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="admin-release-batches" />
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

					{ /* key={gameSlug} remounts ReleaseBatches so its internal state resets when
					 * the chronicle changes. No capabilities prop, matching AdminPlots.tsx: canIn()
					 * falls back to the site-wide window.beyondElysium.capabilities snapshot, which
					 * this page's own be_manage_plots gate already guarantees true for anyone who
					 * could open it at all. */ }
					{ gameSlug && (
						<ReleaseBatches
							key={ gameSlug }
							gameSlug={ gameSlug }
						/>
					) }
				</>
			) }
		</div>
	);
}

export default AdminReleaseBatches;
