/**
 * CharacterSheet renders a character's read-only trait sheet: it resolves the
 * creature stack and template, lays out each section's block through
 * BlockRenderer, and hosts the toolbar for printing, editing, appearance
 * customization, and connections. Also renders the dedicated print-canvas page.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { CSSProperties } from 'react';
import api from '../../api/client';
import BlockRenderer from '../renderers/BlockRenderer';
import SheetStyleEditor from './SheetStyleEditor';
import ChangeHistory from '../changes/ChangeHistory';
import { ConnectionManager } from '../apr/ConnectionManager';
import { BackgroundLedger } from '../apr/BackgroundLedger';
import { TransferPanel } from './TransferPanel';
import { spanFor, sortedForFlow } from '../../lib/templateLayout';
import { resolveSectionTitle } from '../../lib/resolveCrossBlockRef';
import type { ResolvedStack, TemplateResolveResponse } from '../../types';
import type { Character, SheetStyle } from '../../types/character';
import './CharacterSheet.css';

/**
 * Converts a character's sheet style into CSS custom properties for the sheet's
 * root element. Only keys the style actually sets are included, so an unset
 * field falls through to CharacterSheet.css's own defaults.
 */
function styleVars( style: SheetStyle ): CSSProperties {
	const vars: Record<string, string> = {};
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
		vars[ '--be-sheet-bg-image' ] = `url(${ JSON.stringify( style.background_image_url ) })`;
	}
	return vars as CSSProperties;
}

export interface CharacterSheetProps {
	characterId: number;
	gameSlug: string;
	templateType?: string;
}

/**
 * Builds the URL for the dedicated print-canvas page, carrying the character,
 * game, and "include when printing" choices as query parameters so the freshly
 * loaded print page can reconstruct them with no React state to inherit from.
 */
function buildPrintUrl(
	characterId: number,
	gameSlug: string,
	options: { background: boolean; notes: boolean; xpHistory: boolean; fullPowerNames: boolean }
): string {
	const params = new URLSearchParams( {
		character_id: String( characterId ),
		game_slug: gameSlug,
		print: '1',
	} );
	if ( options.background ) {
		params.set( 'print_background', '1' );
	}
	if ( options.notes ) {
		params.set( 'print_notes', '1' );
	}
	if ( options.xpHistory ) {
		params.set( 'print_xp_history', '1' );
	}
	if ( options.fullPowerNames ) {
		params.set( 'print_full_power_names', '1' );
	}
	return `${ window.location.origin }/character-sheet-print/?${ params.toString() }`;
}

interface RestError {
	code?: string;
	message?: string;
	data?: { status?: number };
}

