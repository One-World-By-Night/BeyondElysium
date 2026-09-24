/**
 * The player-facing fixed page: a chronicle switcher plus tabs for Characters, Sheet, Edit, and My Plots & Rumors.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { CSSProperties } from 'react';
import { useChronicleSwitcher } from '../../lib/useChronicleSwitcher';
import { ChronicleSwitcher } from '../shared/ChronicleSwitcher';
import { TabStrip } from '../shared/TabStrip';
import { CharacterList } from '../character/CharacterList';
import { CharacterSheet } from '../character/CharacterSheet';
import { CharacterEditor } from '../character/CharacterEditor';
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
import HelpButton from '../shared/HelpButton';
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
		{ key: PLAYER_TABS.sheet, label: __( 'Sheet', 'beyond-elysium' ) },
		{ key: PLAYER_TABS.edit, label: __( 'Edit', 'beyond-elysium' ) },
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

	return (
		<div className="be-my-chronicle-page" style={ accentStyle }>
			<div className="be-help-heading">
				<h1>{ __( 'My Chronicle', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="my-chronicle" />
			</div>
			<ChronicleSwitcher
				games={ games }
				gameSlug={ gameSlug }
				onChange={ setGameSlug }
				loading={ loadingGames }
				failed={ gamesFailed }
				onRetry={ retryGames }
			/>

			{ ! loadingGames &&
				games.length === 0 &&
				tab === PLAYER_TABS.sendFile && <SendGrapevineFile /> }
			{ ! loadingGames &&
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

			{ gameSlug && capabilitiesFor === gameSlug && (
				<>
					<TabStrip
						tabs={ tabs }
						active={ tab }
						onChange={ setTab }
					/>

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

					{ tab === PLAYER_TABS.sheet &&
						( characterId ? (
							<CharacterSheet
								key={ `${ gameSlug }-${ characterId }` }
								characterId={ characterId }
								gameSlug={ gameSlug }
								capabilities={ capabilities }
							/>
						) : (
							<p>
								{ __(
									'Pick a character from the Characters tab to view its sheet.',
									'beyond-elysium'
								) }
							</p>
						) ) }

					{ tab === PLAYER_TABS.edit && (
						<CharacterEditor
							key={ `${ gameSlug }-${ characterId ?? 'new' }` }
							characterId={ characterId }
							gameSlug={ gameSlug }
							capabilities={ capabilities }
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
				</>
			) }
		</div>
	);
}

export default MyChroniclePage;
