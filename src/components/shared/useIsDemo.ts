/**
 * Whether a chronicle is a demo, for a control that must behave differently there.
 */
import { useEffect, useState } from '@wordpress/element';
import api from '../../api/client';
import { isDemoChronicle } from '../../lib/demoStatusLookup';

/**
 * False until the answer arrives, and always false with no chronicle.
 */
export function useIsDemo( gameSlug?: string ): boolean {
	const [ isDemo, setIsDemo ] = useState( false );

	useEffect( () => {
		if ( ! gameSlug ) {
			setIsDemo( false );
			return;
		}
		let cancelled = false;
		isDemoChronicle( gameSlug, ( slug ) =>
			api.games.demoStatus( slug )
		).then( ( on ) => {
			if ( ! cancelled ) {
				setIsDemo( on );
			}
		} );
		return () => {
			cancelled = true;
		};
	}, [ gameSlug ] );

	return isDemo;
}

export default useIsDemo;
