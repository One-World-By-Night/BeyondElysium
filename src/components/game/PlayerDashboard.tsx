/**
 * Player-facing game landing page: the current user's own characters, their pending changes, and their plot feed.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import api from '../../api/client';
import { describeChange } from '../../lib/describeChange';
import { MyPlotsFeed } from '../apr/MyPlotsFeed';
import { CastingBrief } from './CastingBrief';
import type { Character, QueueChange } from '../../types/character';
import type { StaffQueueCastingRow } from '../../types/staffQueue';
import type { Faction, Position } from '../../types/faction';
import HelpButton from '../shared/HelpButton';
import './GameDashboard.css';

export interface PlayerDashboardProps {
	gameSlug: string;
	sheetPageUrl?: string;
}

/**
 * Builds the character sheet URL for one character, appending its id and chronicle as query parameters to the
 * configured sheet page URL.
 */
function sheetLink(
	sheetPageUrl: string | undefined,
	characterId: number,
	gameSlug: string
): string | null {
	if ( ! sheetPageUrl ) {
		return null;
	}
	const separator = sheetPageUrl.includes( '?' ) ? '&' : '?';
	return `${ sheetPageUrl }${ separator }character_id=${ characterId }&game_slug=${ encodeURIComponent(
		gameSlug
	) }`;
}

/**
 * Renders the player's personal dashboard: a list of their characters linking to each character's sheet, a list of
 * their own pending changes, and their plot feed via MyPlotsFeed.
 */
export function PlayerDashboard( {
	gameSlug,
	sheetPageUrl,
}: PlayerDashboardProps ) {
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ myChanges, setMyChanges ] = useState< QueueChange[] >( [] );
	const [ myCastings, setMyCastings ] = useState< StaffQueueCastingRow[] >(
		[]
	);
	const [ myFactions, setMyFactions ] = useState< Faction[] >( [] );
	const [ myPositions, setMyPositions ] = useState< Position[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ openCastingId, setOpenCastingId ] = useState< number | null >(
		null
	);

	useEffect( () => {
		setLoading( true );
		setError( null );
		Promise.all( [
			api.characters( gameSlug ).myCharacters(),
			api.changes( gameSlug ).myChanges(),
			api.castings( gameSlug ).myUpcoming(),
			api.factions( gameSlug ).list(),
			api.positions( gameSlug ).list(),
		] )
			.then(
				( [
					myCharacters,
					pending,
					castings,
					factions,
					positions,
				] ) => {
					setCharacters( myCharacters );
					setMyChanges( pending );
					setMyCastings( castings );
					const myCharacterIds = myCharacters.map( ( c ) => c.id );
					setMyFactions( factions.filter( ( f ) => f.is_member ) );
					setMyPositions(
						positions.filter(
							( p ) =>
								p.character_id !== null &&
								myCharacterIds.includes( p.character_id )
						)
					);
					setLoading( false );
				}
			)
			.catch( () => {
				setError(
					__( 'Failed to load your dashboard.', 'beyond-elysium' )
				);
				setLoading( false );
			} );
	}, [ gameSlug ] );

	if ( openCastingId !== null ) {
		return (
			<div className="be-game-dashboard be-game-dashboard--player">
				<CastingBrief
					gameSlug={ gameSlug }
					castingId={ openCastingId }
					onClose={ () => setOpenCastingId( null ) }
				/>
			</div>
		);
	}

	return (
		<div className="be-game-dashboard be-game-dashboard--player">
			<div className="be-help-heading">
				<h2>{ __( 'Dashboard', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="player-dashboard" />
			</div>
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
					<p>
						{ __(
							'You have no characters in this chronicle yet.',
							'beyond-elysium'
						) }
					</p>
				) : (
					<ul className="be-game-dashboard__list">
						{ characters.map( ( c ) => {
							const link = sheetLink(
								sheetPageUrl,
								c.id,
								gameSlug
							);
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

			{ ( myFactions.length > 0 || myPositions.length > 0 ) && (
				<section className="be-game-dashboard__section">
					<h2>{ __( 'My Groups', 'beyond-elysium' ) }</h2>
					<ul className="be-game-dashboard__list">
						{ myFactions.map( ( faction ) => (
							<li key={ `faction-${ faction.id }` }>
								{ faction.name }
								{ ' — ' }
								{ faction.faction_type }
							</li>
						) ) }
						{ myPositions.map( ( position ) => (
							<li key={ `position-${ position.id }` }>
								{ position.title }
							</li>
						) ) }
					</ul>
				</section>
			) }

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
								{ change.character_name ??
									`#${ change.character_id }` }{ ' ' }
								—{ ' ' }
								{ describeChange(
									change.change_type,
									change.change_data
								) }{ ' ' }
								<span className="be-game-dashboard__badge">
									{ change.approval_level }
								</span>
							</li>
						) ) }
					</ul>
				) }
			</section>

			{ myCastings.length > 0 && (
				<section className="be-game-dashboard__section">
					<h2>{ __( 'My Castings', 'beyond-elysium' ) }</h2>
					<ul className="be-game-dashboard__list">
						{ myCastings.map( ( casting ) => (
							<li key={ casting.casting_id }>
								<button
									type="button"
									className="be-game-dashboard__link-button"
									onClick={ () =>
										setOpenCastingId( casting.casting_id )
									}
								>
									{ sprintf(
										/* translators: 1: NPC name, 2: session date */
										__(
											"You're playing %1$s on %2$s",
											'beyond-elysium'
										),
										casting.character_name,
										casting.game_date
									) }
								</button>
							</li>
						) ) }
					</ul>
				</section>
			) }

			<section className="be-game-dashboard__section">
				<h2>{ __( 'My Plots', 'beyond-elysium' ) }</h2>
				<MyPlotsFeed gameSlug={ gameSlug } />
			</section>
		</div>
	);
}

export default PlayerDashboard;
