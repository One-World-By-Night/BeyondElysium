/**
 * The Loading / no-games-yet / game-select shape seven wp-admin pages (Characters, Game
 * Nights, Plots, Query, Release Batches, Reports, World Objects) each hand-rolled
 * identically until this was extracted (1.1.1 audit) - paired with `useAdminGameSelector`
 * for the state and data-loading half of the same duplication.
 */
import { __ } from '@wordpress/i18n';
import type { Game } from '../../types';

export interface GameFilterBarProps {
	games: Game[];
	gameSlug: string;
	onGameChange: ( slug: string ) => void;
	loading: boolean;
	/** Extra controls rendered inside the same filter row, after the game select. */
	extraFilters?: React.ReactNode;
	/** The page's own content, rendered below the filter row once a game is loaded. */
	children?: React.ReactNode;
}

export function GameFilterBar( {
	games,
	gameSlug,
	onGameChange,
	loading,
	extraFilters,
	children,
}: GameFilterBarProps ) {
	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	if ( games.length === 0 ) {
		return (
			<p>
				{ __(
					'No games exist yet - create one under Beyond Elysium → System Config → Games first.',
					'beyond-elysium'
				) }
			</p>
		);
	}

	return (
		<>
			<div className="be-admin__filters">
				<label>
					{ __( 'Game', 'beyond-elysium' ) }{ ' ' }
					<select
						value={ gameSlug }
						onChange={ ( e ) => onGameChange( e.target.value ) }
					>
						{ games.map( ( g ) => (
							<option key={ g.slug } value={ g.slug }>
								{ g.name }
							</option>
						) ) }
					</select>
				</label>
				{ extraFilters }
			</div>
			{ children }
		</>
	);
}

export default GameFilterBar;
