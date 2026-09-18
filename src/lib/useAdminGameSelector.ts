/**
 * Loads the full install-wide chronicle list (`api.games.list()`, not a user's own
 * memberships - see `useChronicleSwitcher` for that) and selects the first one, the shape
 * seven wp-admin pages (Characters, Game Nights, Plots, Query, Release Batches, Reports,
 * World Objects) each hand-rolled identically until this was extracted (1.1.1 audit).
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
