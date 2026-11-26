/**
 * A chronicle's numbers for its Storyteller: character counts, what is pending, who has no active character and the
 * latest activity.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import api from '../../api/client';
import { describeChange } from '../../lib/describeChange';
import { errorMessage } from '../../lib/errorMessage';
import {
	changeStatusLabel,
	characterStatusLabel,
	creatureTypeName,
} from '../../lib/characterLabels';
import { useCreatureTypeNames } from '../../lib/useCreatureTypeNames';
import type {
	GameStats as GameStatsData,
	PlayerWithoutActiveCharacter,
} from '../../types';
import './GameDashboard.css';

export interface GameStatsProps {
	gameSlug: string;
	/**
	 * Links to show under the cards.
	 */
	links?: { label: string; href: string }[];
	/**
	 * How many things wait on the person, shown as a card; null leaves the card out.
	 */
	waitingOnMe?: number | null;
}

function sumCounts( counts: Record< string, number > ): number {
	return Object.values( counts ).reduce( ( total, n ) => total + n, 0 );
}

/**
 * Renders the chronicle's counts and recent activity. The caller makes sure the person manages characters.
 */
export function GameStats( {
	gameSlug,
	links = [],
	waitingOnMe = null,
}: GameStatsProps ) {
	const creatureTypes = useCreatureTypeNames( gameSlug );

	const [ stats, setStats ] = useState< GameStatsData | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	const [ rosterHealthOpen, setRosterHealthOpen ] = useState( false );
	const [ rosterHealthPlayers, setRosterHealthPlayers ] = useState<
		PlayerWithoutActiveCharacter[] | null
	>( null );

	function toggleRosterHealth() {
		if ( rosterHealthOpen ) {
			setRosterHealthOpen( false );
			return;
		}
		setRosterHealthOpen( true );
		if ( rosterHealthPlayers === null ) {
			api.gameStats( gameSlug )
				.playersWithoutActiveCharacter()
				.then( setRosterHealthPlayers )
				.catch( () => setRosterHealthPlayers( [] ) );
		}
	}

	useEffect( () => {
		setLoading( true );
		api.gameStats( gameSlug )
			.get()
			.then( ( result ) => {
				setStats( result );
				setLoading( false );
			} )
			.catch( ( err: unknown ) => {
				setError(
					errorMessage(
						err,
						__( 'Failed to load the dashboard.', 'beyond-elysium' )
					)
				);
				setLoading( false );
			} );
	}, [ gameSlug ] );

	return (
		<>
			{ error && (
				<div className="be-game-dashboard__error" role="alert">
					{ error }
				</div>
			) }

			{ loading || ! stats ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : (
				<>
					<div className="be-game-dashboard__cards">
						<div className="be-game-dashboard__card">
							<span className="be-game-dashboard__card-value">
								{ sumCounts( stats.characters_by_stack ) }
							</span>
							<span className="be-game-dashboard__card-label">
								{ __( 'Characters', 'beyond-elysium' ) }
							</span>
							<ul className="be-game-dashboard__breakdown">
								{ Object.entries(
									stats.characters_by_stack
								).map( ( [ stack, count ] ) => (
									<li key={ stack }>
										{ creatureTypeName(
											stack,
											creatureTypes
										) }
										: { count }
									</li>
								) ) }
							</ul>
						</div>

						<div className="be-game-dashboard__card">
							<span className="be-game-dashboard__card-value">
								{ stats.pending_changes }
							</span>
							<span className="be-game-dashboard__card-label">
								{ __( 'Pending Changes', 'beyond-elysium' ) }
							</span>
						</div>

						{ waitingOnMe !== null && (
							<div className="be-game-dashboard__card">
								<span className="be-game-dashboard__card-value">
									{ waitingOnMe }
								</span>
								<span className="be-game-dashboard__card-label">
									{ __( 'Waiting on you', 'beyond-elysium' ) }
								</span>
							</div>
						) }

						<div className="be-game-dashboard__card">
							<span className="be-game-dashboard__card-value">
								{ stats.active_plots }
							</span>
							<span className="be-game-dashboard__card-label">
								{ __( 'Active Plots', 'beyond-elysium' ) }
							</span>
						</div>

						<div className="be-game-dashboard__card">
							<span className="be-game-dashboard__card-value">
								{ stats.characters_needing_attention }
							</span>
							<span className="be-game-dashboard__card-label">
								{ __(
									'Characters Needing Attention',
									'beyond-elysium'
								) }
							</span>
						</div>

						<div className="be-game-dashboard__card">
							<span className="be-game-dashboard__card-value">
								{ sumCounts( stats.characters_by_status ) }
							</span>
							<span className="be-game-dashboard__card-label">
								{ __( 'By Status', 'beyond-elysium' ) }
							</span>
							<ul className="be-game-dashboard__breakdown">
								{ Object.entries(
									stats.characters_by_status
								).map( ( [ status, count ] ) => (
									<li key={ status }>
										{ characterStatusLabel( status ) }:{ ' ' }
										{ count }
									</li>
								) ) }
							</ul>
						</div>

						<div className="be-game-dashboard__card">
							<button
								type="button"
								className="be-game-dashboard__card-toggle"
								onClick={ toggleRosterHealth }
								aria-expanded={ rosterHealthOpen }
							>
								<span className="be-game-dashboard__card-value">
									{ stats.players_without_active_character }
								</span>
								<span className="be-game-dashboard__card-label">
									{ __(
										'Players Without an Active Character',
										'beyond-elysium'
									) }
								</span>
							</button>
							{ rosterHealthOpen &&
								( stats.players_without_active_character ===
								0 ? (
									<p>
										{ __(
											'Everyone has an active character.',
											'beyond-elysium'
										) }
									</p>
								) : rosterHealthPlayers === null ? (
									<p>
										{ __( 'Loading…', 'beyond-elysium' ) }
									</p>
								) : (
									<ul className="be-game-dashboard__breakdown">
										{ rosterHealthPlayers.map(
											( player ) => (
												<li key={ player.wp_user_id }>
													{ player.display_name ??
														`#${ player.wp_user_id }` }
												</li>
											)
										) }
									</ul>
								) ) }
						</div>
					</div>

					{ links.length > 0 && (
						<nav className="be-game-dashboard__links">
							{ links.map( ( link ) => (
								<a key={ link.href } href={ link.href }>
									{ link.label }
								</a>
							) ) }
						</nav>
					) }

					<section className="be-game-dashboard__section">
						<h2>{ __( 'Recent Activity', 'beyond-elysium' ) }</h2>
						{ stats.recent_activity.length === 0 ? (
							<p>{ __( 'Nothing yet.', 'beyond-elysium' ) }</p>
						) : (
							<ul className="be-game-dashboard__list">
								{ stats.recent_activity.map( ( change ) => (
									<li key={ change.id }>
										{ change.character_name ??
											`#${ change.character_id }` }{ ' ' }
										—{ ' ' }
										{ describeChange(
											change.change_type,
											change.change_data
										) }{ ' ' }
										<span className="be-game-dashboard__badge">
											{ changeStatusLabel(
												change.status
											) }
										</span>
									</li>
								) ) }
							</ul>
						) }
					</section>
				</>
			) }
		</>
	);
}

export default GameStats;
