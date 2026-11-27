/**
 * A player's home in one chronicle: their characters, what needs them, what is next and what is new in their plots.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import api from '../../api/client';
import HelpButton from '../shared/HelpButton';
import { loadCart } from '../../lib/cartStorage';
import {
	characterStatusLabel,
	creatureTypeName,
	factionTypeLabel,
} from '../../lib/characterLabels';
import { cardHref, needsYou, nextSessionLines } from '../../lib/playerHome';
import type { NeedItem } from '../../lib/playerHome';
import { PLAYER_TABS, builderUrl, playerTabUrl } from '../../lib/pluginPages';
import { relativeDay } from '../../lib/relativeDays';
import { formatStoredDay } from '../../lib/storedTime';
import { standingLine } from '../../lib/standing';
import { useCreatureTypeNames } from '../../lib/useCreatureTypeNames';
import type { Faction, Position } from '../../types/faction';
import type { MyChangeAcrossGames } from '../../types/character';
import type { HomeCharacter, PlayerHomeData } from '../../types/home';
import './GameDashboard.css';
import './PlayerHome.css';

export interface PlayerHomeProps {
	gameSlug: string;
}

function initials( name: string ): string {
	return name
		.split( /\s+/ )
		.filter( Boolean )
		.slice( 0, 2 )
		.map( ( word ) => word.charAt( 0 ).toUpperCase() )
		.join( '' );
}

function hasCart( characterId: number ): boolean {
	const cart = loadCart( characterId );
	return cart !== null && Object.keys( cart.blocks ).length > 0;
}

/**
 * Renders a player's home for one chronicle.
 */