type SheetState =
	| { status: 'loading' }
	| { status: 'error'; httpStatus: number | null; message: string }
	| { status: 'ready'; character: Character; stack: ResolvedStack; resolved: TemplateResolveResponse };

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
export function CharacterSheet( { characterId, gameSlug, templateType = 'sheet_full' }: CharacterSheetProps ) {
	const [ state, setState ] = useState<SheetState>( { status: 'loading' } );
	const [ style, setStyle ] = useState<SheetStyle>( NO_STYLE );
	const [ showStyleEditor, setShowStyleEditor ] = useState( false );
	const [ showHistory, setShowHistory ] = useState( false );
	const [ showLedger, setShowLedger ] = useState( false );
	const [ showTransfer, setShowTransfer ] = useState( false );
	const [ exportNotice, setExportNotice ] = useState<string | null>( null );
	const [ exporting, setExporting ] = useState( false );
	// Off by default - mints a fresh, real attestation row on every export, so it is not free to leave on.
	const [ includeVerification, setIncludeVerification ] = useState( false );
	const [ ledgerDate, setLedgerDate ] = useState( () => new Date().toISOString().slice( 0, 10 ) );

	// This same component also renders the dedicated print-canvas page, using these URL params.
	const urlParams = new URLSearchParams( window.location.search );
	const isPrintCanvas = window.location.pathname.includes( '/character-sheet-print' );

	// What to include when printing/exporting; off by default and shown only when asked for.
	const [ printBackground, setPrintBackground ] = useState( () => urlParams.get( 'print_background' ) === '1' );
	const [ printNotes, setPrintNotes ] = useState( () => urlParams.get( 'print_notes' ) === '1' );
	const [ printXpHistory, setPrintXpHistory ] = useState( () => urlParams.get( 'print_xp_history' ) === '1' );
	// Every tiered_power section switches from "Celerity 3" to listing each named rung up
	// to the held level (or the one specific power for an Elder-and-above pick) - nothing
	// else in the app currently ever sets `displayMode`, so this is the only source of
	// "named" mode today, on-screen or printed.
	const [ printFullPowerNames, setPrintFullPowerNames ] = useState( () => urlParams.get( 'print_full_power_names' ) === '1' );

	useEffect( () => {
		let cancelled = false;
		setState( { status: 'loading' } );

		( async () => {
			try {
				// Stack and template both depend on character.stack_slug, so those two calls run in parallel after it loads.
				const character = await api.characters( gameSlug ).get( characterId );
				// An NPC gets the NPC sheet, which adds the Storyteller-only sections.
				const resolvedType = character.is_npc ? 'npc_full' : templateType;
				const [ stack, resolved ] = await Promise.all( [
					api.creatureStacks.resolve( character.stack_slug, gameSlug ),
					api.templates( gameSlug ).resolve( character.stack_slug, resolvedType ),
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

				if ( ! cancelled ) {
					setState( { status: 'ready', character, stack, resolved } );
				}
			} catch ( error ) {
				if ( cancelled ) {
					return;
				}

				const httpStatus = isRestError( error ) ? error.data?.status ?? null : null;
				const message =
					httpStatus === 403
						? __( 'You do not have permission to view this character.', 'beyond-elysium' )
						: httpStatus === 404
						? __( 'No such character.', 'beyond-elysium' )
						: isRestError( error ) && error.message
						? error.message
						: __( 'Failed to load the character sheet.', 'beyond-elysium' );

				setState( { status: 'error', httpStatus, message } );
			}
		} )();

		return () => {
			cancelled = true;
		};
	}, [ characterId, gameSlug, templateType ] );

	// Auto-opens the browser's print dialog on the print-canvas page once real content has replaced the loading skeleton.
	useEffect( () => {
		if ( ! isPrintCanvas || urlParams.get( 'print' ) !== '1' || state.status !== 'ready' ) {
			return;
		}
		const timer = window.setTimeout( () => window.print(), 300 );
		return () => window.clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ isPrintCanvas, state.status ] );

	if ( state.status === 'loading' ) {
		return <div className="be-character-sheet__skeleton">{ __( 'Loading character sheet…', 'beyond-elysium' ) }</div>;
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
			const result = await api.characters( gameSlug ).export( characterId, {
				hide_st: ! character.can_manage,
				verify: includeVerification,
			} );
			const blob = new Blob( [ result.xml ], { type: 'application/xml' } );
			const url = URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = `${ character.name.replace( /[^a-z0-9]+/gi, '_' ) }.gex`;
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
			URL.revokeObjectURL( url );

			const notes = [ ...result.warnings, ...result.transliterations ];
			setExportNotice(
				notes.length > 0
					? sprintf( __( 'Exported with %d note(s) - see below.', 'beyond-elysium' ), notes.length ) + ' ' + notes.join( ' | ' )
					: __( 'Exported.', 'beyond-elysium' )
			);
		} catch ( error ) {
			setExportNotice( __( 'Export failed. Please try again.', 'beyond-elysium' ) );
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

	const renderSection = ( section: typeof sections[ number ] ) => {
		const block = stack.blocks[ section.block_slug ];
		if ( ! block ) {
			// Skips rendering for a block the stack no longer has, instead of blanking the whole sheet.
			// eslint-disable-next-line no-console
			console.warn(
				`[BE] Template section references unknown block "${ section.block_slug }" for stack "${ stack.stack.slug }".`
			);
			return null;
		}

		const sectionGraphic = style.section_graphic_urls?.[ section.block_slug ];

		return (
			<div
				className="be-character-sheet__section"
				key={ section.block_slug }
				style={ { gridColumn: `span ${ spanFor( section.width ) }` } }
			>
				{ sectionGraphic && (
					<img
						className="be-character-sheet__section-graphic"
						src={ sectionGraphic }
						alt=""
					/>
				) }
				<h4 className="be-character-sheet__section-title">{ resolveSectionTitle( section, character.sheet_data ) }</h4>
				<BlockRenderer
					blockSlug={ section.block_slug }
					sectionType={ block.section_type }
					definition={ block.definition }
					data={ character.sheet_data[ section.block_slug ] }
					display={ section.display }
					displayMode={ printFullPowerNames && block.section_type === 'tiered_power' ? 'named' : undefined }
					sheetData={ character.sheet_data }
				/>
			</div>
		);
	};

	const findSection = ( slug: string ) => sections.find( ( s ) => s.block_slug === slug );
	let attributeGroupRendered = false;

	return (
		<div className="be-character-sheet" style={ styleVars( style ) }>
			{ /* This toolbar chrome never renders on the print-canvas page, which exists only to be printed automatically. */ }
			{ ! isPrintCanvas && (
				<>
					{ /* Interactive-only UI, excluded from print; appearance customization is a collapsed toggle here. */ }
					<div className="be-character-sheet__chrome be-character-sheet__toolbar">
						<button
							type="button"
							className="be-character-sheet__print"
							onClick={ () =>
								window.open(
									buildPrintUrl( characterId, gameSlug, {
										background: printBackground,
										notes: printNotes,
										xpHistory: printXpHistory,
										fullPowerNames: printFullPowerNames,
									} ),
									'_blank'
								)
							}
						>
							{ __( 'Print / Export', 'beyond-elysium' ) }
						</button>
						{ character.can_edit && (
							<a
								className="be-character-sheet__edit-link"
								href={ `${ window.location.origin }/character-editor/?character_id=${ characterId }&game_slug=${ encodeURIComponent( gameSlug ) }` }
							>
								{ __( 'Edit this character', 'beyond-elysium' ) }
							</a>
						) }
						{ character.can_customize_sheet && (
							<button
								type="button"
								className="be-character-sheet__style-toggle"
								aria-expanded={ showStyleEditor }
								onClick={ () => setShowStyleEditor( ( v ) => ! v ) }
							>
								{ showStyleEditor
									? __( 'Hide appearance settings', 'beyond-elysium' )
									: __( 'Customize appearance', 'beyond-elysium' ) }
							</button>
						) }
						<button
							type="button"
							className="be-character-sheet__history-toggle"
							aria-expanded={ showHistory }
							onClick={ () => setShowHistory( ( v ) => ! v ) }
						>
							{ showHistory ? __( 'Hide history', 'beyond-elysium' ) : __( 'View history', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							className="be-character-sheet__history-toggle"
							aria-expanded={ showLedger }
							onClick={ () => setShowLedger( ( v ) => ! v ) }
						>
							{ showLedger ? __( 'Hide background uses', 'beyond-elysium' ) : __( 'Background uses', 'beyond-elysium' ) }
						</button>
						{ character.can_manage && (
							<button
								type="button"
								className="be-character-sheet__history-toggle"
								aria-expanded={ showTransfer }
								onClick={ () => setShowTransfer( ( v ) => ! v ) }
							>
								{ showTransfer ? __( 'Hide transfer', 'beyond-elysium' ) : __( 'Transfer', 'beyond-elysium' ) }
							</button>
						) }
						<label className="be-character-sheet__verify-toggle">
							<input
								type="checkbox"
								checked={ includeVerification }
								onChange={ ( e ) => setIncludeVerification( e.target.checked ) }
							/>
							{ __( 'Include verification code', 'beyond-elysium' ) }
						</label>
						<button
							type="button"
							className="be-character-sheet__gex-export"
							disabled={ exporting }
							onClick={ handleExport }
						>
							{ exporting ? __( 'Exporting…', 'beyond-elysium' ) : __( 'Export to Grapevine (.gex)', 'beyond-elysium' ) }
						</button>
					</div>
					{ exportNotice && (
						<div className="be-character-sheet__chrome be-character-sheet__export-notice" role="status">
							{ exportNotice }
						</div>
					) }

					{ /* Controls, not content - never part of the printed output themselves, only what they turn on is. */ }
					<div className="be-character-sheet__chrome be-character-sheet__print-options">
						<span>{ __( 'Include when printing:', 'beyond-elysium' ) }</span>
						<label>
							<input type="checkbox" checked={ printBackground } onChange={ ( e ) => setPrintBackground( e.target.checked ) } />
							{ __( 'Background', 'beyond-elysium' ) }
						</label>
						<label>
							<input type="checkbox" checked={ printNotes } onChange={ ( e ) => setPrintNotes( e.target.checked ) } />
							{ __( 'Notes', 'beyond-elysium' ) }
						</label>
						<label>
							<input type="checkbox" checked={ printXpHistory } onChange={ ( e ) => setPrintXpHistory( e.target.checked ) } />
							{ __( 'XP History', 'beyond-elysium' ) }
						</label>
						<label>
							<input type="checkbox" checked={ printFullPowerNames } onChange={ ( e ) => setPrintFullPowerNames( e.target.checked ) } />
							{ __( 'Full power names', 'beyond-elysium' ) }
						</label>
					</div>
				</>
			) }

			{ ! isPrintCanvas && character.can_customize_sheet && showStyleEditor && (
				<div className="be-character-sheet__chrome">
					<SheetStyleEditor
						characterId={ characterId }
						gameSlug={ gameSlug }
						blockSlugs={ blockSlugs }
						onChange={ setStyle }
					/>
				</div>
			) }

			{ /* On-screen history, independent of "include when printing" below - that toggle
			    only controls what the exported/printed sheet carries, not whether this view
			    can see it at all. 0.99.X-Ideas.md "Character audit trail" / "Player-facing XP history". */ }
			{ ! isPrintCanvas && showHistory && (
				<div className="be-character-sheet__chrome">
					<ChangeHistory characterId={ characterId } gameSlug={ gameSlug } />
				</div>
			) }

			{ /* Background_Ledger: what a background was used for and what it still has left to
			    spend. Ownership is enforced server-side (Apr_Controller); a non-owning, non-
			    managing viewer can never reach this component in the first place, since the
			    character fetch above already 403s for them (D33). */ }
			{ ! isPrintCanvas && showLedger && (
				<div className="be-character-sheet__chrome">
					<label className="be-character-sheet__ledger-date">
						{ __( 'Game date', 'beyond-elysium' ) }
						<input type="date" value={ ledgerDate } onChange={ ( e ) => setLedgerDate( e.target.value ) } />
					</label>
					<BackgroundLedger
						gameSlug={ gameSlug }
						characterId={ characterId }
						gameDate={ ledgerDate }
						canManage={ window.beyondElysium?.capabilities?.be_manage_characters ?? false }
					/>
				</div>
			) }

			{ ! isPrintCanvas && showTransfer && character.can_manage && (
				<div className="be-character-sheet__chrome be-character-sheet__section">
					<h4 className="be-character-sheet__section-title">{ __( 'Chronicle Transfer', 'beyond-elysium' ) }</h4>
					<TransferPanel
						gameSlug={ gameSlug }
						characterId={ characterId }
						characterUuid={ character.uuid }
						travellingStatus={ character.travelling_status ?? null }
					/>
				</div>
			) }

			{ /* §8.4: a travelling/visiting notice, always shown regardless of the Transfer panel's own
			    toggle state - a manager editing this sheet needs to see this without an extra click,
			    excluded from print the same way every other .be-character-sheet__chrome element is. */ }
			{ ! isPrintCanvas && character.travelling_status && (
				<div className="be-character-sheet__chrome be-character-sheet__travelling-notice" role="status">
					{ character.travelling_status.direction === 'outbound'
						? sprintf(
								/* translators: 1: the other chronicle's name, 2: the date the transfer started */
								__( 'Travelling — %1$s since %2$s. Edits are discouraged while this character is away.', 'beyond-elysium' ),
								character.travelling_status.chronicle ?? __( 'no host confirmed yet', 'beyond-elysium' ),
								character.travelling_status.since
						  )
						: sprintf(
								/* translators: 1: the home chronicle's name, 2: the date the transfer started */
								__( 'Visiting from %1$s since %2$s.', 'beyond-elysium' ),
								character.travelling_status.chronicle ?? __( 'no host confirmed yet', 'beyond-elysium' ),
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
						alt={ sprintf( __( '%s portrait', 'beyond-elysium' ), character.name ) }
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
					if ( ATTRIBUTE_BLOCK_SLUGS.includes( section.block_slug ) ) {
						if ( attributeGroupRendered ) {
							return null; // Already rendered as part of the group below.
						}
						attributeGroupRendered = true;
						return (
							<div className="be-character-sheet__attributes" key="be-attributes-group">
								{ ATTRIBUTE_GROUPS.map( ( slugs, i ) => (
									<div className="be-character-sheet__attribute-column" key={ `be-attr-col-${ i }` }>
										{ slugs.map( ( slug ) => {
											const s = findSection( slug );
											return s ? renderSection( s ) : null;
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
			{ ! isPrintCanvas && window.beyondElysium?.capabilities?.be_manage_connections && (
				<div className="be-character-sheet__chrome be-character-sheet__section">
					<h4 className="be-character-sheet__section-title">{ __( 'Connections', 'beyond-elysium' ) }</h4>
					<ConnectionManager gameSlug={ gameSlug } entityType="character" entityId={ characterId } />
				</div>
			) }

			{ /* Real content, included in print per its own checkbox; biography/notes HTML is already sanitized server-side. */ }
			{ printBackground && character.biography && (
				<div className="be-character-sheet__section be-character-sheet__prose">
					<h4 className="be-character-sheet__section-title">{ __( 'Background', 'beyond-elysium' ) }</h4>
					<div dangerouslySetInnerHTML={ { __html: character.biography } } />
				</div>
			) }

			{ printNotes && character.notes && (
				<div className="be-character-sheet__section be-character-sheet__prose">
					<h4 className="be-character-sheet__section-title">{ __( 'Notes', 'beyond-elysium' ) }</h4>
					<div dangerouslySetInnerHTML={ { __html: character.notes } } />
				</div>
			) }

			{ printXpHistory && (
				<div className="be-character-sheet__section">
					<h4 className="be-character-sheet__section-title">{ __( 'XP History', 'beyond-elysium' ) }</h4>
					<ChangeHistory characterId={ characterId } gameSlug={ gameSlug } />
				</div>
			) }
		</div>
	);
}

export default CharacterSheet;
