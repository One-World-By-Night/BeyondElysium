/**
 * CharacterSheet renders a character's read-only trait sheet.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import BlockRenderer, { toTraits } from '../renderers/BlockRenderer';
import ChangeHistory from '../changes/ChangeHistory';
import { ConnectionManager } from '../apr/ConnectionManager';
import HelpButton from '../shared/HelpButton';
import DemoBanner from '../shared/DemoBanner';
import SheetWarnings from './SheetWarnings';
import SheetActions from './SheetActions';
import SheetHeader from './SheetHeader';
import TraitPanel from './TraitPanel';
import { compendiumHref } from '../../lib/compendiumLinks';
import {
	spanFor,
	sortedForFlow,
	isSectionCollapsed,
} from '../../lib/templateLayout';
import { resolveSectionTitle } from '../../lib/resolveCrossBlockRef';
import { isPrintCanvasPath } from '../../lib/pluginPages';
import { sectionHasContent } from '../../lib/sectionHasContent';
import { withHeldBlockSections } from '../../lib/heldBlockSections';
import { sheetStyleVars } from '../../lib/sheetStyleVars';
import { showsProseSection } from '../../lib/sheetProse';
import { highlightStMarkers } from '../../lib/highlightStMarkers';
import { stackableProseTables } from '../../lib/stackableProseTables';
import { canIn } from '../../lib/chronicleCapabilities';
import { sectionCount } from '../../lib/sectionTotal';
import { readCollapsed, setCollapsed } from '../../lib/panelCollapse';
import { alsoActiveAt, visitingFromSummary } from '../../lib/visitStatus';
import { heldTraits } from '../../lib/heldTraits';
import { traitLabel } from '../../lib/traitPanel';
import { useCharacterSheet } from '../../lib/useCharacterSheet';
import { useSheetPrintOptions } from '../../lib/useSheetPrintOptions';
import type { MyCapabilities, TraitListDefinition } from '../../types';
import './CharacterSheet.css';

/**
 * Sheet sections share the panel-collapse store with every other foldable panel.
 */
const SECTION_COLLAPSE_PREFIX = 'sheet-section:';

export interface CharacterSheetProps {
	characterId: number;
	gameSlug: string;
	templateType?: string;
	/**
	 * What the person can do in this chronicle, when the page resolved it.
	 */
	capabilities?: MyCapabilities;
	/**
	 * Opens another view of the Character section in place, from the trait panel.
	 */
	onView?: ( view: 'history' | 'edit' ) => void;
}

/**
 * Renders a character's sheet by resolving its creature stack and template.
 */
