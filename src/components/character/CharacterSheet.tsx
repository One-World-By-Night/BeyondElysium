/**
 * CharacterSheet renders a character's read-only trait sheet: it resolves the
 * creature stack and template, lays out each section's block through
 * BlockRenderer, and hosts the toolbar for printing, editing, appearance
 * customization, and connections. Also renders the dedicated print-canvas page.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { CSSProperties } from 'react';
import api from '../../api/client';
import BlockRenderer from '../renderers/BlockRenderer';
import SheetStyleEditor from './SheetStyleEditor';
import ChangeHistory from '../changes/ChangeHistory';
import PointAudit from './PointAudit';
import { ConnectionManager } from '../apr/ConnectionManager';
import { BackgroundLedger } from '../apr/BackgroundLedger';
import { TransferPanel } from './TransferPanel';
import HelpButton from '../shared/HelpButton';
import {
	spanFor,
	sortedForFlow,
	isSectionCollapsed,
} from '../../lib/templateLayout';
import { resolveSectionTitle } from '../../lib/resolveCrossBlockRef';
import { characterEditorUrl, isPrintCanvasPath } from '../../lib/pluginPages';
import { canIn } from '../../lib/chronicleCapabilities';
import { sheetActions, type SheetAction } from '../../lib/sheetActions';
import type {
	MyCapabilities,
	ResolvedStack,
	TemplateResolveResponse,
} from '../../types';
import type { Character, SheetStyle } from '../../types/character';
import './CharacterSheet.css';

/**
 * Converts a character's sheet style into CSS custom properties for the sheet's
 * root element. Only keys the style actually sets are included, so an unset
 * field falls through to CharacterSheet.css's own defaults.
 */
function styleVars( style: SheetStyle ): CSSProperties {
	const vars: Record< string, string > = {};
	if ( style.font_family ) {
		vars[ '--be-sheet-font' ] = style.font_family;
	}
	if ( style.accent_color ) {
		vars[ '--be-sheet-accent' ] = style.accent_color;
	}
	if ( style.background_color ) {
		vars[ '--be-sheet-bg' ] = style.background_color;
	}
	if ( style.text_color ) {
		vars[ '--be-sheet-text' ] = style.text_color;
	}
	if ( style.background_image_url ) {
		vars[ '--be-sheet-bg-image' ] = `url(${ JSON.stringify(
			style.background_image_url
		) })`;
	}
	return vars as CSSProperties;
}

export interface CharacterSheetProps {
	characterId: number;
	gameSlug: string;
	templateType?: string;
	/** What the person can do in this chronicle, when the page resolved it; the site-wide snapshot otherwise (F-103). */
	capabilities?: MyCapabilities;
}

interface RestError {
	code?: string;
	message?: string;
	data?: { status?: number };
}

type SheetState =
	| { status: 'loading' }
	| { status: 'error'; httpStatus: number | null; message: string }
	| {
			status: 'ready';
			character: Character;
			stack: ResolvedStack;
			resolved: TemplateResolveResponse;
	  };

/** A style-load failure never blocks the sheet itself - it just renders unstyled. */
const NO_STYLE: SheetStyle = {};

function isRestError( error: unknown ): error is RestError {
	return typeof error === 'object' && error !== null;
}

/**
 * Renders a character's sheet by resolving its creature stack and template,
 * then walking the resolved layout's sections in (column, order) and handing
 * each section's block definition and character data to BlockRenderer.
 */
