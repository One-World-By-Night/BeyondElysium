/**
 * Tells a viewer the chosen chronicle resets on its own schedule - on My Chronicle, the Storyteller Toolkit, a
 * character's sheet, and its editor. Renders nothing once it knows the chronicle isn't a demo, and nothing at all
 * when a page that already shows one (My Chronicle, the Storyteller Toolkit) is the one rendering it as a tab.
 */
import {
	createContext,
	useContext,
	useEffect,
	useState,
} from '@wordpress/element';
import api from '../../api/client';
import type { DemoStatus } from '../../types';
import { demoBannerMessage } from '../../lib/demoBanner';
import './DemoBanner.css';

/**
 * True inside a page (My Chronicle, the Storyteller Toolkit) that already renders its own banner once, so a
 * character sheet or editor nested in one of its tabs doesn't render a second copy.
 */
export const DemoBannerShownContext = createContext( false );

export default function DemoBanner( { gameSlug }: { gameSlug: string } ) {
	const alreadyShown = useContext( DemoBannerShownContext );
	const [ status, setStatus ] = useState< DemoStatus | null >( null );

	useEffect( () => {
		if ( ! gameSlug || alreadyShown ) {
			setStatus( null );
			return;
		}
		let cancelled = false;
		api.games
			.demoStatus( gameSlug )
			.then( ( result ) => {
				if ( ! cancelled ) {
					setStatus( result );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setStatus( null );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ gameSlug, alreadyShown ] );

	if ( alreadyShown ) {
		return null;
	}

	const message = demoBannerMessage( status );
	if ( ! message ) {
		return null;
	}

	return (
		<p className="be-demo-banner" role="status">
			{ message }
		</p>
	);
}
