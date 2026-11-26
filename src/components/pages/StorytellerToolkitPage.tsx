/**
 * The Storyteller-facing fixed page: a chronicle switcher plus tabs for Dashboard, My Queue, Approval Queue,
 * Characters, Players, Plots & Rumors, Secrets, Boon Ledger, Items & Locations, Game Nights, Releases, Downtime,
 * Factions, Relationships and Email Log.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { CSSProperties } from 'react';
import { useChronicleSwitcher } from '../../lib/useChronicleSwitcher';
import { ChronicleSwitcher } from '../shared/ChronicleSwitcher';
import DemoBanner, { DemoBannerShownContext } from '../shared/DemoBanner';
import { TabStrip } from '../shared/TabStrip';
import { StorytellerHome } from '../game/StorytellerHome';
import { CharacterList } from '../character/CharacterList';
import { SecretsList } from '../shared/SecretsList';
import { ApprovalQueue } from '../changes/ApprovalQueue';
import { PlotManager } from '../apr/PlotManager';
import { BoonLedger } from '../world/BoonLedger';
import { WorldObjectManager } from '../world/WorldObjectManager';
import { GameNights } from '../game/GameNights';
import { ReleaseBatches } from '../game/ReleaseBatches';
import { DowntimeQueue } from '../game/DowntimeQueue';
import { StaffQueue } from '../game/StaffQueue';
import { FactionsAndPositions } from '../faction/FactionsAndPositions';
import { ChroniclePlayers } from '../game/ChroniclePlayers';
import { CatalogTab } from '../catalog/CatalogTab';
import { RelationshipChart } from '../apr/RelationshipChart';
import { EmailLog } from '../game/EmailLog';
import { ToolkitReports } from '../reports/ToolkitReports';
import {
	newCharacterUrl,
	playerTabUrl,
	readTabFromUrl,
	writeTabToUrl,
	PLAYER_TABS,
	STORYTELLER_TABS,
} from '../../lib/pluginPages';
import type { Tab } from '../shared/TabStrip';
import HelpButton from '../shared/HelpButton';
import { AppFrame } from '../app/AppFrame';
import { NoticeBell } from '../app/NoticeBell';
import { isAppLayout, otherPage } from '../../lib/appNav';
import './StorytellerToolkitPage.css';

export function StorytellerToolkitPage() {
	const {
		signedIn,
		games,
		gameSlug,
		setGameSlug,
		capabilities,
		capabilitiesFor,
		loadingGames,
		gamesFailed,
		retryGames,
		accentColor,
		pendingCounts,
	} = useChronicleSwitcher();
	const accentStyle: CSSProperties | undefined = accentColor
		? ( { '--be-st-accent': accentColor } as CSSProperties )
		: undefined;
	const [ tab, setTab ] = useState( () =>
		readTabFromUrl( STORYTELLER_TABS.dashboard )
	);
	const [ showNpcs, setShowNpcs ] = useState( false );
	const [ appLayout ] = useState( isAppLayout );
	// The Downtime queue's own "open the plot thread" link arrives as ?open_plot=.
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
		( capabilities.be_manage_characters ||
			capabilities.be_manage_plots ||
			capabilities.be_manage_sessions ||
			capabilities.be_manage_boons ) && {
			key: STORYTELLER_TABS.dashboard,
			label: __( 'Home', 'beyond-elysium' ),
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
		capabilities.be_manage_characters && {
			key: STORYTELLER_TABS.characters,
			label: __( 'Characters', 'beyond-elysium' ),
		},
		capabilities.be_manage_characters && {
			key: STORYTELLER_TABS.players,
			label: __( 'Players', 'beyond-elysium' ),
		},
		capabilities.be_manage_plots && {
			key: STORYTELLER_TABS.plots,
			label: __( 'Plots & Rumors', 'beyond-elysium' ),
		},
		capabilities.be_manage_plots && {
			key: STORYTELLER_TABS.secrets,
			label: __( 'Secrets', 'beyond-elysium' ),
		},
		capabilities.be_manage_boons && {
			key: STORYTELLER_TABS.boonLedger,
			label: __( 'Boon Ledger', 'beyond-elysium' ),
		},
		capabilities.be_manage_world_objects && {
			key: STORYTELLER_TABS.worldObjects,
			label: __( 'Items & Locations', 'beyond-elysium' ),
		},
		capabilities.be_manage_schemas && {
			key: STORYTELLER_TABS.catalog,
			label: __( 'Catalog', 'beyond-elysium' ),
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
		capabilities.be_manage_connections && {
			key: STORYTELLER_TABS.relationships,
			label: __( 'Relationships', 'beyond-elysium' ),
		},
		capabilities.be_view_reports && {
			key: STORYTELLER_TABS.reports,
			label: __( 'Reports', 'beyond-elysium' ),
		},
		capabilities.be_manage_characters && {
			key: STORYTELLER_TABS.emailLog,
			label: __( 'Email Log', 'beyond-elysium' ),
		},
	].filter( Boolean ) as Tab[];

	useEffect( () => {
		if ( tabs.length > 0 && ! tabs.some( ( t ) => t.key === tab ) ) {
			setTab( tabs[ 0 ].key );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tabs.map( ( t ) => t.key ).join( ',' ) ] );

	const ready = !! gameSlug && capabilitiesFor === gameSlug;

	const switcher = (
		<ChronicleSwitcher
			games={ games }
			gameSlug={ gameSlug }
			onChange={ setGameSlug }
			loading={ loadingGames }
			failed={ gamesFailed }
			onRetry={ retryGames }
			pendingCounts={ pendingCounts }
			compact={ appLayout }
			signedIn={ signedIn }
		/>
	);

	const content = (
		<>
			{ gameSlug && <DemoBanner gameSlug={ gameSlug } /> }

			{ ready &&
				( tabs.length === 0 ? (
					<p>
						{ __(
							"You don't hold a Storyteller role in this chronicle.",
							'beyond-elysium'
						) }
					</p>
				) : (
					<DemoBannerShownContext.Provider value={ true }>
						{ ! appLayout && (
							<TabStrip
								tabs={ tabs }
								active={ tab }
								onChange={ setTab }
							/>
						) }

						{ tab === STORYTELLER_TABS.dashboard && (
							<StorytellerHome
								key={ gameSlug }
								gameSlug={ gameSlug }
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

						{ tab === STORYTELLER_TABS.characters && (
							<>
								<p className="be-storyteller-toolkit-page__new-character">
									<a
										className="be-storyteller-toolkit-page__new-character-link"
										href={ newCharacterUrl( gameSlug ) }
									>
										{ __(
											'+ New Character',
											'beyond-elysium'
										) }
									</a>{ ' ' }
									<a
										className="be-storyteller-toolkit-page__new-character-link"
										href={ newCharacterUrl( gameSlug, {
											npc: true,
										} ) }
									>
										{ __( '+ New NPC', 'beyond-elysium' ) }
									</a>
								</p>
								<label className="be-storyteller-toolkit-page__npc-toggle">
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
								<CharacterList
									key={ `${ gameSlug }-${ showNpcs }` }
									gameSlug={ gameSlug }
									showNpcs={ showNpcs }
									sheetPageUrl={ playerTabUrl(
										PLAYER_TABS.sheet
									) }
									capabilities={ capabilities }
								/>
							</>
						) }

						{ tab === STORYTELLER_TABS.players && (
							<ChroniclePlayers
								key={ gameSlug }
								gameSlug={ gameSlug }
								canGrantStaffAccess={
									capabilities.be_grant_staff_access
								}
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

						{ tab === STORYTELLER_TABS.secrets && (
							<SecretsList
								key={ gameSlug }
								gameSlug={ gameSlug }
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
								showEditor
							/>
						) }

						{ tab === STORYTELLER_TABS.catalog && (
							<CatalogTab
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

						{ tab === STORYTELLER_TABS.relationships && (
							<RelationshipChart
								key={ gameSlug }
								gameSlug={ gameSlug }
							/>
						) }

						{ tab === STORYTELLER_TABS.reports && (
							<ToolkitReports
								key={ gameSlug }
								gameSlug={ gameSlug }
							/>
						) }

						{ tab === STORYTELLER_TABS.emailLog && (
							<EmailLog key={ gameSlug } gameSlug={ gameSlug } />
						) }
					</DemoBannerShownContext.Provider>
				) ) }
		</>
	);

	if ( appLayout ) {
		return (
			<div className="be-storyteller-toolkit-page" style={ accentStyle }>
				<AppFrame
					pageTitle={ __( 'Storyteller Toolkit', 'beyond-elysium' ) }
					helpKey="storyteller-toolkit"
					sections={ ready ? tabs : [] }
					active={ tab }
					onSelect={ setTab }
					places={ [
						{
							key: STORYTELLER_TABS.dashboard,
							label: __( 'Home', 'beyond-elysium' ),
						},
						{
							key: STORYTELLER_TABS.approvalQueue,
							label: __( 'Approvals', 'beyond-elysium' ),
						},
						{
							key: STORYTELLER_TABS.characters,
							label: __( 'Characters', 'beyond-elysium' ),
						},
						{
							key: STORYTELLER_TABS.plots,
							label: __( 'Plots', 'beyond-elysium' ),
						},
					] }
					placeCount={ 4 }
					bell={
						ready && gameSlug ? (
							<NoticeBell gameSlug={ gameSlug } section={ tab } />
						) : null
					}
					switcher={ switcher }
					otherPage={ otherPage(
						'storyteller',
						undefined,
						gameSlug
					) }
				>
					{ content }
				</AppFrame>
			</div>
		);
	}

	return (
		<div className="be-storyteller-toolkit-page" style={ accentStyle }>
			<div className="be-help-heading">
				<h1>{ __( 'Storyteller Toolkit', 'beyond-elysium' ) }</h1>
				{ ready && gameSlug && (
					<NoticeBell gameSlug={ gameSlug } section={ tab } />
				) }
				<HelpButton helpKey="storyteller-toolkit" />
			</div>
			{ switcher }
			{ content }
		</div>
	);
}

export default StorytellerToolkitPage;