export function CharacterSheet( {
	characterId,
	gameSlug,
	templateType = 'sheet_full',
	capabilities,
}: CharacterSheetProps ) {
	const [ state, setState ] = useState< SheetState >( { status: 'loading' } );
	const [ style, setStyle ] = useState< SheetStyle >( NO_STYLE );
	// null while the preflight hasn't resolved yet. Printing works either way; a chronicle with
	// no signing certificate prints copies stamped UNSIGNED, and the toolbar says so.
	const [ pdfAvailability, setPdfAvailability ] = useState< {
		ok: boolean;
		code: string;
	} | null >( null );
	// One picker for everything the sheet can do (owner, 2026-09-15): what's picked, and which
	// action's panel is open below it.
	const [ chosenAction, setChosenAction ] = useState< SheetAction | '' >(
		''
	);
	const [ openPanel, setOpenPanel ] = useState< SheetAction | '' >( '' );
	const panelRef = useRef< HTMLDivElement >( null );
	const [ exportNotice, setExportNotice ] = useState< string | null >( null );
	const [ exporting, setExporting ] = useState( false );
	// Off by default - mints a fresh, real attestation row on every export, so it is not free to leave on.
	const [ includeVerification, setIncludeVerification ] = useState( false );
	const [ ledgerDate, setLedgerDate ] = useState( () =>
		new Date().toISOString().slice( 0, 10 )
	);

	// A keyboard user lands on the panel they asked for.
	useEffect( () => {
		if ( openPanel ) {
			panelRef.current?.focus();
		}
	}, [ openPanel ] );

	// This same component also renders the dedicated print-canvas page, using these URL params.
	const urlParams = new URLSearchParams( window.location.search );
	const isPrintCanvas = isPrintCanvasPath( window.location.pathname );

	// What to include when printing/exporting; off by default and shown only when asked for.
	const [ printBackground, setPrintBackground ] = useState(
		() => urlParams.get( 'print_background' ) === '1'
	);
	const [ printNotes, setPrintNotes ] = useState(
		() => urlParams.get( 'print_notes' ) === '1'
	);
	const [ printXpHistory, setPrintXpHistory ] = useState(
		() => urlParams.get( 'print_xp_history' ) === '1'
	);
	// Every tiered_power section switches from "Celerity 3" to listing each named rung up
	// to the held level (or the one specific power for an Elder-and-above pick) - nothing
	// else in the app currently ever sets `displayMode`, so this is the only source of
	// "named" mode today, on-screen or printed.
	const [ printFullPowerNames, setPrintFullPowerNames ] = useState(
		() => urlParams.get( 'print_full_power_names' ) === '1'
	);

	// The viewer's own expand/collapse clicks, keyed by block_slug - only ever holds an
	// entry once they've clicked a section, so a section they never touched still reads
	// straight from the template's own `collapsed` flag (isSectionCollapsed()).
	const [ collapseOverrides, setCollapseOverrides ] = useState<
		Record< string, boolean >
	>( {} );

	useEffect( () => {
		let cancelled = false;
		setState( { status: 'loading' } );

		( async () => {
			try {
				// Stack and template both depend on character.stack_slug, so those two calls run in parallel after it loads.
				const character = await api
					.characters( gameSlug )
					.get( characterId );
				// An NPC gets the NPC sheet, which adds the Storyteller-only sections.
				const resolvedType = character.is_npc
					? 'npc_full'
					: templateType;
				const [ stack, resolved ] = await Promise.all( [
					api.creatureStacks.resolve(
						character.stack_slug,
						gameSlug
					),
					api
						.templates( gameSlug )
						.resolve( character.stack_slug, resolvedType ),
				] );

				// Best-effort: a style-load failure never blocks the sheet, it just renders unstyled.
				api.sheetStyle( gameSlug )
					.get( characterId )
					.then( ( loaded ) => {
						if ( ! cancelled ) {
							setStyle( loaded );
						}
					} )
					.catch( () => undefined );

				// Best-effort preflight: an availability-check failure leaves pdfAvailability
				// null, so the unsigned note never shows over a transient network blip.
				api.sheets( gameSlug )
					.availability()
					.then( ( loaded ) => {
						if ( ! cancelled ) {
							setPdfAvailability( loaded );
						}
					} )
					.catch( () => undefined );

				if ( ! cancelled ) {
					setState( { status: 'ready', character, stack, resolved } );
				}
			} catch ( error ) {
				if ( cancelled ) {
					return;
				}

				const httpStatus = isRestError( error )
					? error.data?.status ?? null
					: null;
				const message =
					httpStatus === 403
						? __(
								'You do not have permission to view this character.',
								'beyond-elysium'
						  )
						: httpStatus === 404
						? __( 'No such character.', 'beyond-elysium' )
						: isRestError( error ) && error.message
						? error.message
						: __(
								'Failed to load the character sheet.',
								'beyond-elysium'
						  );

				setState( { status: 'error', httpStatus, message } );
			}
		} )();

		return () => {
			cancelled = true;
		};
	}, [ characterId, gameSlug, templateType ] );

	// Auto-opens the browser's print dialog on the print-canvas page once real content has replaced the loading skeleton.
	useEffect( () => {
		if (
			! isPrintCanvas ||
			urlParams.get( 'print' ) !== '1' ||
			state.status !== 'ready'
		) {
			return;
		}
		const timer = window.setTimeout( () => window.print(), 300 );
		return () => window.clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ isPrintCanvas, state.status ] );

	if ( state.status === 'loading' ) {
		return (
			<div className="be-character-sheet__skeleton">
				{ __( 'Loading character sheet…', 'beyond-elysium' ) }
			</div>
		);
	}

	if ( state.status === 'error' ) {
		return (
			<div className="be-character-sheet__error" role="alert">
				{ state.message }
			</div>
		);
	}

	const { character, stack, resolved } = state;

	/**
	 * Exports this character to a Grapevine `.gex` file and offers it as a
	 * browser download. `hide_st` mirrors what a non-manager already sees
	 * elsewhere on this sheet - a manager gets the full record, a player
	 * gets their own character with ST-only text stripped the same way.
	 */
	const handleExport = async () => {
		setExporting( true );
		setExportNotice( null );
		try {
			const result = await api
				.characters( gameSlug )
				.export( characterId, {
					hide_st: ! character.can_manage,
					verify: includeVerification,
				} );
			const blob = new Blob( [ result.xml ], {
				type: 'application/xml',
			} );
			const url = URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = `${ character.name.replace(
				/[^a-z0-9]+/gi,
				'_'
			) }.gex`;
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
			URL.revokeObjectURL( url );

			const notes = [ ...result.warnings, ...result.transliterations ];
			setExportNotice(
				notes.length > 0
					? sprintf(
							/* translators: %d: number of warnings or transliteration notes from the export */
							__(
								'Exported with %d note(s) - see below.',
								'beyond-elysium'
							),
							notes.length
					  ) +
							' ' +
							notes.join( ' | ' )
					: __( 'Exported.', 'beyond-elysium' )
			);
		} catch ( error ) {
			setExportNotice(
				__( 'Export failed. Please try again.', 'beyond-elysium' )
			);
		} finally {
			setExporting( false );
		}
	};

	// Sections flow in (column, order) reading order into a repeat(6, 1fr) CSS Grid, each spanning 2/6, 3/6, or 6/6 per its own width.
	const sections = sortedForFlow( resolved.template.layout.sections );
	const blockSlugs = sections.map( ( s ) => s.block_slug );

	/** Print-only 3-column float grouping for Physical/Social/Mental Traits, each column holding both trait halves. */
	const ATTRIBUTE_GROUPS: string[][] = [
		[ 'met-physical-traits', 'met-physical-traits-neg' ],
		[ 'met-social-traits', 'met-social-traits-neg' ],
		[ 'met-mental-traits', 'met-mental-traits-neg' ],
	];
	const ATTRIBUTE_BLOCK_SLUGS = ATTRIBUTE_GROUPS.flat();

	const renderSection = ( section: ( typeof sections )[ number ] ) => {
		const block = stack.blocks[ section.block_slug ];
		if ( ! block ) {
			// Skips rendering for a block the stack no longer has, instead of blanking the whole sheet.
			// eslint-disable-next-line no-console
			console.warn(
				`[BE] Template section references unknown block "${ section.block_slug }" for stack "${ stack.stack.slug }".`
			);
			return null;
		}

		const sectionGraphic =
			style.section_graphic_urls?.[ section.block_slug ];
		const collapsed = isSectionCollapsed( section, collapseOverrides );

		return (
			<div
				className="be-character-sheet__section"
				key={ section.block_slug }
				// `order` keeps the template's place when the print grouping below wraps a section.
				style={ {
					gridColumn: `span ${ spanFor( section.width ) }`,
					order: sections.indexOf( section ),
				} }
			>
				<h4 className="be-character-sheet__section-title">
					<button
						type="button"
						className="be-character-sheet__section-toggle"
						aria-expanded={ ! collapsed }
						onClick={ () =>
							setCollapseOverrides( ( prev ) => ( {
								...prev,
								[ section.block_slug ]: ! collapsed,
							} ) )
						}
					>
						<span
							className="be-character-sheet__section-toggle-icon"
							aria-hidden="true"
						>
							{ collapsed ? '▸' : '▾' }
						</span>
						{ resolveSectionTitle( section, character.sheet_data ) }
					</button>
				</h4>
				{ /* Never unmounted - hidden keeps it out of view (and, on screen, out of layout)
				 * without losing BlockRenderer's own state; @media print always shows it
				 * regardless, so a collapsed section still prints in full. */ }
				<div hidden={ collapsed }>
					{ sectionGraphic && (
						<img
							className="be-character-sheet__section-graphic"
							src={ sectionGraphic }
							alt=""
						/>
					) }
					<BlockRenderer
						blockSlug={ section.block_slug }
						sectionType={ block.section_type }
						definition={ block.definition }
						data={ character.sheet_data[ section.block_slug ] }
						display={ section.display }
						displayMode={
							printFullPowerNames &&
							block.section_type === 'tiered_power'
								? 'named'
								: undefined
						}
						sheetData={ character.sheet_data }
					/>
				</div>
			</div>
		);
	};

	const findSection = ( slug: string ) =>
		sections.find( ( s ) => s.block_slug === slug );
	let attributeGroupRendered = false;

	const actionLabels: Record< SheetAction, string > = {
		print: __( 'Print / Export', 'beyond-elysium' ),
		items: __( 'Print My Items', 'beyond-elysium' ),
		edit: __( 'Edit this character', 'beyond-elysium' ),
		appearance: __( 'Customize appearance', 'beyond-elysium' ),
		history: __( 'View history', 'beyond-elysium' ),
		ledger: __( 'Background uses', 'beyond-elysium' ),
		send: __( 'Send Sheet', 'beyond-elysium' ),
		audit: __( 'Point audit', 'beyond-elysium' ),
		gex: __( 'Export to Grapevine (.gex)', 'beyond-elysium' ),
	};

	/**
	 * Runs the picked action: Print My Items opens its PDF and Edit this character opens the
	 * editor; everything else opens its panel below the picker, in place of any panel already
	 * open.
	 */
	function runAction() {
		if ( chosenAction === 'items' ) {
			window.open(
				api.reports( gameSlug ).pdfUrl( 'item-cards', { characterId } ),
				'_blank'
			);
			return;
		}
		if ( chosenAction === 'edit' ) {
			window.location.href = characterEditorUrl( characterId, gameSlug );
			return;
		}
		setOpenPanel( chosenAction );
	}

	return (
		<div className="be-character-sheet" style={ styleVars( style ) }>
			{ /* This chrome never renders on the print-canvas page, which exists only to be printed automatically. */ }
			{ ! isPrintCanvas && (
				<>
					<div className="be-character-sheet__chrome be-character-sheet__toolbar">
						<select
							className="be-character-sheet__action-select"
							aria-label={ __(
								'Sheet actions',
								'beyond-elysium'
							) }
							value={ chosenAction }
							onChange={ ( e ) =>
								setChosenAction(
									e.target.value as SheetAction | ''
								)
							}
						>
							<option value="">
								{ __( 'Choose an action…', 'beyond-elysium' ) }
							</option>
							{ sheetActions( character ).map( ( action ) => (
								<option key={ action } value={ action }>
									{ actionLabels[ action ] }
								</option>
							) ) }
						</select>
						<button
							type="button"
							className="be-character-sheet__action-go"
							disabled={ ! chosenAction }
							onClick={ runAction }
						>
							{ __( 'Go', 'beyond-elysium' ) }
						</button>
						<HelpButton helpKey="character-sheet" />
					</div>
					{ exportNotice && (
						<div
							className="be-character-sheet__chrome be-character-sheet__export-notice"
							role="status"
						>
							{ exportNotice }
						</div>
					) }

					{ openPanel && (
						<div
							className="be-character-sheet__chrome be-character-sheet__action-panel"
							ref={ panelRef }
							tabIndex={ -1 }
							aria-label={ actionLabels[ openPanel ] }
						>
							<div className="be-character-sheet__action-panel-header">
								<h4 className="be-character-sheet__section-title">
									{ actionLabels[ openPanel ] }
								</h4>
								{ /* One help doc per open action - not every action has (or needs)
								 * one (items/edit/appearance never set openPanel at all), and
								 * print/gex share sheet-print-export's single doc. Written as
								 * literal per-action helpKey props, not a lookup object, so
								 * helpDocs.test.ts's static scan can see each one. */ }
								{ ( openPanel === 'print' ||
									openPanel === 'gex' ) && (
									<HelpButton helpKey="sheet-print-export" />
								) }
								{ openPanel === 'history' && (
									<HelpButton helpKey="sheet-history" />
								) }
								{ openPanel === 'audit' && (
									<HelpButton helpKey="point-audit" />
								) }
								{ openPanel === 'ledger' && (
									<HelpButton helpKey="background-uses" />
								) }
								{ openPanel === 'send' && (
									<HelpButton helpKey="transfer" />
								) }
								<button
									type="button"
									className="be-character-sheet__action-close"
									onClick={ () => setOpenPanel( '' ) }
								>
									{ __( 'Close', 'beyond-elysium' ) }
								</button>
							</div>

							{ /* What prints is chosen here; ticking one also shows it on this page. */ }
							{ openPanel === 'print' && (
								<>
									<div className="be-character-sheet__print-options">
										<span>
											{ __(
												'Include when printing:',
												'beyond-elysium'
											) }
										</span>
										<label>
											<input
												type="checkbox"
												checked={ printBackground }
												onChange={ ( e ) =>
													setPrintBackground(
														e.target.checked
													)
												}
											/>
											{ __(
												'Background',
												'beyond-elysium'
											) }
										</label>
										<label>
											<input
												type="checkbox"
												checked={ printNotes }
												onChange={ ( e ) =>
													setPrintNotes(
														e.target.checked
													)
												}
											/>
											{ __( 'Notes', 'beyond-elysium' ) }
										</label>
										<label>
											<input
												type="checkbox"
												checked={ printXpHistory }
												onChange={ ( e ) =>
													setPrintXpHistory(
														e.target.checked
													)
												}
											/>
											{ __(
												'XP History',
												'beyond-elysium'
											) }
										</label>
										<label>
											<input
												type="checkbox"
												checked={ printFullPowerNames }
												onChange={ ( e ) =>
													setPrintFullPowerNames(
														e.target.checked
													)
												}
											/>
											{ __(
												'Full power names',
												'beyond-elysium'
											) }
										</label>
									</div>
									{ pdfAvailability !== null &&
										! pdfAvailability.ok && (
											<p
												className="be-character-sheet__print-unsigned"
												role="status"
											>
												{ pdfAvailability.code ===
												'secure_printing_off'
													? __(
															'Secure printing is switched off for this site, so prints are marked UNSIGNED.',
															'beyond-elysium'
													  )
													: __(
															'This site has no signing certificate yet, so prints are marked UNSIGNED.',
															'beyond-elysium'
													  ) }
											</p>
										) }
									<button
										type="button"
										className="be-character-sheet__print"
										onClick={ () =>
											window.open(
												api
													.sheets( gameSlug )
													.pdfUrl( [ characterId ], {
														background:
															printBackground,
														notes: printNotes,
														xpHistory:
															printXpHistory,
														fullPowerNames:
															printFullPowerNames,
													} ),
												'_blank'
											)
										}
									>
										{ __( 'Open PDF', 'beyond-elysium' ) }
									</button>
								</>
							) }

							{ openPanel === 'gex' && (
								<div className="be-character-sheet__gex-options">
									<label className="be-character-sheet__verify-toggle">
										<input
											type="checkbox"
											checked={ includeVerification }
											onChange={ ( e ) =>
												setIncludeVerification(
													e.target.checked
												)
											}
										/>
										{ __(
											'Include verification code',
											'beyond-elysium'
										) }
									</label>
									<button
										type="button"
										className="be-character-sheet__gex-export"
										disabled={ exporting }
										onClick={ handleExport }
									>
										{ exporting
											? __(
													'Exporting…',
													'beyond-elysium'
											  )
											: __(
													'Download .gex file',
													'beyond-elysium'
											  ) }
									</button>
								</div>
							) }

							{ openPanel === 'appearance' &&
								character.can_customize_sheet && (
									<SheetStyleEditor
										characterId={ characterId }
										gameSlug={ gameSlug }
										blockSlugs={ blockSlugs }
										onChange={ setStyle }
									/>
								) }

							{ /* On-screen history, independent of "include when printing" - that only
							    controls what the printed sheet carries, not whether this view can see it. */ }
							{ openPanel === 'history' && (
								<ChangeHistory
									characterId={ characterId }
									gameSlug={ gameSlug }
								/>
							) }

							{ /* be_manage_characters-gated server-side (point-calculator-design.md §5.5);
							    the picker only offers it to a manager, but the route is the real control. */ }
							{ openPanel === 'audit' && character.can_manage && (
								<PointAudit
									characterId={ characterId }
									gameSlug={ gameSlug }
								/>
							) }

							{ /* Ownership is enforced server-side (Apr_Controller); a non-owning,
							    non-managing viewer never reaches this sheet at all (D33). */ }
							{ openPanel === 'ledger' && (
								<>
									<label className="be-character-sheet__ledger-date">
										{ __( 'Game date', 'beyond-elysium' ) }
										<input
											type="date"
											value={ ledgerDate }
											onChange={ ( e ) =>
												setLedgerDate( e.target.value )
											}
										/>
									</label>
									<BackgroundLedger
										gameSlug={ gameSlug }
										characterId={ characterId }
										gameDate={ ledgerDate }
										canManage={ canIn(
											'be_manage_characters',
											capabilities
										) }
									/>
								</>
							) }

							{ openPanel === 'send' && character.can_manage && (
								<TransferPanel
									gameSlug={ gameSlug }
									characterId={ characterId }
									characterUuid={ character.uuid }
									travellingStatus={
										character.travelling_status ?? null
									}
								/>
							) }
						</div>
					) }
				</>
			) }

			{ /* §8.4: a travelling/visiting notice, always shown regardless of the Transfer panel's own
			    toggle state - a manager editing this sheet needs to see this without an extra click,
			    excluded from print the same way every other .be-character-sheet__chrome element is. */ }
			{ ! isPrintCanvas && character.travelling_status && (
				<div
					className="be-character-sheet__chrome be-character-sheet__travelling-notice"
					role="status"
				>
					{ character.travelling_status.direction === 'outbound'
						? sprintf(
								/* translators: 1: the other chronicle's name, 2: the date the transfer started */
								__(
									'Travelling — %1$s since %2$s. Edits are discouraged while this character is away.',
									'beyond-elysium'
								),
								character.travelling_status.chronicle ??
									__(
										'no host confirmed yet',
										'beyond-elysium'
									),
								character.travelling_status.since
						  )
						: sprintf(
								/* translators: 1: the home chronicle's name, 2: the date the transfer started */
								__(
									'Visiting from %1$s since %2$s.',
									'beyond-elysium'
								),
								character.travelling_status.chronicle ??
									__(
										'no host confirmed yet',
										'beyond-elysium'
									),
								character.travelling_status.since
						  ) }
				</div>
			) }

			{ /* Content, not chrome - a portrait always prints/exports, with no separate include-when-printing toggle. */ }
			<div className="be-character-sheet__header-row">
				{ character.image_url && (
					<img
						className="be-character-sheet__portrait"
						src={ character.image_url }
						alt={ sprintf(
							/* translators: %s: the character's own name */
							__( '%s portrait', 'beyond-elysium' ),
							character.name
						) }
					/>
				) }
				<dl className="be-character-sheet__header">
					<div>
						<dt>{ __( 'Name', 'beyond-elysium' ) }</dt>
						<dd>{ character.name }</dd>
					</div>
					<div>
						<dt>{ __( 'Type', 'beyond-elysium' ) }</dt>
						<dd>{ stack.stack.name }</dd>
					</div>
					<div>
						<dt>{ __( 'Status', 'beyond-elysium' ) }</dt>
						<dd>{ character.status }</dd>
					</div>
					<div>
						<dt>{ __( 'Player', 'beyond-elysium' ) }</dt>
						<dd>{ character.player_name ?? '—' }</dd>
					</div>
					<div>
						<dt>{ __( 'XP Earned', 'beyond-elysium' ) }</dt>
						<dd>{ character.xp_earned }</dd>
					</div>
					<div>
						<dt>{ __( 'XP Unspent', 'beyond-elysium' ) }</dt>
						<dd>{ character.xp_unspent }</dd>
					</div>
				</dl>
			</div>

			<div className="be-character-sheet__grid">
				{ sections.map( ( section ) => {
					if (
						ATTRIBUTE_BLOCK_SLUGS.includes( section.block_slug )
					) {
						if ( attributeGroupRendered ) {
							return null; // Already rendered as part of the group below.
						}
						attributeGroupRendered = true;
						return (
							<div
								className="be-character-sheet__attributes"
								key="be-attributes-group"
							>
								{ ATTRIBUTE_GROUPS.map( ( slugs, i ) => (
									<div
										className="be-character-sheet__attribute-column"
										key={ `be-attr-col-${ i }` }
									>
										{ slugs.map( ( slug ) => {
											const s = findSection( slug );
											return s
												? renderSection( s )
												: null;
										} ) }
									</div>
								) ) }
							</div>
						);
					}
					return renderSection( section );
				} ) }
			</div>

			{ /* Lets a manager link this character to plots or other characters; never shown on the print canvas. */ }
			{ ! isPrintCanvas &&
				canIn( 'be_manage_connections', capabilities ) && (
					<div className="be-character-sheet__chrome be-character-sheet__section">
						<div className="be-help-heading">
							<h4 className="be-character-sheet__section-title">
								{ __( 'Connections', 'beyond-elysium' ) }
							</h4>
							<HelpButton helpKey="connections" />
						</div>
						<ConnectionManager
							gameSlug={ gameSlug }
							entityType="character"
							entityId={ characterId }
						/>
					</div>
				) }

			{ /* Real content, included in print per its own checkbox; biography/notes HTML is already sanitized server-side. */ }
			{ printBackground && character.biography && (
				<div className="be-character-sheet__section be-character-sheet__prose">
					<h4 className="be-character-sheet__section-title">
						{ __( 'Background', 'beyond-elysium' ) }
					</h4>
					<div
						dangerouslySetInnerHTML={ {
							__html: character.biography,
						} }
					/>
				</div>
			) }

			{ printNotes && character.notes && (
				<div className="be-character-sheet__section be-character-sheet__prose">
					<h4 className="be-character-sheet__section-title">
						{ __( 'Notes', 'beyond-elysium' ) }
					</h4>
					<div
						dangerouslySetInnerHTML={ { __html: character.notes } }
					/>
				</div>
			) }

			{ printXpHistory && (
				<div className="be-character-sheet__section">
					<h4 className="be-character-sheet__section-title">
						{ __( 'XP History', 'beyond-elysium' ) }
					</h4>
					<ChangeHistory
						characterId={ characterId }
						gameSlug={ gameSlug }
					/>
				</div>
			) }
		</div>
	);
}

export default CharacterSheet;
