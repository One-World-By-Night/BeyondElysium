/**
 * The player-facing fixed page: a chronicle switcher plus a tab for each thing a player does in a chronicle
 * (Dashboard, Characters, Sheet, Edit, My Plots & Rumors, Who's Who, What I Know, After-Game Report, Reports, Propose
 * an Item, Propose a Group), and the Send a Grapevine File panel.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { CSSProperties } from 'react';
import { useChronicleSwitcher } from '../../lib/useChronicleSwitcher';
import { ChronicleSwitcher } from '../shared/ChronicleSwitcher';
import { JoinChronicle } from '../game/JoinChronicle';
import { DiscordLinkPanel } from '../game/DiscordLinkPanel';
import DemoBanner, { DemoBannerShownContext } from '../shared/DemoBanner';
import { TabStrip } from '../shared/TabStrip';
import { CharacterList } from '../character/CharacterList';
import { CharacterSection } from '../character/CharacterSection';
import { MyPlotsFeed } from '../apr/MyPlotsFeed';
import { GameDashboard } from '../game/GameDashboard';
import { GameCalendar } from '../game/GameCalendar';
import { ReportCards } from '../game/ReportCards';
import { WhosWho } from '../game/WhosWho';
import { WhatIKnow } from '../game/WhatIKnow';
import { AfterGameReport } from '../game/AfterGameReport';
import { SendGrapevineFile } from '../character/SendGrapevineFile';
import ProposeWorldObject from '../world/ProposeWorldObject';
import ProposeFaction from '../faction/ProposeFaction';
import {
	newCharacterUrl,
	playerTabUrl,
	readTabFromUrl,
	writeTabToUrl,
	PLAYER_TABS,
} from '../../lib/pluginPages';
import {
	CHARACTER_SECTION,
	isCharacterTab,
	navKey,
} from '../../lib/characterViews';
import HelpButton from '../shared/HelpButton';
import { AppFrame } from '../app/AppFrame';
import { AppCharacterPicker } from '../app/AppCharacterPicker';
import { characterHref, isAppLayout, otherPage } from '../../lib/appNav';
import { creatureTypeName } from '../../lib/characterLabels';
import { useCreatureTypeNames } from '../../lib/useCreatureTypeNames';
import api from '../../api/client';
import type { Character } from '../../types/character';
import './MyChroniclePage.css';

function readCharacterIdFromUrl(): number | undefined {
	const raw = new URLSearchParams( window.location.search ).get(
		'character_id'
	);
	const parsed = raw ? parseInt( raw, 10 ) : NaN;
	return Number.isFinite( parsed ) && parsed > 0 ? parsed : undefined;
}

export function MyChroniclePage() {
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
		readTabFromUrl( PLAYER_TABS.dashboard )
	);
	const [ characterId ] = useState< number | undefined >(
		readCharacterIdFromUrl
	);
	const [ reportKey, setReportKey ] = useState( 'game-calendar' );
	const [ appLayout ] = useState( isAppLayout );
	const creatureTypes = useCreatureTypeNames( appLayout ? gameSlug : '' );

	const [ reportCharacters, setReportCharacters ] = useState< Character[] >(
		[]
	);
	const [ reportCharacterId, setReportCharacterId ] = useState( '' );
	const [ reportAvailability, setReportAvailability ] = useState< Record<
		string,
		boolean
	> | null >( null );

	useEffect( () => {
		if ( ! gameSlug ) {
			return;
		}
		api.characters( gameSlug )
			.myCharacters()
			.then( setReportCharacters )
			.catch( () => setReportCharacters( [] ) );
	}, [ gameSlug ] );

	useEffect( () => {
		if ( ! gameSlug || ! reportCharacterId ) {
			setReportAvailability( null );
			if ( reportKey === 'rote-cards' ) {
				setReportKey( 'game-calendar' );
			}
			return;
		}
		api.reports( gameSlug )
			.availability( Number( reportCharacterId ) )
			.then( ( result ) => {
				setReportAvailability( result );
				if ( reportKey === 'rote-cards' && ! result[ 'rote-cards' ] ) {
					setReportKey( 'game-calendar' );
				}
			} )
			.catch( () => setReportAvailability( null ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ gameSlug, reportCharacterId ] );

	useEffect( () => {
		writeTabToUrl( tab );
	}, [ tab ] );

	const tabs = [
		{
			key: PLAYER_TABS.dashboard,
			label: __( 'Dashboard', 'beyond-elysium' ),
		},
		{
			key: PLAYER_TABS.characters,
			label: __( 'Characters', 'beyond-elysium' ),
		},
		{ key: CHARACTER_SECTION, label: __( 'Character', 'beyond-elysium' ) },
		{
			key: PLAYER_TABS.plots,
			label: __( 'My Plots & Rumors', 'beyond-elysium' ),
		},
		{
			key: PLAYER_TABS.whosWho,
			label: __( "Who's Who", 'beyond-elysium' ),
		},
		{
			key: PLAYER_TABS.whatIKnow,
			label: __( 'What I Know', 'beyond-elysium' ),
		},
		{
			key: PLAYER_TABS.afterGameReport,
			label: __( 'After-Game Report', 'beyond-elysium' ),
		},
		{
			key: PLAYER_TABS.reports,
			label: __( 'Reports', 'beyond-elysium' ),
		},
		{
			key: PLAYER_TABS.proposeItem,
			label: __( 'Propose an Item', 'beyond-elysium' ),
		},
		{
			key: PLAYER_TABS.proposeFaction,
			label: __( 'Propose a Group', 'beyond-elysium' ),
		},
	];

	const REPORT_KEYS = [
		{
			key: 'game-calendar',
			label: __( 'Game Calendar', 'beyond-elysium' ),
		},
		{
			key: 'location-cards',
			label: __( 'Location Cards', 'beyond-elysium' ),
		},
		{ key: 'rote-cards', label: __( 'Rote Cards', 'beyond-elysium' ) },
	].filter(
		( r ) =>
			r.key !== 'rote-cards' ||
			( reportCharacterId !== '' &&
				reportAvailability?.[ 'rote-cards' ] === true )
	);

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

			<DiscordLinkPanel />

			{ signedIn && ! loadingGames && (
				<JoinChronicle hasMemberships={ games.length > 0 } />
			) }

			{ signedIn &&
				! loadingGames &&
				games.length === 0 &&
				tab === PLAYER_TABS.sendFile && <SendGrapevineFile /> }
			{ signedIn &&
				! loadingGames &&
				games.length === 0 &&
				tab !== PLAYER_TABS.sendFile && (
					<p>
						<a
							href={ playerTabUrl( PLAYER_TABS.sendFile ) }
							onClick={ ( e ) => {
								e.preventDefault();
								setTab( PLAYER_TABS.sendFile );
							} }
						>
							{ __(
								'Send a Grapevine file to a chronicle',
								'beyond-elysium'
							) }
						</a>
					</p>
				) }

			{ ready && (
				<DemoBannerShownContext.Provider value={ true }>
					{ ! appLayout && (
						<TabStrip
							tabs={ tabs }
							active={ navKey( tab ) }
							onChange={ setTab }
						/>
					) }

					{ tab === PLAYER_TABS.sendFile && <SendGrapevineFile /> }

					{ tab === PLAYER_TABS.proposeItem &&
						( characterId ? (
							<ProposeWorldObject
								key={ `${ gameSlug }-${ characterId }` }
								gameSlug={ gameSlug }
								characterId={ characterId }
							/>
						) : (
							<p>
								{ __(
									'Pick a character on the Characters tab first - an item is proposed for one of your characters.',
									'beyond-elysium'
								) }
							</p>
						) ) }

					{ tab === PLAYER_TABS.proposeFaction &&
						( characterId ? (
							<ProposeFaction
								key={ `${ gameSlug }-${ characterId }` }
								gameSlug={ gameSlug }
								characterId={ characterId }
							/>
						) : (
							<p>
								{ __(
									'Pick a character on the Characters tab first - a group is proposed for one of your characters.',
									'beyond-elysium'
								) }
							</p>
						) ) }

					{ tab === PLAYER_TABS.dashboard && (
						<GameDashboard
							key={ gameSlug }
							gameSlug={ gameSlug }
							sheetPageUrl={ playerTabUrl( PLAYER_TABS.sheet ) }
							capabilities={ capabilities }
						/>
					) }

					{ tab === PLAYER_TABS.characters && (
						<>
							<p className="be-my-chronicle-page__new-character">
								<a href={ newCharacterUrl( gameSlug ) }>
									{ __(
										'+ New Character',
										'beyond-elysium'
									) }
								</a>{ ' ' }
								<a
									href={ playerTabUrl(
										PLAYER_TABS.sendFile
									) }
									onClick={ ( e ) => {
										e.preventDefault();
										setTab( PLAYER_TABS.sendFile );
									} }
								>
									{ __(
										'Send a Grapevine file',
										'beyond-elysium'
									) }
								</a>
							</p>
							<CharacterList
								key={ gameSlug }
								gameSlug={ gameSlug }
								sheetPageUrl={ playerTabUrl(
									PLAYER_TABS.sheet
								) }
								capabilities={ capabilities }
							/>
						</>
					) }

					{ isCharacterTab( tab ) && (
						<CharacterSection
							gameSlug={ gameSlug }
							characterId={ characterId }
							tab={ tab }
							capabilities={ capabilities }
							onView={ setTab }
							onPickCharacter={ () =>
								setTab( PLAYER_TABS.characters )
							}
						/>
					) }

					{ tab === PLAYER_TABS.plots && (
						<MyPlotsFeed key={ gameSlug } gameSlug={ gameSlug } />
					) }

					{ tab === PLAYER_TABS.whosWho && (
						<WhosWho key={ gameSlug } gameSlug={ gameSlug } />
					) }

					{ tab === PLAYER_TABS.whatIKnow && (
						<WhatIKnow key={ gameSlug } gameSlug={ gameSlug } />
					) }

					{ tab === PLAYER_TABS.afterGameReport && (
						<AfterGameReport
							key={ gameSlug }
							gameSlug={ gameSlug }
						/>
					) }

					{ tab === PLAYER_TABS.reports && (
						<div className="be-my-chronicle-page__reports">
							{ reportCharacters.length > 0 && (
								<label className="be-my-chronicle-page__report-character">
									<span>
										{ __(
											'Scope to character (for Rote Cards)',
											'beyond-elysium'
										) }
									</span>
									<select
										value={ reportCharacterId }
										onChange={ ( e ) =>
											setReportCharacterId(
												e.target.value
											)
										}
									>
										<option value="">
											{ __( 'None', 'beyond-elysium' ) }
										</option>
										{ reportCharacters.map( ( c ) => (
											<option key={ c.id } value={ c.id }>
												{ c.name }
											</option>
										) ) }
									</select>
								</label>
							) }
							<TabStrip
								tabs={ REPORT_KEYS }
								active={ reportKey }
								onChange={ setReportKey }
							/>
							{ reportKey === 'game-calendar' && (
								<GameCalendar
									key={ gameSlug }
									gameSlug={ gameSlug }
								/>
							) }
							{ ( reportKey === 'location-cards' ||
								reportKey === 'rote-cards' ) && (
								<ReportCards
									key={ `${ gameSlug }-${ reportKey }-${ reportCharacterId }` }
									gameSlug={ gameSlug }
									reportKey={ reportKey }
									characterId={
										reportCharacterId
											? Number( reportCharacterId )
											: undefined
									}
								/>
							) }
						</div>
					) }
				</DemoBannerShownContext.Provider>
			) }
		</>
	);

	if ( appLayout ) {
		const ownCharacterId = reportCharacters.some(
			( c ) => Number( c.id ) === characterId
		)
			? characterId
			: undefined;
		const role = games.find( ( g ) => g.slug === gameSlug )?.role;
		return (
			<div className="be-my-chronicle-page" style={ accentStyle }>
				<AppFrame
					pageTitle={ __( 'My Chronicle', 'beyond-elysium' ) }
					helpKey="my-chronicle"
					sections={ ready ? tabs : [] }
					active={ navKey( tab ) }
					onSelect={ setTab }
					places={ [
						{
							key: PLAYER_TABS.dashboard,
							label: __( 'Home', 'beyond-elysium' ),
						},
						{
							key: characterId
								? CHARACTER_SECTION
								: PLAYER_TABS.characters,
							label: __( 'Character', 'beyond-elysium' ),
						},
						{
							key: PLAYER_TABS.plots,
							label: __( 'Plots', 'beyond-elysium' ),
						},
					] }
					placeCount={ 3 }
					switcher={ switcher }
					character={
						ready && (
							<AppCharacterPicker
								characters={ reportCharacters }
								characterId={ ownCharacterId }
								typeName={ ( slug ) =>
									creatureTypeName( slug, creatureTypes )
								}
								onPick={ ( id ) =>
									window.location.assign(
										characterHref(
											window.location.href,
											id,
											tab
										)
									)
								}
							/>
						)
					}
					otherPage={ otherPage( 'player', role, gameSlug ) }
				>
					{ content }
				</AppFrame>
			</div>
		);
	}

	return (
		<div className="be-my-chronicle-page" style={ accentStyle }>
			<div className="be-help-heading">
				<h1>{ __( 'My Chronicle', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="my-chronicle" />
			</div>
			{ switcher }
			{ content }
		</div>
	);
}

export default MyChroniclePage;
