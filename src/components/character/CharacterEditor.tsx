/**
 * CharacterEditor is the character creation and editing screen.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import useCharacterEditorStore from '../../store/characterEditorStore';
import BlockEditor from '../editors/BlockEditor';
import CreationTallyPanel from './CreationTallyPanel';
import ConfirmDialog from '../shared/ConfirmDialog';
import HelpButton from '../shared/HelpButton';
import AssigneePicker from '../shared/AssigneePicker';
import DemoBanner from '../shared/DemoBanner';
import SecretsPanel from '../shared/SecretsPanel';
import NpcHooksPanel from './NpcHooksPanel';
import NpcRoleplayingDraftButton from './NpcRoleplayingDraftButton';
import CollapsiblePanel from '../shared/CollapsiblePanel';
import { previewPriceLabel } from '../../lib/queuePrice';
import { describeChange } from '../../lib/describeChange';
import { spanFor, sortedForFlow } from '../../lib/templateLayout';
import {
	allowsAnyBlock,
	withHeldBlockSections,
} from '../../lib/heldBlockSections';
import AddSectionPicker from './AddSectionPicker';
import SheetWarnings from './SheetWarnings';
import { resolveSectionTitle } from '../../lib/resolveCrossBlockRef';
import { characterSheetUrl } from '../../lib/pluginPages';
import { canIn } from '../../lib/chronicleCapabilities';
import type {
	CreatureStack,
	MyCapabilities,
	ResolvedStack,
	SchemaBlock,
	TemplateLayoutSection,
	TemplateResolveResponse,
	IdentityFieldDefinition,
	TraitListDefinition,
	TieredPowerDefinition,
} from '../../types';
import type { SubmitResult } from '../../store/characterEditorStore';
import './CharacterEditor.css';

export interface CharacterEditorProps {
	/**
	 * Present = edit mode.
	 */
	characterId?: number;
	gameSlug: string;
	/**
	 * Create mode only.
	 */
	stackSlug?: string;
	templateType?: string;
	/**
	 * What the person can do in this chronicle, when the page resolved it.
	 */
	capabilities?: MyCapabilities;
	/**
	 * Opens a new character with the NPC box already ticked, for a person allowed to flag NPCs.
	 */
	startAsNpc?: boolean;
}

import { errorMessage } from '../../lib/errorMessage';
import { approvalLevelLabel } from '../../lib/characterLabels';
import { pendingLine } from '../../lib/pendingLine';

function sortedSections(
	resolved: TemplateResolveResponse | null,
	stack: ResolvedStack | null,
	sheetData: Record< string, unknown >,
	addedSlugs: string[]
): TemplateLayoutSection[] {
	if ( ! resolved ) {
		return [];
	}
	return sortedForFlow(
		withHeldBlockSections(
			resolved.template.layout.sections,
			stack,
			sheetData,
			addedSlugs
		)
	);
}

/**
 * Edits an existing character, or creates a new one, rendering the same BlockEditor per stack section as the
 * read-only sheet's own layout.
 */
