/**
 * CharacterEditor is the character creation and editing screen: it renders a
 * BlockEditor per stack section for picking traits, plus background/notes,
 * portrait, and a pending-changes summary with submit/discard controls. Handles
 * both create mode (no character yet) and edit mode (an existing character).
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import useCharacterEditorStore from '../../store/characterEditorStore';
import BlockEditor from '../editors/BlockEditor';
import ConfirmDialog from '../shared/ConfirmDialog';
import HtmlEditor from '../shared/HtmlEditor';
import HelpButton from '../shared/HelpButton';
import AssigneePicker from '../shared/AssigneePicker';
import AudiencePicker from '../shared/AudiencePicker';
import SecretsPanel from '../shared/SecretsPanel';
import CollapsiblePanel from '../shared/CollapsiblePanel';
import { previewPriceLabel } from '../../lib/queuePrice';
import { spanFor, sortedForFlow } from '../../lib/templateLayout';
import { resolveSectionTitle } from '../../lib/resolveCrossBlockRef';
import { pickMediaImage } from '../../lib/pickMediaImage';
import { characterSheetUrl } from '../../lib/pluginPages';
import { changedFields } from '../../lib/changedFields';
import { canIn } from '../../lib/chronicleCapabilities';
import type {
	CreatureStack,
	MyCapabilities,
	ResolvedStack,
	TemplateLayoutSection,
	TemplateResolveResponse,
	TraitListDefinition,
	TieredPowerDefinition,
} from '../../types';
import type { AudienceRules, AudienceValue } from '../../types/plot';
import type { SubmitResult } from '../../store/characterEditorStore';
import './CharacterEditor.css';

export interface CharacterEditorProps {
	/** Present = edit mode. Absent = create mode. */
	characterId?: number;
	gameSlug: string;
	/** Create mode only. Absent means the player picks a stack first. */
	stackSlug?: string;
	templateType?: string;
	/** What the person can do in this chronicle, when the page resolved it; the site-wide snapshot otherwise (F-103). */
	capabilities?: MyCapabilities;
}

import { errorMessage } from '../../lib/errorMessage';

function sortedSections(
	resolved: TemplateResolveResponse | null
): TemplateLayoutSection[] {
	if ( ! resolved ) {
		return [];
	}
	return sortedForFlow( resolved.template.layout.sections );
}

/**
 * Edits an existing character, or creates a new one, rendering the same
 * BlockEditor per stack section as the read-only sheet's own layout. Create
 * mode collects picks locally and creates an empty character shell first, then
 * applies every pick through the same diff/submit path an edit uses; edit mode
 * loads the character's current state directly into the editor store.
 */
