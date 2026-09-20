/**
 * Drives an in-page chronicle switcher (page-consolidation-design.md): loads the
 * current user's real chronicle memberships (never the full install-wide list),
 * selects one (the URL's own `?game_slug=` first, then the first real membership),
 * keeps the URL in sync so a reload or a shared link preserves the choice, and
 * re-fetches this chronicle's own real capabilities every time the selection
 * changes - never the site-wide, chronicle-blind snapshot `window.beyondElysium
 * .capabilities` carries on every page load regardless of which chronicle it names.
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

/** Reads the current `?game_slug=` from the URL. Exported for testing without a hook-rendering dependency. */
export function readGameSlugFromUrl(): string {
	return (
		new URLSearchParams( window.location.search ).get( 'game_slug' ) ?? ''
	);
}

/** Writes `game_slug` into the URL without a page reload, so a bookmark or refresh preserves the switch. */
export function writeGameSlugToUrl( gameSlug: string ): void {
	const url = new URL( window.location.href );
	url.searchParams.set( 'game_slug', gameSlug );
	window.history.replaceState( {}, '', url.toString() );
}

/**
 * Loads the current user's memberships, telling a failed request apart from
 * belonging to no chronicle (1.0.0-review F-081). Exported for testing
 * without a hook-rendering dependency.
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

export interface ChronicleSwitcherState {
	games: MyGame[];
	gameSlug: string;
	setGameSlug: ( slug: string ) => void;
	capabilities: MyCapabilities;
	loadingGames: boolean;
	/** The membership request failed - not the same as belonging to no chronicle. */
	gamesFailed: boolean;
	/** Asks for the memberships again after a failure. */
	retryGames: () => void;
	loadingCapabilities: boolean;
	/** The chronicle `capabilities` were resolved for - until it matches `gameSlug`, they aren't this chronicle's yet. */
	capabilitiesFor: string;
	/**
	 * This chronicle's resolved brand accent (1.2.7-design-workflow.md §E2) - its own
	 * override, or the site-wide default, or '' when neither is set. '' means "apply no
	 * inline style", never a color - the six `--be-st-accent` consumers fall through to
	 * their own CSS default unchanged.
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

	// Loads the user's real memberships once, then resolves the initial selection: the
	// URL's own game_slug when it names a real membership, the first membership otherwise.
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

	// Re-resolves capabilities every time the selected chronicle changes, and keeps the
	// URL's own game_slug in sync so a reload lands back on the same chronicle.
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
