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
import { GameCalendar } from '../game/GameCalendar';
import { ReportCards } from '../game/ReportCards';
import { SendGrapevineFile } from '../character/SendGrapevineFile';
import {
	newCharacterUrl,
	playerTabUrl,
	readTabFromUrl,
	writeTabToUrl,
	PLAYER_TABS,
} from '../../lib/pluginPages';
import HelpButton from '../shared/HelpButton';
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
	} = useChronicleSwitcher();
	const [ tab, setTab ] = useState( () =>
		readTabFromUrl( PLAYER_TABS.dashboard )
	);
	// Selecting a different character navigates via CharacterList's own real link
	// (a full page load to ?tab=sheet&character_id=N&game_slug=S), not client-side
	// state - so this only ever needs to be read once, on mount.
	const [ characterId ] = useState< number | undefined >(
		readCharacterIdFromUrl
	);
	const [ reportKey, setReportKey ] = useState( 'game-calendar' );

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
			key: PLAYER_TABS.reports,
			label: __( 'Reports', 'beyond-elysium' ),
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
	];

	return (
		<div className="be-my-chronicle-page">
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

			{ /* A non-member has no chronicle for the switcher above to show at all, and no
			capabilities for the guard below to ever pass - this is how they reach the one
			thing they can still do here (F-122). A real member reaches the same view from
			the Characters tab below instead, with the tab strip still there to click back. */ }
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

			{ /* Each tab shows what the person can do in this chronicle, so it waits for that (F-103). */ }
			{ gameSlug && capabilitiesFor === gameSlug && (
				<>
					<TabStrip
						tabs={ tabs }
						active={ tab }
						onChange={ setTab }
					/>

					{ tab === PLAYER_TABS.sendFile && <SendGrapevineFile /> }

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
							{ /* The Edit tab edits whichever character was last opened, so starting another one needs its own way in (F-108). */ }
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

					{ tab === PLAYER_TABS.reports && (
						<div className="be-my-chronicle-page__reports">
							<div
								className="be-my-chronicle-page__report-picker"
								role="tablist"
							>
								{ REPORT_KEYS.map( ( r ) => (
									<button
										type="button"
										role="tab"
										key={ r.key }
										aria-selected={ reportKey === r.key }
										className={
											'be-my-chronicle-page__report-tab' +
											( reportKey === r.key
												? ' is-active'
												: '' )
										}
										onClick={ () => setReportKey( r.key ) }
									>
										{ r.label }
									</button>
								) ) }
							</div>
							{ reportKey === 'game-calendar' && (
								<GameCalendar
									key={ gameSlug }
									gameSlug={ gameSlug }
								/>
							) }
							{ ( reportKey === 'location-cards' ||
								reportKey === 'rote-cards' ) && (
								<ReportCards
									key={ `${ gameSlug }-${ reportKey }` }
									gameSlug={ gameSlug }
									reportKey={ reportKey }
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