export function CharacterEditor( {
	characterId,
	gameSlug,
	stackSlug,
	templateType = 'sheet_full',
	capabilities,
}: CharacterEditorProps ) {
	const store = useCharacterEditorStore();

	// ---- create-mode local state (no character exists yet, so nothing here lives in the store) ----
	const [ createdId, setCreatedId ] = useState< number | null >( null );
	// The name of a character just sent as a request to join this chronicle (1.0.0-review F-033).
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
	const [ draftName, setDraftName ] = useState( '' );
	const [ draftSheetData, setDraftSheetData ] = useState<
		Record< string, unknown >
	>( {} );
	const [ creating, setCreating ] = useState( false );
	const [ createError, setCreateError ] = useState< string | null >( null );
	// admin-menu-consolidation-design.md: Storyteller-only, never shown to a player.
	const [ createIsNpc, setCreateIsNpc ] = useState( false );
	// "New NPC asks Quick or Full" (1.1.0 §3.7 item 1) - meaningless unless createIsNpc.
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

	// Background/Notes are header fields that save directly, independent of the pending-changes flow below.
	const biographyDraft = useRef< string >( '' );
	const notesDraft = useRef< string >( '' );
	// What was loaded or last saved: a save sends only the fields that differ from it (1.0.0-review F-076).
	const headerSaved = useRef< { biography: string; notes: string } >( {
		biography: '',
		notes: '',
	} );
	const headerDraftsSeeded = useRef< number | null >( null );
	const [ savingHeader, setSavingHeader ] = useState( false );
	const [ headerSaveError, setHeaderSaveError ] = useState< string | null >(
		null
	);
	const [ headerSaveMessage, setHeaderSaveMessage ] = useState<
		string | null
	>( null );

	async function saveBackgroundAndNotes() {
		if ( ! effectiveCharacterId ) {
			return;
		}
		setSavingHeader( true );
		setHeaderSaveError( null );
		setHeaderSaveMessage( null );
		// Only what was edited: a field left alone is not put back as it was when this editor
		// opened, over whatever someone else has saved to it since.
		const edited = changedFields(
			{ biography: biographyDraft.current, notes: notesDraft.current },
			headerSaved.current
		);
		try {
			if ( Object.keys( edited ).length > 0 ) {
				await api
					.characters( gameSlug )
					.update( effectiveCharacterId, edited );
				headerSaved.current = { ...headerSaved.current, ...edited };
			}
			setHeaderSaveMessage( __( 'Saved.', 'beyond-elysium' ) );
		} catch ( err: unknown ) {
			setHeaderSaveError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSavingHeader( false );
		}
	}

	// A WP attachment ID/URL, same picker pattern as SheetStyleEditor; local state so the image updates immediately.
	const [ portraitUrl, setPortraitUrl ] = useState< string | null >( null );
	const [ savingPortrait, setSavingPortrait ] = useState( false );

	// admin-menu-consolidation-design.md: flagging an existing character as an NPC (or
	// back) after creation - Storyteller-only, never shown to a player.
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
			// Reloads so the template re-resolves (sheet_full <-> npc_full follows is_npc directly).
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

	// Staff assignment (1.1.0 §3.6): an NPC's own staff owner. Same reload-after-save shape as toggleNpc().
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

	// "Make full NPC" (1.1.0 §3.7 item 1): a Quick NPC the Storyteller wants to develop
	// further upgrades to the full sheet; there is no downgrade path back to Quick.
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

	// Who's Who public profile (1.1.0 §3.7 item 3): a Storyteller-only panel, separate from
	// the sheet itself, so it saves through its own PUT rather than the header-fields path.
	const publicNameDraft = useRef( '' );
	const publicDescriptionDraft = useRef( '' );
	const [ publicImageUrl, setPublicImageUrl ] = useState< string | null >(
		null
	);
	const [ publicImageId, setPublicImageId ] = useState< number | null >(
		null
	);
	const [ profileAudience, setProfileAudience ] =
		useState< AudienceValue >( 'storytellers' );
	const [ profileAudienceRules, setProfileAudienceRules ] =
		useState< AudienceRules | null >( null );
	const [ savingProfile, setSavingProfile ] = useState( false );
	const [ profileSaveMessage, setProfileSaveMessage ] = useState<
		string | null
	>( null );
	const [ profileSaveError, setProfileSaveError ] = useState< string | null >(
		null
	);

	async function pickPublicImage() {
		const attachment = await pickMediaImage(
			__( "Choose a Who's Who portrait", 'beyond-elysium' )
		);
		if ( ! attachment ) {
			return;
		}
		setPublicImageId( attachment.id );
		setPublicImageUrl( attachment.url );
	}

	async function saveProfile() {
		if ( ! effectiveCharacterId ) {
			return;
		}
		setSavingProfile( true );
		setProfileSaveError( null );
		setProfileSaveMessage( null );
		try {
			await api.npcs( gameSlug ).updateProfile( effectiveCharacterId, {
				public_name: publicNameDraft.current,
				public_description: publicDescriptionDraft.current,
				public_image_id: publicImageId,
				profile_audience: profileAudience,
				profile_audience_rules: profileAudienceRules,
			} );
			setProfileSaveMessage( __( 'Saved.', 'beyond-elysium' ) );
		} catch ( err: unknown ) {
			setProfileSaveError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSavingProfile( false );
		}
	}

	async function pickPortrait() {
		if ( ! effectiveCharacterId ) {
			return;
		}
		const attachment = await pickMediaImage(
			__( 'Choose a character portrait', 'beyond-elysium' )
		);
		if ( ! attachment ) {
			return;
		}
		setSavingPortrait( true );
		try {
			await api
				.characters( gameSlug )
				.update( effectiveCharacterId, { image_id: attachment.id } );
			setPortraitUrl( attachment.url );
		} catch ( err: unknown ) {
			setHeaderSaveError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSavingPortrait( false );
		}
	}

	const effectiveCharacterId = characterId ?? createdId;
	const isCreateMode = ! effectiveCharacterId;

	// Edit mode: load the character once we have a real id.
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

	// Create mode: resolve the chosen stack for rendering its block editors.
	// forCreation narrows any restricted identity field's options (a Vampire
	// Clan/Sect subset, say) - safe only here, never for viewing/editing an
	// already-existing character.
	useEffect( () => {
		if ( isCreateMode && chosenStackSlug ) {
			api.creatureStacks
				.resolve( chosenStackSlug, gameSlug, true )
				.then( setCreateStack )
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
	// An NPC gets the NPC sheet, which adds the Storyteller-only sections - npc_quick's
	// shorter one when a Quick NPC hasn't been upgraded to npc_full (1.1.0 §3.7 item 1).
	const isNpc = isCreateMode ? createIsNpc : !! store.character?.is_npc;
	const npcDetail = isCreateMode
		? createNpcDetail
		: store.character?.npc_detail ?? 'full';
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
					// Shows an explicit error instead of silently rendering zero fields in either mode.
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
	// Mirrors CharacterSheet.tsx's own flowing grid layout exactly, so the editor matches the printed sheet.
	const sections = useMemo( () => sortedSections( template ), [ template ] );

	// Unsaved-changes guard on navigation away, via the browser's beforeunload event.
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
										{ /* One help doc per section_type, written as literal
										 * per-type helpKey props (not a lookup object) so
										 * helpDocs.test.ts's static scan can see each one. */ }
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
									/>
								</div>
							);
						} ) }
					</div>
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

	// Read-only degradation by capability: a viewer who can't edit is told so, not shown a disabled editor.
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

	// Seeds the drafts from the loaded character once per character, not on every re-render.
	if ( headerDraftsSeeded.current !== effectiveCharacterId ) {
		biographyDraft.current = store.character.biography ?? '';
		notesDraft.current = store.character.notes ?? '';
		headerSaved.current = {
			biography: biographyDraft.current,
			notes: notesDraft.current,
		};
		setPortraitUrl( store.character.image_url ?? null );
		publicNameDraft.current = store.character.public_name ?? '';
		publicDescriptionDraft.current =
			store.character.public_description ?? '';
		// No dedicated URL field for public_image_id comes back from the sheet-header
		// response; a freshly-picked one shows immediately via pickPublicImage()'s own
		// attachment.url, same as the main portrait picker does.
		setPublicImageId( store.character.public_image_id ?? null );
		setPublicImageUrl( null );
		setProfileAudience(
			store.character.profile_audience ?? 'storytellers'
		);
		setProfileAudienceRules(
			store.character.profile_audience_rules ?? null
		);
		setProfileSaveMessage( null );
		setProfileSaveError( null );
		headerDraftsSeeded.current = effectiveCharacterId;
	}

	return (
		<div className="be-character-editor">
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
				{ portraitUrl && (
					<img
						className="be-character-editor__portrait"
						src={ portraitUrl }
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
					{ ! readOnly && (
						<button
							type="button"
							className="be-character-editor__portrait-button"
							disabled={ savingPortrait }
							onClick={ pickPortrait }
						>
							{ savingPortrait
								? __( 'Saving…', 'beyond-elysium' )
								: portraitUrl
								? __( 'Change portrait…', 'beyond-elysium' )
								: __( 'Add a portrait…', 'beyond-elysium' ) }
						</button>
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

			{ ( store.error || templateError ) && (
				<div className="be-character-editor__error" role="alert">
					{ store.error ?? templateError }
				</div>
			) }

			<div
				className="be-character-editor__section be-character-editor__header-text"
				key={ `header-text-${ effectiveCharacterId }` }
			>
				<h4>{ __( 'Background', 'beyond-elysium' ) }</h4>
				<HtmlEditor
					id={ `be-biography-${ effectiveCharacterId }` }
					defaultValue={ biographyDraft.current }
					onChange={ ( html ) => ( biographyDraft.current = html ) }
					readOnly={ readOnly }
					aiAssist={ {
						capability: 'be_manage_characters',
						fieldContext: 'character_biography',
						gameSlug,
					} }
				/>

				<h4>{ __( 'Notes', 'beyond-elysium' ) }</h4>
				<HtmlEditor
					id={ `be-notes-${ effectiveCharacterId }` }
					defaultValue={ notesDraft.current }
					onChange={ ( html ) => ( notesDraft.current = html ) }
					readOnly={ readOnly }
					aiAssist={ {
						capability: 'be_manage_characters',
						fieldContext: 'character_notes',
						gameSlug,
					} }
				/>

				{ ! readOnly && (
					<div className="be-character-editor__header-text-actions">
						<button
							type="button"
							disabled={ savingHeader }
							onClick={ saveBackgroundAndNotes }
						>
							{ savingHeader
								? __( 'Saving…', 'beyond-elysium' )
								: __(
										'Save Background & Notes',
										'beyond-elysium'
								  ) }
						</button>
						{ headerSaveMessage && (
							<span className="be-character-editor__header-text-status">
								{ headerSaveMessage }
							</span>
						) }
						{ headerSaveError && (
							<span
								className="be-character-editor__error"
								role="alert"
							>
								{ headerSaveError }
							</span>
						) }
					</div>
				) }
			</div>

			{ canFlagNpc && canManage && isNpc && (
				<div
					className="be-character-editor__section be-character-editor__profile"
					key={ `profile-${ effectiveCharacterId }` }
				>
					<div className="be-help-heading">
						<h4>{ __( "Who's Who Profile", 'beyond-elysium' ) }</h4>
						<HelpButton helpKey="whos-who" />
					</div>
					<p className="be-character-editor__profile-hint">
						{ __(
							"What a player sees about this NPC in the chronicle's Who's Who directory - separate from the sheet above.",
							'beyond-elysium'
						) }
					</p>

					<div className="be-character-editor__field">
						<label
							htmlFor={ `be-public-name-${ effectiveCharacterId }` }
						>
							{ __( 'Display Name', 'beyond-elysium' ) }
						</label>
						<input
							id={ `be-public-name-${ effectiveCharacterId }` }
							type="text"
							placeholder={ store.character.name }
							defaultValue={ publicNameDraft.current }
							onChange={ ( e ) =>
								( publicNameDraft.current = e.target.value )
							}
						/>
					</div>

					<h4>{ __( 'Description', 'beyond-elysium' ) }</h4>
					<HtmlEditor
						id={ `be-public-description-${ effectiveCharacterId }` }
						defaultValue={ publicDescriptionDraft.current }
						onChange={ ( html ) =>
							( publicDescriptionDraft.current = html )
						}
						aiAssist={ {
							capability: 'be_manage_characters',
							fieldContext: 'npc_public_description',
							gameSlug,
						} }
					/>

					<div className="be-character-editor__field">
						{ publicImageUrl && (
							<img
								className="be-character-editor__portrait"
								src={ publicImageUrl }
								alt=""
							/>
						) }
						<button type="button" onClick={ pickPublicImage }>
							{ publicImageId
								? __( 'Change portrait…', 'beyond-elysium' )
								: __(
										"Choose a Who's Who portrait…",
										'beyond-elysium'
								  ) }
						</button>
					</div>

					<h4>
						{ __( 'Who Can See This Profile', 'beyond-elysium' ) }
					</h4>
					<AudiencePicker
						gameSlug={ gameSlug }
						audience={ profileAudience }
						audienceRules={ profileAudienceRules }
						onChange={ ( audience, rules ) => {
							setProfileAudience( audience );
							setProfileAudienceRules( rules );
						} }
					/>

					<div className="be-character-editor__header-text-actions">
						<button
							type="button"
							disabled={ savingProfile }
							onClick={ saveProfile }
						>
							{ savingProfile
								? __( 'Saving…', 'beyond-elysium' )
								: __( 'Save Profile', 'beyond-elysium' ) }
						</button>
						{ profileSaveMessage && (
							<span className="be-character-editor__header-text-status">
								{ profileSaveMessage }
							</span>
						) }
						{ profileSaveError && (
							<span
								className="be-character-editor__error"
								role="alert"
							>
								{ profileSaveError }
							</span>
						) }
					</div>
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

			<div className="be-character-editor__grid">
				{ sections.map( ( section ) => {
					const block = store.stack?.blocks[ section.block_slug ];
					if ( ! block ) {
						return null;
					}
					return (
						<CollapsiblePanel
							/*
							 * U7e: block slugs are stack-wide, so a player who folds
							 * Backgrounds away finds it folded on their next character of
							 * the same creature type too - which is the point. Namespaced
							 * to keep the editor's own state distinct from the sheet's.
							 */
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
							/>
						</CollapsiblePanel>
					);
				} ) }
			</div>

			{ ! readOnly && (
				<CollapsiblePanel
					id="character-editor-pending"
					className="be-character-editor__summary"
					/*
					 * D74/U7c: re-open when a change is queued, even if the player folded the
					 * panel away. Keyed on the count so 0 -> N forces it open and nobody
					 * submits blind to what they changed; it never forces it shut, so folding
					 * it again while changes are pending sticks.
					 */
					forceOpenKey={ pendingTotal }
					heading={
						<h4>
							{ sprintf(
								/* translators: %d: number of unsaved pending changes */
								__( 'Pending Changes (%d)', 'beyond-elysium' ),
								pendingTotal
							) }
						</h4>
					}
				>
					{ pendingTotal === 0 && (
						<p>{ __( 'No unsaved changes.', 'beyond-elysium' ) }</p>
					) }
					{ /* D74: this list is unbounded - one entry per queued change - inside a
					 * `position: sticky; bottom: 0` panel. Without a height cap it grew over
					 * the editor it belongs to (7-8 changes covered 37-68% of the viewport,
					 * owner-reported live 2026-09-21). The cap lives on the list, not the
					 * panel, so the heading and the XP totals below stay visible while it
					 * scrolls - the totals are the whole reason the panel exists. */ }
					<ul className="be-character-editor__pending-list">
						{ store.pendingChanges.map( ( change, i ) => {
							const preview = store.previewCosts?.results[ i ];
							return (
								<li key={ i }>
									{ change.change_type } — { change.category }
									{ preview && (
										<>
											{ ' ' }
											(
											{ /* Homebrew has no catalog price: the player is told a Storyteller sets it,
											 * not shown the 0 the server holds in its place (1.3.3 E5). */ }
											{ previewPriceLabel( preview ) ?? (
												<>
													{ preview.xp_cost >= 0
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
											, { preview.approval_level })
										</>
									) }
								</li>
							);
						} ) }
					</ul>
					{ pendingTotal > 0 && (
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
					) }

					<div className="be-character-editor__actions">
						<button
							type="button"
							disabled={
								store.saving || pendingTotal === 0 || overBudget
							}
							onClick={ handleSubmit }
						>
							{ store.saving
								? __( 'Submitting…', 'beyond-elysium' )
								: __( 'Submit Changes', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							disabled={ store.saving || pendingTotal === 0 }
							onClick={ () => setConfirmingReset( true ) }
						>
							{ __( 'Discard', 'beyond-elysium' ) }
						</button>
					</div>

					{ submitResult && submitResult.failed.length > 0 && (
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

					{ submitResult && submitResult.pending.length > 0 && (
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
				</CollapsiblePanel>
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
					// Navigates back to the sheet so the discard's effect is visible, not just a silent state reset.
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
