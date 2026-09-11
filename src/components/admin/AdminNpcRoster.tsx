/**
 * Admin page for the dedicated NPC roster.
 * Lets staff choose a chronicle and browse only its non-player
 * characters, reusing the shared CharacterList component locked to
 * NPC mode.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import CharacterList from '../character/CharacterList';
import type { Game } from '../../types';
import './Admin.css';

/**
 * Renders the NPC Roster admin screen.
 * Lets staff pick a chronicle and view its full NPC list through the
 * shared CharacterList component, with NPCs forced on and no toggle
 * back to player characters.
 */
export function AdminNpcRoster() {
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

	const sheetUrl = `${ window.location.origin }/character-sheet/`;

	return (
		<div className="be-admin">
			<h1>{ __( 'NPC Roster', 'beyond-elysium' ) }</h1>

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

					{ gameSlug && <CharacterList key={ gameSlug } gameSlug={ gameSlug } showNpcs sheetPageUrl={ sheetUrl } /> }
				</>
			) }
		</div>
	);
}

export default AdminNpcRoster;
