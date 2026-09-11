/**
 * Admin screen for the staff-facing Query Tool.
 *
 * Loads the list of games for a chronicle picker, then hands off to the
 * shared QueryTool widget for the selected game. Exports AdminQuery as
 * both a named and default export for use on the Beyond Elysium admin menu.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import QueryTool from '../query/QueryTool';
import type { Game } from '../../types';
import './Admin.css';

/**
 * Renders the Query Tool admin page. Fetches the list of games, lets the
 * user pick one from a dropdown, and displays the QueryTool widget scoped
 * to that game's slug. Shows a loading state while games are being fetched
 * and a message prompting game creation when none exist.
 */
export function AdminQuery() {
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
			<h1>{ __( 'Query Tool', 'beyond-elysium' ) }</h1>

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

					{ gameSlug && <QueryTool gameSlug={ gameSlug } /> }
				</>
			) }
		</div>
	);
}

export default AdminQuery;
