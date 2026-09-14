/**
 * The player-facing fixed page (page-consolidation-design.md): a chronicle switcher
 * plus tabs for Characters, Sheet, Edit, and My Plots & Rumors - replacing four
 * separate pages that each used to duplicate per chronicle. Takes zero required
 * props, same as VerifyCharacter - all of its state (which chronicle, which tab,
 * which character) comes from useChronicleSwitcher() and the URL.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useChronicleSwitcher } from '../../lib/useChronicleSwitcher';
import { ChronicleSwitcher } from '../shared/ChronicleSwitcher';
import { TabStrip } from '../shared/TabStrip';
import { CharacterList } from '../character/CharacterList';
import { CharacterSheet } from '../character/CharacterSheet';
import { CharacterEditor } from '../character/CharacterEditor';
import { MyPlotsFeed } from '../apr/MyPlotsFeed';
import { GameDashboard } from '../game/GameDashboard';
import { playerTabUrl, readTabFromUrl, writeTabToUrl, PLAYER_TABS } from '../../lib/pluginPages';
import './MyChroniclePage.css';

function readCharacterIdFromUrl(): number | undefined {
	const raw = new URLSearchParams( window.location.search ).get( 'character_id' );
	const parsed = raw ? parseInt( raw, 10 ) : NaN;
	return Number.isFinite( parsed ) && parsed > 0 ? parsed : undefined;
}

export function MyChroniclePage() {
	const { games, gameSlug, setGameSlug, loadingGames } = useChronicleSwitcher();
	const [ tab, setTab ] = useState( () => readTabFromUrl( PLAYER_TABS.dashboard ) );
	// Selecting a different character navigates via CharacterList's own real link
	// (a full page load to ?tab=sheet&character_id=N&game_slug=S), not client-side
	// state - so this only ever needs to be read once, on mount.
	const [ characterId ] = useState<number | undefined>( readCharacterIdFromUrl );

	useEffect( () => {
		writeTabToUrl( tab );
	}, [ tab ] );

	const tabs = [
		{ key: PLAYER_TABS.dashboard, label: __( 'Dashboard', 'beyond-elysium' ) },
		{ key: PLAYER_TABS.characters, label: __( 'Characters', 'beyond-elysium' ) },
		{ key: PLAYER_TABS.sheet, label: __( 'Sheet', 'beyond-elysium' ) },
		{ key: PLAYER_TABS.edit, label: __( 'Edit', 'beyond-elysium' ) },
		{ key: PLAYER_TABS.plots, label: __( 'My Plots & Rumors', 'beyond-elysium' ) },
	];

	return (
		<div className="be-my-chronicle-page">
			<ChronicleSwitcher games={ games } gameSlug={ gameSlug } onChange={ setGameSlug } loading={ loadingGames } />

			{ gameSlug && (
				<>
					<TabStrip tabs={ tabs } active={ tab } onChange={ setTab } />

					{ tab === PLAYER_TABS.dashboard && (
						<GameDashboard
							key={ gameSlug }
							gameSlug={ gameSlug }
							sheetPageUrl={ playerTabUrl( PLAYER_TABS.sheet ) }
						/>
					) }

					{ tab === PLAYER_TABS.characters && (
						<CharacterList
							key={ gameSlug }
							gameSlug={ gameSlug }
							sheetPageUrl={ playerTabUrl( PLAYER_TABS.sheet ) }
						/>
					) }

					{ tab === PLAYER_TABS.sheet && (
						characterId ? (
							<CharacterSheet key={ `${ gameSlug }-${ characterId }` } characterId={ characterId } gameSlug={ gameSlug } />
						) : (
							<p>{ __( 'Pick a character from the Characters tab to view its sheet.', 'beyond-elysium' ) }</p>
						)
					) }

					{ tab === PLAYER_TABS.edit && (
						<CharacterEditor key={ `${ gameSlug }-${ characterId ?? 'new' }` } characterId={ characterId } gameSlug={ gameSlug } />
					) }

					{ tab === PLAYER_TABS.plots && (
						<MyPlotsFeed key={ gameSlug } gameSlug={ gameSlug } />
					) }
				</>
			) }
		</div>
	);
}

export default MyChroniclePage;
