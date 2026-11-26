/**
 * A Storyteller's home in one chronicle: what waits on them, the next game night, who has had no attention and the
 * chronicle's numbers.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import api from '../../api/client';
import HelpButton from '../shared/HelpButton';
import { GameStats } from './GameStats';
import { canIn } from '../../lib/chronicleCapabilities';
import { STORYTELLER_TABS, storytellerTabUrl } from '../../lib/pluginPages';
import { formatStoredDay } from '../../lib/storedTime';
import {
	nightLines,
	spotlightLine,
	waitingLines,
} from '../../lib/storytellerHome';
import type { MyCapabilities } from '../../types';
import type { StorytellerHomeData } from '../../types/home';
import './GameDashboard.css';
import './PlayerHome.css';

export interface StorytellerHomeProps {
	gameSlug: string;
	capabilities?: MyCapabilities;
}

function tabHref( tab: string, gameSlug: string ): string {
	return `${ storytellerTabUrl( tab ) }&game_slug=${ encodeURIComponent(
		gameSlug
	) }`;
}

/**
 * Renders a Storyteller's home for one chronicle.
 */
export function StorytellerHome( {
	gameSlug,
	capabilities,
}: StorytellerHomeProps ) {
	const [ data, setData ] = useState< StorytellerHomeData | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ glanceOpen, setGlanceOpen ] = useState(
		() => ! window.matchMedia( '(max-width: 768px)' ).matches
	);
	const canSeeStats = canIn( 'be_manage_characters', capabilities );

	useEffect( () => {
		setData( null );
		setError( null );
		api.home( gameSlug )
			.storyteller()
			.then( setData )
			.catch( () =>
				setError( __( 'Failed to load your home.', 'beyond-elysium' ) )
			);
	}, [ gameSlug ] );

	const waiting = data ? waitingLines( data, gameSlug ) : [];
	const night = data?.next_session ?? null;
	const lines = night ? nightLines( night ) : [];
	const spotlight = data?.spotlight ?? null;

	return (
		<div className="be-game-dashboard be-home be-home--storyteller">
			<div className="be-help-heading">
				<h2>{ __( 'Home', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="game-dashboard" />
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
						<h3>{ __( 'Waiting on you', 'beyond-elysium' ) }</h3>
						{ waiting.length === 0 ? (
							<p>
								{ __(
									'Nothing waits on you.',
									'beyond-elysium'
								) }
							</p>
						) : (
							<ul className="be-home__needs">
								{ waiting.map( ( line ) => (
									<li
										key={ line.key }
										className="be-home__need be-home__need--waiting"
									>
										<a
											className="be-home__need-link"
											href={ line.href }
										>
											<span className="be-home__need-title">
												{ line.text }
											</span>
										</a>
									</li>
								) ) }
							</ul>
						) }
					</section>

					{ night && (
						<section className="be-game-dashboard__section">
							<h3>
								{ __( 'Next game night', 'beyond-elysium' ) }
							</h3>
							<p className="be-home__session">
								<strong>
									{ formatStoredDay( night.game_date ) }
								</strong>
								{ [ night.start_time, night.place ]
									.filter( Boolean )
									.map( ( part ) => ` · ${ part }` )
									.join( '' ) }
							</p>
							{ lines.map( ( line ) => (
								<p
									key={ line }
									className="be-home__session-line"
								>
									{ line }
								</p>
							) ) }
							<p className="be-home__more">
								<a
									className="be-home__action"
									href={ tabHref(
										data.waiting.downtime
											? STORYTELLER_TABS.downtime
											: STORYTELLER_TABS.gameNights,
										gameSlug
									) }
								>
									{ data.waiting.downtime
										? __(
												'Open Downtime',
												'beyond-elysium'
											)
										: __(
												'Open Game Nights',
												'beyond-elysium'
											) }
								</a>
							</p>
						</section>
					) }

					{ spotlight && spotlight.flagged > 0 && (
						<section className="be-game-dashboard__section">
							<h3>{ __( 'Spotlight', 'beyond-elysium' ) }</h3>
							<p className="be-home__session-line">
								{ spotlightLine(
									spotlight.flagged,
									spotlight.days
								) }
							</p>
							<ul className="be-game-dashboard__list">
								{ spotlight.names.map( ( name ) => (
									<li key={ name }>{ name }</li>
								) ) }
							</ul>
							<p className="be-home__more">
								<a
									className="be-home__action"
									href={ tabHref(
										STORYTELLER_TABS.gameNights,
										gameSlug
									) }
								>
									{ __(
										'Open Game Nights',
										'beyond-elysium'
									) }
								</a>
							</p>
						</section>
					) }
				</>
			) }

			{ canSeeStats && (
				<details
					className="be-home__glance"
					open={ glanceOpen }
					onToggle={ ( e ) =>
						setGlanceOpen( ( e.target as HTMLDetailsElement ).open )
					}
				>
					<summary>{ __( 'At a glance', 'beyond-elysium' ) }</summary>
					{ glanceOpen && <GameStats gameSlug={ gameSlug } /> }
				</details>
			) }
		</div>
	);
}

export default StorytellerHome;