export function CharacterEditor( {
	characterId,
	gameSlug,
	stackSlug,
	templateType = 'sheet_full',
	capabilities,
	startAsNpc = false,
}: CharacterEditorProps ) {
	const store = useCharacterEditorStore();

	// ---- create-mode local state (no character exists yet, so nothing here lives in the store) ----
	const [ createdId, setCreatedId ] = useState< number | null >( null );
	// The name of a character just sent as a request to join this chronicle.
	const [ joinRequested, setJoinRequested ] = useState< string | null >(
		null
	);
	const [ availableStacks, setAvailableStacks ] = useState< CreatureStack[] >(
		[]
	);
	const [ chosenStackSlug, setChosenStackSlug ] = useState< string >(
		stackSlug ?? ''
	);
	const [ createStack, setCreateStack ] = useState< ResolvedStack | null >(
		null
	);
	// Blocks a Storyteller has opened on the new character, beyond its creature type's own sections.
	const [ createAdded, setCreateAdded ] = useState< string[] >( [] );
	const [ draftName, setDraftName ] = useState( '' );
	const [ draftSheetData, setDraftSheetData ] = useState<
		Record< string, unknown >
	>( {} );
	const [ creating, setCreating ] = useState( false );
	const [ createError, setCreateError ] = useState< string | null >( null );
	const [ createIsNpc, setCreateIsNpc ] = useState( startAsNpc );
	const [ createExistingCharacter, setCreateExistingCharacter ] =
		useState( false );
	// Whether a new NPC is created as a Quick or a Full one.
	const [ createNpcDetail, setCreateNpcDetail ] = useState<
		'full' | 'quick'
	>( 'full' );
	const canFlagNpc = canIn( 'be_manage_characters', capabilities );

	// ---- shared ----
	const [ template, setTemplate ] =
		useState< TemplateResolveResponse | null >( null );
	const [ templateError, setTemplateError ] = useState< string | null >(
		null
	);
	const [ submitResult, setSubmitResult ] = useState< SubmitResult | null >(
		null
	);
	const [ confirmingReset, setConfirmingReset ] = useState( false );
	const [ reviewOpen, setReviewOpen ] = useState( false );
	const pendingCount = store.pendingChanges.length;
	const hasInvalidPending = !! store.previewCosts?.results.some(
		( result ) => result.invalid
	);
	const hasFailedSubmit = ( submitResult?.failed.length ?? 0 ) > 0;
	useEffect( () => {
		if ( hasInvalidPending || hasFailedSubmit ) {
			setReviewOpen( true );
		}
	}, [ hasInvalidPending, hasFailedSubmit ] );
	useEffect( () => {
		if ( pendingCount === 0 ) {
			setReviewOpen( false );
		}
	}, [ pendingCount ] );

	const [ headerSaveError, setHeaderSaveError ] = useState< string | null >(
		null
	);
	const [ savingNpc, setSavingNpc ] = useState( false );

	async function toggleNpc( nextIsNpc: boolean ) {
		if ( ! effectiveCharacterId ) {
			return;
		}
		setSavingNpc( true );
		try {
			await api
				.characters( gameSlug )
				.update( effectiveCharacterId, { is_npc: nextIsNpc } );
			await store.loadCharacter( effectiveCharacterId, gameSlug );
		} catch ( err: unknown ) {
			setHeaderSaveError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSavingNpc( false );
		}
	}

	const [ savingAssignee, setSavingAssignee ] = useState( false );

	async function assignNpc( assignedTo: number | null ) {
		if ( ! effectiveCharacterId ) {
			return;
		}
		setSavingAssignee( true );
		try {
			await api
				.characters( gameSlug )
				.update( effectiveCharacterId, { assigned_to: assignedTo } );
			await store.loadCharacter( effectiveCharacterId, gameSlug );
		} catch ( err: unknown ) {
			setHeaderSaveError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSavingAssignee( false );
		}
	}

	// "Make full NPC": a Quick NPC the Storyteller wants to develop further upgrades to the full sheet.
	const [ savingNpcDetail, setSavingNpcDetail ] = useState( false );

	async function upgradeToFullNpc() {
		if ( ! effectiveCharacterId ) {
			return;
		}
		setSavingNpcDetail( true );
		try {
			await api
				.characters( gameSlug )
				.update( effectiveCharacterId, { npc_detail: 'full' } );
			await store.loadCharacter( effectiveCharacterId, gameSlug );
		} catch ( err: unknown ) {
			setHeaderSaveError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSavingNpcDetail( false );
		}
	}

	const effectiveCharacterId = characterId ?? createdId;
	const isCreateMode = ! effectiveCharacterId;

	useEffect( () => {
		if ( effectiveCharacterId ) {
			store.loadCharacter( effectiveCharacterId, gameSlug );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ effectiveCharacterId, gameSlug ] );

	// Create mode: load the pickable stacks if none was given.
	useEffect( () => {
		if ( isCreateMode && ! stackSlug ) {
			api.creatureStacks
				.list( { game_slug: gameSlug } )
				.then( setAvailableStacks )
				.catch( () => {
					setAvailableStacks( [] );
					setCreateError(
						__(
							'Failed to load the list of creature types. Try refreshing the page.',
							'beyond-elysium'
						)
					);
				} );
		}
	}, [ isCreateMode, stackSlug, gameSlug ] );

	useEffect( () => {
		if ( isCreateMode && chosenStackSlug ) {
			api.creatureStacks
				.resolve( chosenStackSlug, gameSlug, true )
				.then( ( resolved ) => {
					setCreateStack( resolved );
					setCreateAdded( [] );
				} )
				.catch( () => {
					setCreateStack( null );
					setCreateError(
						__(
							'Failed to load that creature type. Try choosing it again.',
							'beyond-elysium'
						)
					);
				} );
		}
	}, [ isCreateMode, chosenStackSlug, gameSlug ] );

	// Both modes resolve the template once a stack_slug is known, matching the read-only sheet exactly.
	const activeStackSlug = isCreateMode ? chosenStackSlug : store.stackSlug;
	// An NPC gets the NPC sheet.
	const isNpc = isCreateMode ? createIsNpc : !! store.character?.is_npc;
	const npcDetail = isCreateMode
		? createNpcDetail
		: ( store.character?.npc_detail ?? 'full' );
	const npcTemplateType = npcDetail === 'quick' ? 'npc_quick' : 'npc_full';
	useEffect( () => {
		if ( activeStackSlug ) {
			api.templates( gameSlug )
				.resolve(
					activeStackSlug,
					isNpc ? npcTemplateType : templateType
				)
				.then( ( result ) => {
					setTemplate( result );
					setTemplateError( null );
				} )
				.catch( () => {
					// Shows an explicit error.
					setTemplate( null );
					setTemplateError(
						__(
							'Failed to load the character sheet layout. Try refreshing the page.',
							'beyond-elysium'
						)
					);
				} );
		}
	}, [ activeStackSlug, gameSlug, templateType, isNpc, npcTemplateType ] );

	const activeStack = isCreateMode ? createStack : store.stack;
	// Mirrors CharacterSheet.tsx's own flowing grid layout exactly.
	const sections = useMemo(
		() =>
			sortedSections(
				template,
				activeStack,
				isCreateMode ? draftSheetData : store.sheetData,
				isCreateMode ? createAdded : store.addedBlocks
			),
		[
			template,
			activeStack,
			isCreateMode,
			draftSheetData,
			store.sheetData,
			createAdded,
			store.addedBlocks,
		]
	);

	useEffect( () => {
		const dirty = isCreateMode
			? Object.keys( draftSheetData ).length > 0
			: store.dirty;
		if ( ! dirty ) {
			return;
		}
		const handler = ( e: BeforeUnloadEvent ) => {
			e.preventDefault();
			e.returnValue = '';
		};
		window.addEventListener( 'beforeunload', handler );
		return () => window.removeEventListener( 'beforeunload', handler );
	}, [ isCreateMode, draftSheetData, store.dirty ] );

	function addCreateSection( block: SchemaBlock ) {
		setCreateStack( ( prev ) =>
			prev
				? { ...prev, blocks: { ...prev.blocks, [ block.slug ]: block } }
				: prev
		);
		setCreateAdded( ( prev ) =>
			prev.includes( block.slug ) ? prev : [ ...prev, block.slug ]
		);
	}

	async function handleCreate() {
		if ( ! chosenStackSlug || ! draftName.trim() ) {
			setCreateError(
				__(
					'A name and a creature type are required.',
					'beyond-elysium'
				)
			);
			return;
		}
		setCreating( true );
		setCreateError( null );

		try {
			// One atomic create carrying the whole starting sheet; either the character is created complete, or not at all.
			const character = await api.characters( gameSlug ).create( {
				name: draftName.trim(),
				stack_slug: chosenStackSlug,
				sheet_data: draftSheetData,
				...( canFlagNpc
					? {
							is_npc: createIsNpc,
							...( createIsNpc
								? { npc_detail: createNpcDetail }
								: {} ),
							existing_character: createExistingCharacter,
						}
					: {} ),
			} );

			if ( character.join_pending ) {
				// Not a member yet, so there is no sheet to open until a Storyteller approves.
				setJoinRequested( character.name );
				return;
			}
			setCreatedId( character.id );
			await store.loadCharacter( character.id, gameSlug );
		} catch ( error ) {
			setCreateError(
				errorMessage(
					error,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setCreating( false );
		}
	}

	async function handleSubmit() {
		const result = await store.submitChanges();
		setSubmitResult( result );
	}

	if ( joinRequested ) {
		return (
			<div className="be-character-editor be-character-editor--create">
				<h2 className="be-character-editor__title">
					{ __( 'Request Sent', 'beyond-elysium' ) }
				</h2>
				<p role="status">
					{ sprintf(
						// translators: %s: character name.
						__(
							"%s is waiting for this chronicle's Storytellers to approve it. You become a player here, and can open the sheet, once they do.",
							'beyond-elysium'
						),
						joinRequested
					) }
				</p>
			</div>
		);
	}

	if ( isCreateMode ) {
		return (
			<div className="be-character-editor be-character-editor--create">
				<div className="be-help-heading">
					<h2 className="be-character-editor__title">
						{ __( 'New Character', 'beyond-elysium' ) }
					</h2>
					<HelpButton helpKey="character-editor" />
				</div>

				{ ( createError || templateError ) && (
					<div className="be-character-editor__error" role="alert">
						{ createError ?? templateError }
					</div>
				) }

				<div className="be-character-editor__field">
					<label htmlFor="be-character-editor-name">
						{ __( 'Name', 'beyond-elysium' ) }
					</label>
					<input
						id="be-character-editor-name"
						type="text"
						value={ draftName }
						onChange={ ( e ) => setDraftName( e.target.value ) }
					/>
				</div>

				{ ! stackSlug && (
					<div className="be-character-editor__field">
						<label htmlFor="be-character-editor-stack">
							{ __( 'Creature Type', 'beyond-elysium' ) }
						</label>
						<select
							id="be-character-editor-stack"
							value={ chosenStackSlug }
							onChange={ ( e ) =>
								setChosenStackSlug( e.target.value )
							}
						>
							<option value="">
								{ __( 'Choose one…', 'beyond-elysium' ) }
							</option>
							{ availableStacks.map( ( stack ) => (
								<option key={ stack.slug } value={ stack.slug }>
									{ stack.name }
								</option>
							) ) }
						</select>
					</div>
				) }

				{ canFlagNpc && (
					<div className="be-character-editor__field">
						<label htmlFor="be-character-editor-is-npc">
							<input
								id="be-character-editor-is-npc"
								type="checkbox"
								checked={ createIsNpc }
								onChange={ ( e ) =>
									setCreateIsNpc( e.target.checked )
								}
							/>{ ' ' }
							{ __( 'This is an NPC', 'beyond-elysium' ) }
						</label>
					</div>
				) }

				{ canFlagNpc && createIsNpc && (
					<div className="be-character-editor__field">
						<label>
							<input
								type="radio"
								name="be-npc-detail"
								checked={ createNpcDetail === 'full' }
								onChange={ () => setCreateNpcDetail( 'full' ) }
							/>{ ' ' }
							{ __( 'Full sheet', 'beyond-elysium' ) }
						</label>
						<label>
							<input
								type="radio"
								name="be-npc-detail"
								checked={ createNpcDetail === 'quick' }
								onChange={ () => setCreateNpcDetail( 'quick' ) }
							/>{ ' ' }
							{ __(
								'Quick stats only - enough to run this NPC in a scene',
								'beyond-elysium'
							) }
						</label>
					</div>
				) }

				{ canFlagNpc && (
					<div className="be-character-editor__field">
						<label htmlFor="be-character-editor-existing">
							<input
								id="be-character-editor-existing"
								type="checkbox"
								checked={ createExistingCharacter }
								onChange={ ( e ) =>
									setCreateExistingCharacter(
										e.target.checked
									)
								}
							/>{ ' ' }
							{ __(
								'Existing character - a sheet that already exists, so its build takes no starting XP and costs nothing to create',
								'beyond-elysium'
							) }
						</label>
					</div>
				) }

				{ activeStack && ! createExistingCharacter && (
					<div className="be-character-editor__field">
						<h4>{ __( 'Build Tally', 'beyond-elysium' ) }</h4>
						<CreationTallyPanel
							gameSlug={ gameSlug }
							stackSlug={ chosenStackSlug }
							sheetData={ draftSheetData }
							warnsPlayer={ ! canFlagNpc }
						/>
					</div>
				) }

				{ activeStack && (
					<div className="be-character-editor__grid">
						{ sections.map( ( section ) => {
							const block =
								activeStack.blocks[ section.block_slug ];
							if ( ! block ) {
								return null;
							}
							return (
								<div
									className="be-character-editor__section"
									key={ section.block_slug }
									style={ {
										gridColumn: `span ${ spanFor(
											section.width
										) }`,
									} }
								>
									<div className="be-help-heading">
										<h4>
											{ resolveSectionTitle(
												section,
												draftSheetData
											) }
										</h4>
										{ block.section_type ===
											'trait_list' && (
											<HelpButton helpKey="trait-editor" />
										) }
										{ block.section_type ===
											'tiered_power' && (
											<HelpButton helpKey="power-editor" />
										) }
										{ ( block.section_type ===
											'resource_pool' ||
											block.section_type ===
												'identity_field' ) && (
											<HelpButton helpKey="pools-identity-editor" />
										) }
										{ ( block.section_type ===
											'trait_list' ||
											block.section_type ===
												'tiered_power' ) &&
											(
												block.definition as
													| TraitListDefinition
													| TieredPowerDefinition
											 ).player_order && (
												<HelpButton helpKey="player-order" />
											) }
									</div>
									<BlockEditor
										blockSlug={ section.block_slug }
										sectionType={ block.section_type }
										definition={ block.definition }
										data={
											draftSheetData[ section.block_slug ]
										}
										onChange={ ( slug, data ) =>
											setDraftSheetData( ( prev ) => ( {
												...prev,
												[ slug ]: data,
											} ) )
										}
										sheetData={ draftSheetData }
										gameSlug={ gameSlug }
										isManager={ canFlagNpc }
										creating
									/>
								</div>
							);
						} ) }
					</div>
				) }

				{ canFlagNpc && allowsAnyBlock( activeStack ) && (
					<AddSectionPicker
						gameSlug={ gameSlug }
						shown={ sections.map( ( s ) => s.block_slug ) }
						onAdd={ addCreateSection }
					/>
				) }

				<button
					type="button"
					disabled={ creating || ! chosenStackSlug }
					onClick={ handleCreate }
				>
					{ creating
						? __( 'Creating…', 'beyond-elysium' )
						: __( 'Create Character', 'beyond-elysium' ) }
				</button>
			</div>
		);
	}

	if ( store.loading ) {
		return (
			<div className="be-character-editor__skeleton">
				{ __( 'Loading character…', 'beyond-elysium' ) }
			</div>
		);
	}

	if ( store.error && ! store.character ) {
		return (
			<div className="be-character-editor__error" role="alert">
				{ store.error }
			</div>
		);
	}

	if ( ! store.character || ! store.stack ) {
		return null;
	}

	const canEdit = store.character.can_edit ?? false;
	const canManage = store.character.can_manage ?? false;
	const readOnly = ! canEdit;

	const pendingTotal = store.pendingChanges.length;
	const totalCost =
		store.previewCosts?.results.reduce(
			( sum, r ) => sum + r.xp_cost,
			0
		) ?? 0;
	const resultingUnspent =
		store.previewCosts?.running_xp_unspent ?? store.character.xp_unspent;
	const overBudget = ! canManage && resultingUnspent < 0;

	const pendingSummary = pendingLine(
		pendingTotal,
		canManage || ! store.previewCosts ? null : totalCost,
		canManage || ! store.previewCosts ? null : resultingUnspent
	);
	const submitMessages =
		submitResult &&
		( submitResult.failed.length > 0 ||
			submitResult.pending.length > 0 ) ? (
			<>
				{ submitResult.failed.length > 0 && (
					<p className="be-character-editor__error" role="alert">
						{ sprintf(
							/* translators: 1: number of changes saved, 2: total number of changes submitted, 3: number that failed and need retrying */
							_n(
								'%1$d of %2$d changes were saved. %3$d did not - review and submit again to retry it.',
								'%1$d of %2$d changes were saved. %3$d did not - review and submit again to retry them.',
								submitResult.failed.length,
								'beyond-elysium'
							),
							submitResult.submitted.length,
							submitResult.submitted.length +
								submitResult.failed.length,
							submitResult.failed.length
						) }
					</p>
				) }
				{ submitResult.pending.length > 0 && (
					<p
						className="be-character-editor__pending-notice"
						role="status"
					>
						{ sprintf(
							/* translators: %d: number of changes submitted and awaiting Storyteller approval */
							_n(
								'%d change was submitted and is awaiting Storyteller approval - it will not appear on the sheet until then.',
								'%d changes were submitted and are awaiting Storyteller approval - they will not appear on the sheet until then.',
								submitResult.pending.length,
								'beyond-elysium'
							),
							submitResult.pending.length
						) }
					</p>
				) }
			</>
		) : null;

	return (
		<div className="be-character-editor">
			<DemoBanner gameSlug={ gameSlug } />
			<SheetWarnings warnings={ store.character.sheet_warnings } />
			{ store.restorableDraft && ! readOnly && (
				<div
					className="be-character-editor__draft-notice"
					role="status"
				>
					<p>
						{ sprintf(
							/* translators: %s: when the draft was saved, e.g. "9/11/2026, 3:45:00 PM" */
							__(
								'You have unsaved changes from a previous session, saved %s.',
								'beyond-elysium'
							),
							new Date(
								store.restorableDraft.savedAt
							).toLocaleString()
						) }
					</p>
					<div className="be-character-editor__draft-notice-actions">
						<button
							type="button"
							onClick={ () => store.restoreDraft() }
						>
							{ __( 'Restore', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							onClick={ () => store.dismissDraft() }
						>
							{ __( 'Discard', 'beyond-elysium' ) }
						</button>
					</div>
				</div>
			) }

			<div className="be-character-editor__header">
				{ store.character.image_url && (
					<img
						className="be-character-editor__portrait"
						src={ store.character.image_url }
						alt={ sprintf(
							/* translators: %s: the character's own name */
							__( '%s portrait', 'beyond-elysium' ),
							store.character.name
						) }
					/>
				) }
				<div>
					<div className="be-help-heading">
						<h2>{ store.character.name }</h2>
						<HelpButton helpKey="character-editor" />
					</div>
					{ readOnly && (
						<p className="be-character-editor__readonly-note">
							{ __(
								'You can view this sheet but not edit it.',
								'beyond-elysium'
							) }
						</p>
					) }
					{ canFlagNpc && canManage && (
						<label className="be-character-editor__npc-toggle">
							<input
								type="checkbox"
								checked={ isNpc }
								disabled={ savingNpc }
								onChange={ ( e ) =>
									toggleNpc( e.target.checked )
								}
							/>{ ' ' }
							{ __( 'This is an NPC', 'beyond-elysium' ) }
						</label>
					) }
					{ canFlagNpc && canManage && isNpc && (
						<label className="be-character-editor__npc-assignee">
							{ __( 'Assigned to', 'beyond-elysium' ) }{ ' ' }
							<AssigneePicker
								gameSlug={ gameSlug }
								value={ store.character?.assigned_to ?? null }
								disabled={ savingAssignee }
								onChange={ assignNpc }
							/>
						</label>
					) }
					{ canFlagNpc &&
						canManage &&
						isNpc &&
						npcDetail === 'quick' && (
							<button
								type="button"
								disabled={ savingNpcDetail }
								onClick={ upgradeToFullNpc }
							>
								{ savingNpcDetail
									? __( 'Upgrading…', 'beyond-elysium' )
									: __( 'Make Full NPC', 'beyond-elysium' ) }
							</button>
						) }
				</div>
			</div>

			{ ( store.error || templateError || headerSaveError ) && (
				<div className="be-character-editor__error" role="alert">
					{ store.error ?? templateError ?? headerSaveError }
				</div>
			) }

			{ canFlagNpc && canManage && isNpc && effectiveCharacterId && (
				<div className="be-character-editor__section">
					<SecretsPanel
						gameSlug={ gameSlug }
						entityType="npc"
						entityId={ effectiveCharacterId }
					/>
				</div>
			) }

			{ canFlagNpc && canManage && isNpc && effectiveCharacterId && (
				<div className="be-character-editor__section">
					<NpcHooksPanel
						gameSlug={ gameSlug }
						characterId={ effectiveCharacterId }
					/>
				</div>
			) }

			<div className="be-character-editor__grid">
				{ sections.map( ( section ) => {
					const block = store.stack?.blocks[ section.block_slug ];
					if ( ! block ) {
						return null;
					}
					return (
						<CollapsiblePanel
							id={ `character-editor-section:${ section.block_slug }` }
							className="be-character-editor__section"
							key={ section.block_slug }
							style={ {
								gridColumn: `span ${ spanFor(
									section.width
								) }`,
							} }
							heading={
								<span className="be-help-heading">
									<h4>
										{ resolveSectionTitle(
											section,
											store.sheetData
										) }
									</h4>
									{ block.section_type === 'trait_list' && (
										<HelpButton helpKey="trait-editor" />
									) }
									{ block.section_type === 'tiered_power' && (
										<HelpButton helpKey="power-editor" />
									) }
									{ ( block.section_type ===
										'resource_pool' ||
										block.section_type ===
											'identity_field' ) && (
										<HelpButton helpKey="pools-identity-editor" />
									) }
									{ ( block.section_type === 'trait_list' ||
										block.section_type ===
											'tiered_power' ) &&
										(
											block.definition as
												| TraitListDefinition
												| TieredPowerDefinition
										 ).player_order && (
											<HelpButton helpKey="player-order" />
										) }
									{ section.block_slug ===
										'npc-roleplaying-notes' &&
										! readOnly &&
										effectiveCharacterId && (
											<NpcRoleplayingDraftButton
												gameSlug={ gameSlug }
												characterId={
													effectiveCharacterId
												}
												characterName={
													store.character?.name ?? ''
												}
												currentData={
													( store.sheetData[
														section.block_slug
													] ?? {} ) as Record<
														string,
														string
													>
												}
												fieldNames={ (
													(
														block.definition as IdentityFieldDefinition
													 ).fields ?? []
												).map(
													( field ) => field.name
												) }
												onApply={ ( merged ) =>
													store.setBlockData(
														section.block_slug,
														{
															...( store
																.sheetData[
																section
																	.block_slug
															] ?? {} ),
															...merged,
														}
													)
												}
											/>
										) }
								</span>
							}
						>
							<BlockEditor
								blockSlug={ section.block_slug }
								sectionType={ block.section_type }
								definition={ block.definition }
								data={ store.sheetData[ section.block_slug ] }
								onChange={ store.setBlockData }
								readOnly={ readOnly }
								sheetData={ store.sheetData }
								gameSlug={ gameSlug }
								characterId={ effectiveCharacterId }
								isManager={ canManage }
								savedData={
									store.originalSheetData[
										section.block_slug
									]
								}
							/>
						</CollapsiblePanel>
					);
				} ) }
			</div>

			{ ! readOnly && canManage && allowsAnyBlock( store.stack ) && (
				<AddSectionPicker
					gameSlug={ gameSlug }
					shown={ sections.map( ( s ) => s.block_slug ) }
					onAdd={ store.addSection }
				/>
			) }

			{ ! readOnly && pendingTotal > 0 && (
				<div
					className="be-character-editor__summary"
					id="character-editor-pending"
				>
					<div className="be-character-editor__chip">
						<p
							className="be-character-editor__chip-line"
							role="status"
						>
							{ pendingSummary.lead }
							{ pendingSummary.tail && (
								<>
									{ ' · ' }
									<span
										className={
											pendingSummary.over
												? 'be-character-editor__over-budget'
												: undefined
										}
									>
										{ pendingSummary.tail }
									</span>
								</>
							) }
						</p>
						<button
							type="button"
							aria-expanded={ reviewOpen }
							aria-controls="character-editor-review"
							onClick={ () =>
								setReviewOpen( ( open ) => ! open )
							}
						>
							{ reviewOpen
								? __( 'Close', 'beyond-elysium' )
								: __( 'Review', 'beyond-elysium' ) }
						</button>
					</div>
					{ reviewOpen && (
						<div
							className="be-character-editor__review"
							id="character-editor-review"
						>
							<ul className="be-character-editor__pending-list">
								{ store.pendingChanges.map( ( change, i ) => {
									const preview =
										store.previewCosts?.results[ i ];
									return (
										<li
											key={ i }
											className={
												preview?.invalid
													? 'be-character-editor__pending--invalid'
													: undefined
											}
										>
											{ describeChange(
												change.change_type,
												change.change_data
											) }
											{ preview && (
												<>
													{ ' ' }
													(
													{ previewPriceLabel(
														preview
													) ?? (
														<>
															{ preview.xp_cost >=
															0
																? '+'
																: '' }
															{ sprintf(
																/* translators: %d: the XP cost or refund for this pending change */
																__(
																	'%d XP',
																	'beyond-elysium'
																),
																preview.xp_cost
															) }
														</>
													) }
													{ ! preview.invalid && (
														<>
															,{ ' ' }
															{ approvalLevelLabel(
																preview.approval_level
															) }
														</>
													) }
													)
												</>
											) }
										</li>
									);
								} ) }
							</ul>
							<p className="be-character-editor__totals">
								{ sprintf(
									/* translators: 1: total signed XP cost of every pending change, already formatted with a sign, 2: unspent XP remaining after applying them */
									__(
										'Total: %1$s XP — Unspent after: %2$d',
										'beyond-elysium'
									),
									`${ totalCost >= 0 ? '+' : '' }${ totalCost }`,
									resultingUnspent
								) }
								{ overBudget && (
									<span className="be-character-editor__over-budget">
										{ ' ' }
										{ __(
											'(exceeds available XP)',
											'beyond-elysium'
										) }
									</span>
								) }
							</p>
							<div className="be-character-editor__actions">
								<button
									type="button"
									disabled={
										store.saving ||
										pendingTotal === 0 ||
										overBudget
									}
									onClick={ handleSubmit }
								>
									{ store.saving
										? __( 'Submitting…', 'beyond-elysium' )
										: __(
												'Submit Changes',
												'beyond-elysium'
											) }
								</button>
								<button
									type="button"
									disabled={
										store.saving || pendingTotal === 0
									}
									onClick={ () => setConfirmingReset( true ) }
								>
									{ __( 'Discard', 'beyond-elysium' ) }
								</button>
							</div>
							{ submitMessages }
						</div>
					) }
				</div>
			) }
			{ ! readOnly && pendingTotal === 0 && submitMessages && (
				<div className="be-character-editor__result">
					{ submitMessages }
				</div>
			) }

			<ConfirmDialog
				open={ confirmingReset }
				title={ __( 'Discard unsaved changes?', 'beyond-elysium' ) }
				message={ __(
					'This will revert every field back to its last saved state and return you to the character sheet.',
					'beyond-elysium'
				) }
				confirmLabel={ __( 'Discard', 'beyond-elysium' ) }
				onConfirm={ () => {
					// Navigates back to the sheet.
					store.reset();
					window.location.href = characterSheetUrl(
						effectiveCharacterId,
						gameSlug
					);
				} }
				onCancel={ () => setConfirmingReset( false ) }
			/>
		</div>
	);
}

export default CharacterEditor;
