/**
 * Admin page for the staff character roster.
 * Lets staff choose a chronicle and browse its full character list,
 * reusing the shared CharacterList component with a toggle between
 * player characters and NPCs.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import CharacterList from '../character/CharacterList';
import type { Game } from '../../types';
import './Admin.css';

/**
 * Renders the Characters admin screen.
 * Loads the list of chronicles, lets the viewer pick one from a dropdown,
 * and displays that chronicle's characters through CharacterList, with a
 * checkbox to switch the roster between player characters and NPCs.
 */
export function AdminCharacters() {
	const [ games, setGames ] = useState<Game[]>( [] );
	const [ gameSlug, setGameSlug ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ showNpcs, setShowNpcs ] = useState( false );

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

	// URL of the read-only character sheet page that each roster row links to.
	const sheetUrl = `${ window.location.origin }/character-sheet/`;

	return (
		<div className="be-admin">
			<h1>{ __( 'Characters', 'beyond-elysium' ) }</h1>

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
						<label>
							{ ' ' }
							<input type="checkbox" checked={ showNpcs } onChange={ ( e ) => setShowNpcs( e.target.checked ) } />
							{ ' ' }{ __( 'Show NPCs instead of player characters', 'beyond-elysium' ) }
						</label>
					</div>

					{ gameSlug && <CharacterList gameSlug={ gameSlug } showNpcs={ showNpcs } sheetPageUrl={ sheetUrl } /> }
				</>
			) }
		</div>
	);
}

export default AdminCharacters;
