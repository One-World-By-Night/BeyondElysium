/**
 * Loads the full install-wide chronicle list and selects the first one, for the wp-admin pages that pick a chronicle.
 */
import { useEffect, useState } from '@wordpress/element';
import api from '../api/client';
import type { Game } from '../types';

export interface AdminGameSelectorState {
	games: Game[];
	gameSlug: string;
	setGameSlug: ( slug: string ) => void;
	loading: boolean;
}

export function useAdminGameSelector(): AdminGameSelectorState {
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

	return { games, gameSlug, setGameSlug, loading };
}
