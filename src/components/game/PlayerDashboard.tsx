/**
 * Player-facing game landing page: the current user's own characters, their
 * pending changes, and their plot feed. Shown by GameDashboard to any viewer
 * without manager capabilities.
 * Reuses MyPlotsFeed unchanged for the plot section.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import api from '../../api/client';
import { describeChange } from '../../lib/describeChange';
import { MyPlotsFeed } from '../apr/MyPlotsFeed';
import type { Character, QueueChange } from '../../types/character';
import './GameDashboard.css';

export interface PlayerDashboardProps {
	gameSlug: string;
	sheetPageUrl?: string;
}

/**
 * Builds the character sheet URL for one character, appending its id as a query
 * parameter to the configured sheet page URL. Returns null when no sheet page URL is
 * configured, so callers can render a plain label instead of a link.
 */
function sheetLink( sheetPageUrl: string | undefined, characterId: number ): string | null {
	if ( ! sheetPageUrl ) {
		return null;
	}
	const separator = sheetPageUrl.includes( '?' ) ? '&' : '?';
	return `${ sheetPageUrl }${ separator }character_id=${ characterId }`;
}

/**
 * Renders the player's personal dashboard: a list of their characters linking to each
 * character's sheet, a list of their own pending changes, and their plot feed via
 * MyPlotsFeed.
 */
export function PlayerDashboard( { gameSlug, sheetPageUrl }: PlayerDashboardProps ) {
	const [ characters, setCharacters ] = useState<Character[]>( [] );
	const [ myChanges, setMyChanges ] = useState<QueueChange[]>( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );

	useEffect( () => {
		setLoading( true );
		setError( null );
		Promise.all( [ api.characters( gameSlug ).myCharacters(), api.changes( gameSlug ).myChanges() ] )
			.then( ( [ myCharacters, pending ] ) => {
				setCharacters( myCharacters );
				setMyChanges( pending );
				setLoading( false );
			} )
			.catch( () => {
				setError( __( 'Failed to load your dashboard.', 'beyond-elysium' ) );
				setLoading( false );
			} );
	}, [ gameSlug ] );

	return (
		<div className="be-game-dashboard be-game-dashboard--player">
			{ error && (
				<div className="be-game-dashboard__error" role="alert">
					{ error }
				</div>
			) }

			<section className="be-game-dashboard__section">
				<h2>{ __( 'My Characters', 'beyond-elysium' ) }</h2>
				{ loading ? (
					<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
				) : characters.length === 0 ? (
					<p>{ __( 'You have no characters in this chronicle yet.', 'beyond-elysium' ) }</p>
				) : (
					<ul className="be-game-dashboard__list">
						{ characters.map( ( c ) => {
							const link = sheetLink( sheetPageUrl, c.id );
							return (
								<li key={ c.id }>
									{ link ? (
										<a href={ link }>{ c.name }</a>
									) : (
										<span>{ c.name }</span>
									) }
									{ ' — ' }
									{ c.stack_slug } ({ c.status })
								</li>
							);
						} ) }
					</ul>
				) }
			</section>

			<section className="be-game-dashboard__section">
				<h2>{ __( 'My Pending Changes', 'beyond-elysium' ) }</h2>
				{ loading ? (
					<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
				) : myChanges.length === 0 ? (
					<p>{ __( 'Nothing pending review.', 'beyond-elysium' ) }</p>
				) : (
					<ul className="be-game-dashboard__list">
						{ myChanges.map( ( change ) => (
							<li key={ change.id }>
								{ change.character_name ?? `#${ change.character_id }` } —{ ' ' }
								{ describeChange( change.change_type, change.change_data ) }{ ' ' }
								<span className="be-game-dashboard__badge">{ change.approval_level }</span>
							</li>
						) ) }
					</ul>
				) }
			</section>

			<section className="be-game-dashboard__section">
				<h2>{ __( 'My Plots', 'beyond-elysium' ) }</h2>
				<MyPlotsFeed gameSlug={ gameSlug } />
			</section>
		</div>
	);
}

export default PlayerDashboard;
