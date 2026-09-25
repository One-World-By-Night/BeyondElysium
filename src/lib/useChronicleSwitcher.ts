/**
 * Drives an in-page chronicle switcher.
 */
import { useEffect, useState } from '@wordpress/element';
import api from '../api/client';
import type { MyGame, MyCapabilities } from '../types';

const EMPTY_CAPABILITIES: MyCapabilities = {
	be_manage_characters: false,
	be_manage_plots: false,
	be_manage_schemas: false,
	be_manage_connections: false,
	be_manage_boons: false,
	be_manage_world_objects: false,
	be_manage_sessions: false,
	be_manage_apr: false,
	be_manage_factions: false,
};

/**
 * Reads the current `?game_slug=` from the URL.
 */
export function readGameSlugFromUrl(): string {
	return (
		new URLSearchParams( window.location.search ).get( 'game_slug' ) ?? ''
	);
}

/**
 * Writes `game_slug` into the URL without a page reload.
 */
export function writeGameSlugToUrl( gameSlug: string ): void {
	const url = new URL( window.location.href );
	url.searchParams.set( 'game_slug', gameSlug );
	window.history.replaceState( {}, '', url.toString() );
}

/**
 * Loads the current user's memberships, telling a failed request apart from belonging to no chronicle.
 */
export async function fetchMemberships(
	mine: () => Promise< MyGame[] >
): Promise< { games: MyGame[]; failed: boolean } > {
	try {
		return { games: await mine(), failed: false };
	} catch {
		return { games: [], failed: true };
	}
}

/**
 * Whether the chronicle is linked to OWbN accessSchema. A chronicle not in the list is not.
 */
export function isLinkedToAccessSchema(
	games: MyGame[],
	gameSlug: string
): boolean {
	return (
		games.find( ( game ) => game.slug === gameSlug )?.asc_linked === true
	);
}

export interface ChronicleSwitcherState {
	games: MyGame[];
	gameSlug: string;
	setGameSlug: ( slug: string ) => void;
	capabilities: MyCapabilities;
	loadingGames: boolean;
	/**
	 * The membership request failed.
	 */
	gamesFailed: boolean;
	/**
	 * Asks for the memberships again after a failure.
	 */
	retryGames: () => void;
	loadingCapabilities: boolean;
	/**
	 * The chronicle `capabilities` were resolved.
	 */
	capabilitiesFor: string;
	/**
	 * This chronicle's resolved brand accent.
	 */
	accentColor: string;
}

export function useChronicleSwitcher(): ChronicleSwitcherState {
	const [ games, setGames ] = useState< MyGame[] >( [] );
	const [ gameSlug, setGameSlug ] = useState( readGameSlugFromUrl );
	const [ capabilities, setCapabilities ] =
		useState< MyCapabilities >( EMPTY_CAPABILITIES );
	const [ accentColor, setAccentColor ] = useState( '' );
	const [ loadingGames, setLoadingGames ] = useState( true );
	const [ gamesFailed, setGamesFailed ] = useState( false );
	const [ gamesAttempt, setGamesAttempt ] = useState( 0 );
	const [ loadingCapabilities, setLoadingCapabilities ] = useState( false );
	const [ capabilitiesFor, setCapabilitiesFor ] = useState( '' );

	// Loads the user's real memberships once.
	useEffect( () => {
		setLoadingGames( true );
		fetchMemberships( () => api.games.mine() ).then(
			( { games: result, failed } ) => {
				setGames( result );
				setGamesFailed( failed );
				setGameSlug( ( current ) =>
					current && result.some( ( g ) => g.slug === current )
						? current
						: result[ 0 ]?.slug ?? ''
				);
				setLoadingGames( false );
			}
		);
	}, [ gamesAttempt ] );

	// Re-resolves capabilities every time the selected chronicle changes, and keeps the URL's own game_slug in sync.
	useEffect( () => {
		if ( ! gameSlug ) {
			setCapabilities( EMPTY_CAPABILITIES );
			setCapabilitiesFor( '' );
			setAccentColor( '' );
			return;
		}
		writeGameSlugToUrl( gameSlug );
		setLoadingCapabilities( true );
		// An answer for a chronicle switched away from before it arrived is dropped, never shown for the next one.
		let current = true;
		api.games
			.myCapabilities( gameSlug )
			.then( ( { capabilities: caps, accent_color: accent } ) => ( {
				caps,
				accent: accent ?? '',
			} ) )
			.catch( () => ( { caps: EMPTY_CAPABILITIES, accent: '' } ) )
			.then( ( { caps, accent } ) => {
				if ( ! current ) {
					return;
				}
				setCapabilities( caps );
				setAccentColor( accent );
				setCapabilitiesFor( gameSlug );
				setLoadingCapabilities( false );
			} );
		return () => {
			current = false;
		};
	}, [ gameSlug ] );

	const retryGames = () => setGamesAttempt( ( attempt ) => attempt + 1 );

	return {
		games,
		gameSlug,
		setGameSlug,
		capabilities,
		loadingGames,
		gamesFailed,
		retryGames,
		loadingCapabilities,
		capabilitiesFor,
		accentColor,
	};
}
