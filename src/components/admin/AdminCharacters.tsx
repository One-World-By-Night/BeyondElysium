/**
 * Admin page for the staff character roster.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import CharacterList from '../character/CharacterList';
import {
	playerTabUrl,
	PLAYER_TABS,
	newCharacterUrl,
} from '../../lib/pluginPages';
import { useAdminGameSelector } from '../../lib/useAdminGameSelector';
import { GameFilterBar } from './GameFilterBar';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

/**
 * Renders the Characters admin screen.
 */
export function AdminCharacters() {
	const { games, gameSlug, setGameSlug, loading } = useAdminGameSelector();
	const [ showNpcs, setShowNpcs ] = useState( false );

	// URL of the read-only character sheet page that each roster row links to.
	const sheetUrl = playerTabUrl( PLAYER_TABS.sheet );

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h1>{ __( 'Characters', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="admin-characters" />
			</div>

			<GameFilterBar
				games={ games }
				gameSlug={ gameSlug }
				onGameChange={ setGameSlug }
				loading={ loading }
				extraFilters={
					<>
						<label>
							{ ' ' }
							<input
								type="checkbox"
								checked={ showNpcs }
								onChange={ ( e ) =>
									setShowNpcs( e.target.checked )
								}
							/>{ ' ' }
							{ __(
								'Show NPCs instead of player characters',
								'beyond-elysium'
							) }
						</label>
						{ gameSlug && (
							<a
								className="button button-primary"
								href={ newCharacterUrl( gameSlug ) }
							>
								{ __( '+ New Character', 'beyond-elysium' ) }
							</a>
						) }
					</>
				}
			>
				{ gameSlug && (
					<CharacterList
						gameSlug={ gameSlug }
						showNpcs={ showNpcs }
						sheetPageUrl={ sheetUrl }
					/>
				) }
			</GameFilterBar>
		</div>
	);
}

export default AdminCharacters;
