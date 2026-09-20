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
import type { CSSProperties } from 'react';
import { useChronicleSwitcher } from '../../lib/useChronicleSwitcher';
import { ChronicleSwitcher } from '../shared/ChronicleSwitcher';
import { TabStrip } from '../shared/TabStrip';
import { GameDashboard } from '../game/GameDashboard';
import { ApprovalQueue } from '../changes/ApprovalQueue';
import { PlotManager } from '../apr/PlotManager';
import { BoonLedger } from '../world/BoonLedger';
import { WorldObjectManager } from '../world/WorldObjectManager';
import { GameNights } from '../game/GameNights';
import { ReleaseBatches } from '../game/ReleaseBatches';
import { DowntimeQueue } from '../game/DowntimeQueue';
import { StaffQueue } from '../game/StaffQueue';
import { FactionsAndPositions } from '../faction/FactionsAndPositions';
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
		accentColor,
	} = useChronicleSwitcher();
	// 1.2.7-design-workflow.md §E3 - only set when a chronicle (or the site) actually
	// overrides the accent; an unset chronicle sends no inline style at all, so the six
	// --be-st-accent consumers fall through to CharacterSheet.css's/PlotManager.css's own
	// CSS default unchanged (Decision 041's "un-styled renders byte-identical" guarantee).
	const accentStyle: CSSProperties | undefined = accentColor
		? ( { '--be-st-accent': accentColor } as CSSProperties )
		: undefined;
	const [ tab, setTab ] = useState( () =>
		readTabFromUrl( STORYTELLER_TABS.dashboard )
	);
	// The Downtime queue's own "open the plot thread" link (1.1.0 §3.3) arrives as
	// ?open_plot=; read once, not kept in sync with the URL afterward.
	const [ openPlotId ] = useState( () => {
		const raw = new URLSearchParams( window.location.search ).get(
			'open_plot'
		);
		return raw ? Number( raw ) : null;
	} );

	useEffect( () => {
		writeTabToUrl( tab );
	}, [ tab ] );

	const tabs: Tab[] = [
		capabilities.be_manage_characters && {
			key: STORYTELLER_TABS.dashboard,
			label: __( 'Dashboard', 'beyond-elysium' ),
		},
		( capabilities.be_manage_plots ||
			capabilities.be_manage_characters ) && {
			key: STORYTELLER_TABS.myQueue,
			label: __( 'My Queue', 'beyond-elysium' ),
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
		capabilities.be_manage_sessions && {
			key: STORYTELLER_TABS.gameNights,
			label: __( 'Game Nights', 'beyond-elysium' ),
		},
		capabilities.be_manage_plots && {
			key: STORYTELLER_TABS.releases,
			label: __( 'Releases', 'beyond-elysium' ),
		},
		capabilities.be_manage_plots && {
			key: STORYTELLER_TABS.downtime,
			label: __( 'Downtime', 'beyond-elysium' ),
		},
		capabilities.be_manage_factions && {
			key: STORYTELLER_TABS.factions,
			label: __( 'Factions', 'beyond-elysium' ),
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
		<div className="be-storyteller-toolkit-page" style={ accentStyle }>
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

						{ tab === STORYTELLER_TABS.myQueue && (
							<StaffQueue
								key={ gameSlug }
								gameSlug={ gameSlug }
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
								initialSelectedPlotId={ openPlotId }
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

						{ tab === STORYTELLER_TABS.gameNights && (
							<GameNights
								key={ gameSlug }
								gameSlug={ gameSlug }
								capabilities={ capabilities }
							/>
						) }

						{ tab === STORYTELLER_TABS.releases && (
							<ReleaseBatches
								key={ gameSlug }
								gameSlug={ gameSlug }
								capabilities={ capabilities }
							/>
						) }

						{ tab === STORYTELLER_TABS.downtime && (
							<DowntimeQueue
								key={ gameSlug }
								gameSlug={ gameSlug }
								capabilities={ capabilities }
							/>
						) }

						{ tab === STORYTELLER_TABS.factions && (
							<FactionsAndPositions
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