export function PlayerHome( { gameSlug }: PlayerHomeProps ) {
	const [ data, setData ] = useState< PlayerHomeData | null >( null );
	const [ factions, setFactions ] = useState< Faction[] >( [] );
	const [ positions, setPositions ] = useState< Position[] >( [] );
	const [ elsewhere, setElsewhere ] = useState< MyChangeAcrossGames[] >( [] );
	const [ error, setError ] = useState< string | null >( null );
	const creatureTypes = useCreatureTypeNames( gameSlug );

	useEffect( () => {
		setData( null );
		setError( null );
		Promise.all( [
			api.home( gameSlug ).player(),
			api.factions( gameSlug ).list(),
			api.positions( gameSlug ).list(),
			api.myChangesAcrossGames().list(),
		] )
			.then( ( [ home, allFactions, allPositions, changes ] ) => {
				const mine = home.characters.map( ( c ) => c.id );
				setData( home );
				setFactions( allFactions.filter( ( f ) => f.is_member ) );
				setPositions(
					allPositions.filter(
						( p ) =>
							p.character_id !== null &&
							mine.includes( p.character_id )
					)
				);
				setElsewhere(
					changes.filter(
						( c ) =>
							c.game_slug !== gameSlug &&
							c.display_status === 'pending'
					)
				);
			} )
			.catch( () =>
				setError( __( 'Failed to load your home.', 'beyond-elysium' ) )
			);
	}, [ gameSlug ] );

	const open =
		( item: NeedItem ) => ( e: { preventDefault: () => void } ) => {
			if ( item.noticeId === undefined ) {
				return;
			}
			e.preventDefault();
			const go = () => window.location.assign( item.href );
			api.notices( gameSlug )
				.markRead( [ item.noticeId ] )
				.then( go, go );
		};

	const card = ( c: HomeCharacter ) => (
		<li key={ c.id } className="be-home__card">
			{ c.image_url ? (
				<img className="be-home__portrait" src={ c.image_url } alt="" />
			) : (
				<span className="be-home__portrait be-home__portrait--initials">
					{ initials( c.name ) }
				</span>
			) }
			<div className="be-home__card-body">
				<a
					className="be-home__card-link"
					href={ cardHref( c.id, gameSlug ) }
				>
					{ c.name }
				</a>
				<span className="be-home__card-type">
					{ creatureTypeName( c.stack_slug, creatureTypes ) }
					{ c.status !== 'active' &&
						` (${ characterStatusLabel( c.status ) })` }
				</span>
				<span className="be-home__card-standing">
					{ standingLine( c.xp_unspent, c.xp_held, c.waiting ) }
				</span>
				{ hasCart( c.id ) && (
					<span className="be-home__card-cart">
						{ __( 'In your cart', 'beyond-elysium' ) }
					</span>
				) }
			</div>
		</li>
	);

	const needs = data ? needsYou( data, gameSlug ) : [];
	const noticesListed = needs.filter(
		( item ) => item.kind === 'notice'
	).length;
	const session = data?.next_session ?? null;
	const sessionLines = session ? nextSessionLines( session ) : [];

	return (
		<div className="be-game-dashboard be-game-dashboard--player be-home">
			<div className="be-help-heading">
				<h2>{ __( 'Home', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="player-dashboard" />
			</div>
			{ error && (
				<div className="be-game-dashboard__error" role="alert">
					{ error }
				</div>
			) }

			{ ! data && ! error && (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) }

			{ data && (
				<>
					<section className="be-game-dashboard__section">
						<h3>{ __( 'Your characters', 'beyond-elysium' ) }</h3>
						{ data.characters.length === 0 && (
							<p>
								{ __(
									'You have no characters in this chronicle yet.',
									'beyond-elysium'
								) }
							</p>
						) }
						<ul className="be-home__cards">
							{ data.characters.map( card ) }
						</ul>
						<p className="be-home__actions">
							<a
								className="be-home__action"
								href={ builderUrl( gameSlug ) }
							>
								{ __( '+ New character', 'beyond-elysium' ) }
							</a>
							<a
								className="be-home__action"
								href={ `${ playerTabUrl(
									PLAYER_TABS.sendFile
								) }&game_slug=${ encodeURIComponent(
									gameSlug
								) }` }
							>
								{ __(
									'Send a Grapevine file',
									'beyond-elysium'
								) }
							</a>
						</p>
					</section>

					<section className="be-game-dashboard__section">
						<h3>{ __( 'Needs you', 'beyond-elysium' ) }</h3>
						{ needs.length === 0 ? (
							<p>
								{ __(
									'Nothing needs you right now.',
									'beyond-elysium'
								) }
							</p>
						) : (
							<ul className="be-home__needs">
								{ needs.map( ( item ) => (
									<li
										key={ item.key }
										className={ `be-home__need be-home__need--${ item.kind }` }
									>
										<a
											className="be-home__need-link"
											href={ item.href }
											onClick={ open( item ) }
										>
											<span className="be-home__need-title">
												{ item.title }
											</span>
											{ item.lines.map( ( line ) => (
												<span
													key={ line }
													className="be-home__need-line"
												>
													{ line }
												</span>
											) ) }
										</a>
									</li>
								) ) }
							</ul>
						) }
						{ data.notices.unread > noticesListed && (
							<p className="be-home__more">
								{ sprintf(
									/* translators: %d: how many unread notices the bell holds beyond those listed */
									_n(
										'%d more unread in the bell',
										'%d more unread in the bell',
										data.notices.unread - noticesListed,
										'beyond-elysium'
									),
									data.notices.unread - noticesListed
								) }
							</p>
						) }
					</section>

					{ session && (
						<section className="be-game-dashboard__section">
							<h3>
								{ __( 'Next game night', 'beyond-elysium' ) }
							</h3>
							<p className="be-home__session">
								<strong>
									{ formatStoredDay( session.game_date ) }
								</strong>
								{ [ session.start_time, session.place ]
									.filter( Boolean )
									.map( ( part ) => ` · ${ part }` )
									.join( '' ) }
							</p>
							{ sessionLines.map( ( line ) => (
								<p
									key={ line }
									className="be-home__session-line"
								>
									{ line }
								</p>
							) ) }
						</section>
					) }

					<section className="be-game-dashboard__section">
						<h3>
							{ __( 'Latest in your plots', 'beyond-elysium' ) }
						</h3>
						{ data.plots.length === 0 ? (
							<p>
								{ __(
									"Nothing yet - plots you're connected to or reached by will show up here.",
									'beyond-elysium'
								) }
							</p>
						) : (
							<ul className="be-game-dashboard__list">
								{ data.plots.map( ( plot ) => (
									<li key={ plot.id }>
										<a
											className="be-home__plot-link"
											href={ `${ playerTabUrl(
												PLAYER_TABS.plots
											) }&game_slug=${ encodeURIComponent(
												gameSlug
											) }&open_plot=${ plot.id }` }
										>
											{ plot.title }
										</a>
										<span className="be-home__when">
											{ relativeDay(
												plot.updated_at,
												data.today
											) }
										</span>
									</li>
								) ) }
							</ul>
						) }
						<p className="be-home__more">
							<a
								className="be-home__action"
								href={ `${ playerTabUrl(
									PLAYER_TABS.plots
								) }&game_slug=${ encodeURIComponent(
									gameSlug
								) }` }
							>
								{ __( 'See all', 'beyond-elysium' ) }
							</a>
						</p>
					</section>

					{ ( factions.length > 0 || positions.length > 0 ) && (
						<section className="be-game-dashboard__section">
							<h3>{ __( 'Your groups', 'beyond-elysium' ) }</h3>
							<ul className="be-game-dashboard__list">
								{ factions.map( ( faction ) => (
									<li key={ `faction-${ faction.id }` }>
										{ faction.name }
										{ ' — ' }
										{ factionTypeLabel(
											faction.faction_type
										) }
									</li>
								) ) }
								{ positions.map( ( position ) => (
									<li key={ `position-${ position.id }` }>
										{ position.title }
									</li>
								) ) }
							</ul>
						</section>
					) }

					{ elsewhere.length > 0 && (
						<section className="be-game-dashboard__section">
							<h3>
								{ __( 'Other chronicles', 'beyond-elysium' ) }
							</h3>
							<ul className="be-game-dashboard__list">
								{ Object.entries(
									elsewhere.reduce(
										( counts, change ) => ( {
											...counts,
											[ change.game_name ]:
												( counts[ change.game_name ] ??
													0 ) + 1,
										} ),
										{} as Record< string, number >
									)
								).map( ( [ name, count ] ) => (
									<li key={ name }>
										{ sprintf(
											/* translators: 1: another chronicle's name, 2: how many changes wait there */
											_n(
												'%1$s: %2$d change waiting',
												'%1$s: %2$d changes waiting',
												count,
												'beyond-elysium'
											),
											name,
											count
										) }
									</li>
								) ) }
							</ul>
						</section>
					) }
				</>
			) }
		</div>
	);
}

export default PlayerHome;
