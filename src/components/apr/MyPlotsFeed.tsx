/**
 * Player-facing feed of plots and rumors the current user is connected to or reached by.
 * Fetches the list from the API, renders it as a clickable list of titles, and shows the
 * full PlotThread view for whichever plot is selected. Reused as-is by the player
 * dashboard, not a separate implementation.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { MyPlotsResponse } from '../../types/plot';
import { PlotThread } from './PlotThread';
import './MyPlotsFeed.css';

export interface MyPlotsFeedProps {
	gameSlug: string;
}

/**
 * Renders the current player's personal plot feed: a list of plots they are connected to
 * or have been reached by, each opening into the full PlotThread view when clicked. Shows
 * loading and error states while the feed is being fetched from the API.
 */
export function MyPlotsFeed( { gameSlug }: MyPlotsFeedProps ) {
	const [ data, setData ] = useState<MyPlotsResponse | null>( null );
	const [ selected, setSelected ] = useState<number | null>( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );

	useEffect( () => {
		setLoading( true );
		api
			.plots( gameSlug )
			.myPlots()
			.then( ( result ) => {
				setData( result );
				setLoading( false );
			} )
			.catch( () => {
				setError( __( 'Failed to load your plots.', 'beyond-elysium' ) );
				setLoading( false );
			} );
	}, [ gameSlug ] );

	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}
	if ( error || ! data ) {
		return (
			<div className="be-my-plots__error" role="alert">
				{ error ?? __( 'Could not load your plots.', 'beyond-elysium' ) }
			</div>
		);
	}

	if ( selected !== null ) {
		return (
			<div className="be-my-plots">
				<button type="button" className="be-my-plots__back" onClick={ () => setSelected( null ) }>
					{ __( '← Back to your plots', 'beyond-elysium' ) }
				</button>
				<PlotThread gameSlug={ gameSlug } plotId={ selected } />
			</div>
		);
	}

	return (
		<div className="be-my-plots">
			{ data.plots.length === 0 ? (
				<p>{ __( "Nothing yet - plots you're connected to or reached by will show up here.", 'beyond-elysium' ) }</p>
			) : (
				<ul className="be-my-plots__items">
					{ data.plots.map( ( plot ) => (
						<li key={ plot.id }>
							<button type="button" className="be-my-plots__item-button" onClick={ () => setSelected( plot.id ) }>
								<span className="be-my-plots__title">{ plot.title }</span>
								<span className="be-my-plots__updated">{ plot.updated_at }</span>
							</button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

export default MyPlotsFeed;
