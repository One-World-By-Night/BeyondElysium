/**
 * Debounces a title search and loads the matching secrets for a chronicle, with a way to force a reload.
 */
import { useEffect, useState } from '@wordpress/element';
import api from '../api/client';
import type { Secret } from '../types/secret';

const SEARCH_DEBOUNCE_MS = 300;

export interface SecretsSearch {
	secrets: Secret[] | null;
	refresh: () => void;
}

export function useSecretsSearch(
	gameSlug: string,
	searchInput: string,
	onError?: () => void
): SecretsSearch {
	const [ search, setSearch ] = useState( searchInput );
	const [ secrets, setSecrets ] = useState< Secret[] | null >( null );
	const [ refreshCount, setRefreshCount ] = useState( 0 );

	useEffect( () => {
		const timer = setTimeout(
			() => setSearch( searchInput ),
			SEARCH_DEBOUNCE_MS
		);
		return () => clearTimeout( timer );
	}, [ searchInput ] );

	useEffect( () => {
		api.secrets( gameSlug )
			.all( search )
			.then( setSecrets )
			.catch( () => {
				setSecrets( [] );
				onError?.();
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ gameSlug, search, refreshCount ] );

	return { secrets, refresh: () => setRefreshCount( ( n ) => n + 1 ) };
}