export function CharacterSheet( {
	characterId,
	gameSlug,
	templateType = 'sheet_full',
	capabilities,
	onView,
}: CharacterSheetProps ) {
	const { state, style, setStyle, pdfAvailability } = useCharacterSheet(
		characterId,
		gameSlug,
		templateType
	);

	// This same component also renders the dedicated print-canvas page, using these URL params.
	const urlParams = new URLSearchParams( window.location.search );
	const isPrintCanvas = isPrintCanvasPath( window.location.pathname );

	const print = useSheetPrintOptions( urlParams );
	const [ openTrait, setOpenTrait ] = useState< {
		blockSlug: string;
		name: string;
		powerName?: string;
	} | null >( null );
	const {
		printBackground,
		printNotes,
		printXpHistory,
		printFullPowerNames,
		printShowCost,
	} = print;

	// The viewer's own expand/collapse clicks, keyed by block_slug.
	const [ collapseOverrides, setCollapseOverrides ] = useState<
		Record< string, boolean >
	>( () => {
		const out: Record< string, boolean > = {};
		for ( const [ key, value ] of Object.entries( readCollapsed() ) ) {
			if ( key.startsWith( SECTION_COLLAPSE_PREFIX ) ) {
				out[ key.slice( SECTION_COLLAPSE_PREFIX.length ) ] = value;
			}
		}
		return out;
	} );

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

	const sections = sortedForFlow(
		withHeldBlockSections(
			resolved.template.layout.sections,
			stack,
			character.sheet_data
		)
	);
	const blockSlugs = sections.map( ( s ) => s.block_slug );

	/**
	 * Print-only 3-column float grouping for Physical/Social/Mental Traits, each column holding both trait halves.
	 */
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

			console.warn(
				`[BE] Template section references unknown block "${ section.block_slug }" for stack "${ stack.stack.slug }".`
			);
			return null;
		}

		if (
			! sectionHasContent(
				block.section_type,
				block.definition,
				character.sheet_data[ section.block_slug ]
			)
		) {
			return null;
		}

		const sectionGraphic =
			style.section_graphic_urls?.[ section.block_slug ];
		const collapsed = isSectionCollapsed( section, collapseOverrides );

		let title = resolveSectionTitle( section, character.sheet_data );
		// A non-atomic trait_list section whose held entries all carry a numeric count shows its total after the title.
		if (
			block.section_type === 'trait_list' &&
			! ( block.definition as TraitListDefinition ).atomic
		) {
			const total = sectionCount(
				toTraits( character.sheet_data[ section.block_slug ] ),
				( block.definition as TraitListDefinition ).count_is_cost
			);
			if ( total !== null ) {
				title = `${ title } \u{00B7} ${ total }`;
			}
		}

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
						onClick={ () => {
							setCollapsed(
								SECTION_COLLAPSE_PREFIX + section.block_slug,
								! collapsed
							);
							setCollapseOverrides( ( prev ) => ( {
								...prev,
								[ section.block_slug ]: ! collapsed,
							} ) );
						} }
					>
						<span
							className="be-character-sheet__section-toggle-icon"
							aria-hidden="true"
						>
							{ collapsed ? '▸' : '▾' }
						</span>
						{ title }
					</button>
				</h4>
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
						showCost={
							block.section_type === 'trait_list'
								? printShowCost
								: undefined
						}
						sheetData={ character.sheet_data }
						onTrait={
							isPrintCanvas
								? undefined
								: ( blockSlug, name, powerName ) =>
										setOpenTrait( {
											blockSlug,
											name,
											powerName,
										} )
						}
					/>
				</div>
			</div>
		);
	};

	const findSection = ( slug: string ) =>
		sections.find( ( s ) => s.block_slug === slug );
	let attributeGroupRendered = false;

	return (
		<div className="be-character-sheet" style={ sheetStyleVars( style ) }>
			{ /* This chrome never renders on the print-canvas page, which exists only to be printed automatically. */ }
			{ ! isPrintCanvas && (
				<>
					<DemoBanner gameSlug={ gameSlug } />
					<SheetActions
						character={ character }
						characterId={ characterId }
						gameSlug={ gameSlug }
						capabilities={ capabilities }
						blockSlugs={ blockSlugs }
						onStyleChange={ setStyle }
						print={ print }
						pdfAvailability={ pdfAvailability }
					/>
				</>
			) }

			{ ! isPrintCanvas &&
				character.visits &&
				character.visits.length > 0 && (
					<div
						className="be-character-sheet__chrome be-character-sheet__travelling-notice"
						role="status"
					>
						{ alsoActiveAt( character.visits ) + '.' }
					</div>
				) }

			{ ! isPrintCanvas && (
				<div className="be-character-sheet__chrome">
					<SheetWarnings warnings={ character.sheet_warnings } />
				</div>
			) }

			{ ! isPrintCanvas && character.visiting_from && (
				<div
					className="be-character-sheet__chrome be-character-sheet__travelling-notice"
					role="status"
				>
					{ visitingFromSummary( character.visiting_from ) }
				</div>
			) }

			{ /* Content, not chrome - a portrait always prints/exports, with no separate include-when-printing toggle. */ }
			<SheetHeader
				character={ character }
				typeName={ stack.stack.name }
			/>

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

			{ showsProseSection(
				character.biography,
				isPrintCanvas,
				printBackground
			) && (
				<div className="be-character-sheet__section be-character-sheet__prose">
					<h4 className="be-character-sheet__section-title">
						{ __( 'Background', 'beyond-elysium' ) }
					</h4>
					<div
						dangerouslySetInnerHTML={ {
							__html: highlightStMarkers(
								stackableProseTables(
									character.biography ?? ''
								)
							),
						} }
					/>
				</div>
			) }

			{ showsProseSection(
				character.notes,
				isPrintCanvas,
				printNotes
			) && (
				<div className="be-character-sheet__section be-character-sheet__prose">
					<h4 className="be-character-sheet__section-title">
						{ __( 'Notes', 'beyond-elysium' ) }
					</h4>
					<div
						dangerouslySetInnerHTML={ {
							__html: highlightStMarkers(
								stackableProseTables( character.notes ?? '' )
							),
						} }
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

			{ ! isPrintCanvas &&
				openTrait &&
				( () => {
					const held = heldTraits(
						sections.map( ( section ) => ( {
							blockSlug: section.block_slug,
							title: resolveSectionTitle(
								section,
								character.sheet_data
							),
							sectionType:
								stack.blocks[ section.block_slug ]
									?.section_type ?? '',
						} ) ),
						character.sheet_data
					).find(
						( trait ) =>
							trait.blockSlug === openTrait.blockSlug &&
							trait.name === openTrait.name &&
							( trait.powerName ?? undefined ) ===
								( openTrait.powerName ?? undefined )
					);
					return held ? (
						<TraitPanel
							gameSlug={ gameSlug }
							characterId={ characterId }
							trait={ held }
							compendiumHref={ compendiumHref(
								gameSlug,
								stack.stack.slug,
								held.blockSlug,
								held.name,
								characterId
							) }
							block={ stack.blocks[ held.blockSlug ] }
							sheetData={ character.sheet_data }
							label={ traitLabel(
								stack.blocks[ held.blockSlug ],
								held.name
							) }
							canEdit={ !! character.can_edit }
							canManage={ !! character.can_manage }
							onClose={ () => setOpenTrait( null ) }
							onView={
								onView
									? ( view ) => {
											setOpenTrait( null );
											onView( view );
										}
									: undefined
							}
						/>
					) : null;
				} )() }
		</div>
	);
}

export default CharacterSheet;
