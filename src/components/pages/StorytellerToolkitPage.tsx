/**
 * The Storyteller-facing fixed page (page-consolidation-design.md): a chronicle
 * switcher plus tabs for Dashboard, Approval Queue, Plots & Rumors, Boon Ledger, and
 * Items & Locations - replacing separate pages that each used to duplicate per
 * chronicle. Each tab hides itself when `useChronicleSwitcher()`'s per-chronicle
 * capabilities say the current user doesn't hold it in the currently selected
 * chronicle - an AST who only narrates one chronicle sees fewer tabs there than
 * in one they HST, resolved fresh on every switch rather than read from the
 * site-wide, chronicle-blind snapshot every page already carries.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useChronicleSwitcher } from '../../lib/useChronicleSwitcher';
import { ChronicleSwitcher } from '../shared/ChronicleSwitcher';
import { TabStrip } from '../shared/TabStrip';
import { GameDashboard } from '../game/GameDashboard';
import { ApprovalQueue } from '../changes/ApprovalQueue';
import { PlotManager } from '../apr/PlotManager';
import { BoonLedger } from '../world/BoonLedger';
import { WorldObjectManager } from '../world/WorldObjectManager';
import {
	playerTabUrl,
	readTabFromUrl,
	writeTabToUrl,
	PLAYER_TABS,
	STORYTELLER_TABS,
} from '../../lib/pluginPages';
import type { Tab } from '../shared/TabStrip';
import HelpButton from '../shared/HelpButton';
import './StorytellerToolkitPage.css';

export function StorytellerToolkitPage() {
	const {
		games,
		gameSlug,
		setGameSlug,
		capabilities,
		capabilitiesFor,
		loadingGames,
		gamesFailed,
		retryGames,
	} = useChronicleSwitcher();
	const [ tab, setTab ] = useState( () =>
		readTabFromUrl( STORYTELLER_TABS.dashboard )
	);

	useEffect( () => {
		writeTabToUrl( tab );
	}, [ tab ] );

	const tabs: Tab[] = [
		capabilities.be_manage_characters && {
			key: STORYTELLER_TABS.dashboard,
			label: __( 'Dashboard', 'beyond-elysium' ),
		},
		capabilities.be_manage_characters && {
			key: STORYTELLER_TABS.approvalQueue,
			label: __( 'Approval Queue', 'beyond-elysium' ),
		},
		capabilities.be_manage_plots && {
			key: STORYTELLER_TABS.plots,
			label: __( 'Plots & Rumors', 'beyond-elysium' ),
		},
		capabilities.be_manage_boons && {
			key: STORYTELLER_TABS.boonLedger,
			label: __( 'Boon Ledger', 'beyond-elysium' ),
		},
		capabilities.be_manage_world_objects && {
			key: STORYTELLER_TABS.worldObjects,
			label: __( 'Items & Locations', 'beyond-elysium' ),
		},
	].filter( Boolean ) as Tab[];

	// The previously-active tab can become unavailable after switching to a chronicle
	// where the user holds a narrower role - falls back to the first tab still visible.
	useEffect( () => {
		if ( tabs.length > 0 && ! tabs.some( ( t ) => t.key === tab ) ) {
			setTab( tabs[ 0 ].key );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tabs.map( ( t ) => t.key ).join( ',' ) ] );

	return (
		<div className="be-storyteller-toolkit-page">
			<div className="be-help-heading">
				<h1>{ __( 'Storyteller Toolkit', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="storyteller-toolkit" />
			</div>
			<ChronicleSwitcher
				games={ games }
				gameSlug={ gameSlug }
				onChange={ setGameSlug }
				loading={ loadingGames }
				failed={ gamesFailed }
				onRetry={ retryGames }
			/>

			{ gameSlug &&
				capabilitiesFor === gameSlug &&
				( tabs.length === 0 ? (
					<p>
						{ __(
							"You don't hold a Storyteller role in this chronicle.",
							'beyond-elysium'
						) }
					</p>
				) : (
					<>
						<TabStrip
							tabs={ tabs }
							active={ tab }
							onChange={ setTab }
						/>

						{ tab === STORYTELLER_TABS.dashboard && (
							<GameDashboard
								key={ gameSlug }
								gameSlug={ gameSlug }
								sheetPageUrl={ playerTabUrl(
									PLAYER_TABS.sheet
								) }
								capabilities={ capabilities }
							/>
						) }

						{ tab === STORYTELLER_TABS.approvalQueue && (
							<ApprovalQueue
								key={ gameSlug }
								gameSlug={ gameSlug }
							/>
						) }

						{ tab === STORYTELLER_TABS.plots && (
							<PlotManager
								key={ gameSlug }
								gameSlug={ gameSlug }
								capabilities={ capabilities }
							/>
						) }

						{ tab === STORYTELLER_TABS.boonLedger && (
							<BoonLedger
								key={ gameSlug }
								gameSlug={ gameSlug }
								capabilities={ capabilities }
							/>
						) }

						{ tab === STORYTELLER_TABS.worldObjects && (
							<WorldObjectManager
								key={ gameSlug }
								gameSlug={ gameSlug }
							/>
						) }
					</>
				) ) }
		</div>
	);
}

export default StorytellerToolkitPage;
