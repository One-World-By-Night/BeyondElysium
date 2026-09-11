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
import { spanFor, sortedForFlow } from '../../lib/templateLayout';
import { resolveSectionTitle } from '../../lib/resolveCrossBlockRef';
import { pickMediaImage } from '../../lib/pickMediaImage';
import type { CreatureStack, ResolvedStack, TemplateLayoutSection, TemplateResolveResponse } from '../../types';
import type { SubmitResult } from '../../store/characterEditorStore';
import './CharacterEditor.css';

export interface CharacterEditorProps {
	/** Present = edit mode. Absent = create mode. */
	characterId?: number;
	gameSlug: string;
	/** Create mode only. Absent means the player picks a stack first. */
	stackSlug?: string;
	templateType?: string;
}

interface RestError {
	message?: string;
	data?: { status?: number };
}

function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null && ( error as RestError ).message ) {
		return ( error as RestError ).message as string;
	}
	return __( 'Something went wrong.', 'beyond-elysium' );
}

function sortedSections( resolved: TemplateResolveResponse | null ): TemplateLayoutSection[] {
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
export function CharacterEditor( { characterId, gameSlug, stackSlug, templateType = 'sheet_full' }: CharacterEditorProps ) {
	const store = useCharacterEditorStore();

	// ---- create-mode local state (no character exists yet, so nothing here lives in the store) ----
	const [ createdId, setCreatedId ] = useState<number | null>( null );
	const [ availableStacks, setAvailableStacks ] = useState<CreatureStack[]>( [] );
	const [ chosenStackSlug, setChosenStackSlug ] = useState<string>( stackSlug ?? '' );
	const [ createStack, setCreateStack ] = useState<ResolvedStack | null>( null );
	const [ draftName, setDraftName ] = useState( '' );
	const [ draftSheetData, setDraftSheetData ] = useState<Record<string, unknown>>( {} );
	const [ creating, setCreating ] = useState( false );
	const [ createError, setCreateError ] = useState<string | null>( null );

	// ---- shared ----
	const [ template, setTemplate ] = useState<TemplateResolveResponse | null>( null );
	const [ templateError, setTemplateError ] = useState<string | null>( null );
	const [ submitResult, setSubmitResult ] = useState<SubmitResult | null>( null );
	const [ confirmingReset, setConfirmingReset ] = useState( false );

	// Background/Notes are header fields that save directly, independent of the pending-changes flow below.
	const biographyDraft = useRef<string>( '' );
	const notesDraft = useRef<string>( '' );
	const headerDraftsSeeded = useRef<number | null>( null );
	const [ savingHeader, setSavingHeader ] = useState( false );
	const [ headerSaveError, setHeaderSaveError ] = useState<string | null>( null );
	const [ headerSaveMessage, setHeaderSaveMessage ] = useState<string | null>( null );

	async function saveBackgroundAndNotes() {
		if ( ! effectiveCharacterId ) {
			return;
		}
		setSavingHeader( true );
		setHeaderSaveError( null );
		setHeaderSaveMessage( null );
		try {
			await api.characters( gameSlug ).update( effectiveCharacterId, {
				biography: biographyDraft.current,
				notes: notesDraft.current,
			} );
			setHeaderSaveMessage( __( 'Saved.', 'beyond-elysium' ) );
		} catch ( err: unknown ) {
			setHeaderSaveError( errorMessage( err ) );
		} finally {
			setSavingHeader( false );
		}
	}

	// A WP attachment ID/URL, same picker pattern as SheetStyleEditor; local state so the image updates immediately.
	const [ portraitUrl, setPortraitUrl ] = useState<string | null>( null );
	const [ savingPortrait, setSavingPortrait ] = useState( false );

	async function pickPortrait() {
		if ( ! effectiveCharacterId ) {
			return;
		}
		const attachment = await pickMediaImage( __( 'Choose a character portrait', 'beyond-elysium' ) );
		if ( ! attachment ) {
			return;
		}
		setSavingPortrait( true );
		try {
			await api.characters( gameSlug ).update( effectiveCharacterId, { image_id: attachment.id } );
			setPortraitUrl( attachment.url );
		} catch ( err: unknown ) {
			setHeaderSaveError( errorMessage( err ) );
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
				.list()
				.then( setAvailableStacks )
				.catch( () => {
					setAvailableStacks( [] );
					setCreateError( __( 'Failed to load the list of creature types. Try refreshing the page.', 'beyond-elysium' ) );
				} );
		}
	}, [ isCreateMode, stackSlug ] );

	// Create mode: resolve the chosen stack for rendering its block editors.
	useEffect( () => {
		if ( isCreateMode && chosenStackSlug ) {
			api.creatureStacks
				.resolve( chosenStackSlug, gameSlug )
				.then( setCreateStack )
				.catch( () => {
					setCreateStack( null );
					setCreateError( __( 'Failed to load that creature type. Try choosing it again.', 'beyond-elysium' ) );
				} );
		}
	}, [ isCreateMode, chosenStackSlug ] );

	// Both modes resolve the template once a stack_slug is known, matching the read-only sheet exactly.
	const activeStackSlug = isCreateMode ? chosenStackSlug : store.stackSlug;
	// An NPC gets the NPC sheet, which adds the Storyteller-only sections.
	const isNpc = ! isCreateMode && !! store.character?.is_npc;
	useEffect( () => {
		if ( activeStackSlug ) {
			api
				.templates( gameSlug )
				.resolve( activeStackSlug, isNpc ? 'npc_full' : templateType )
				.then( ( result ) => {
					setTemplate( result );
					setTemplateError( null );
				} )
				.catch( () => {
					// Shows an explicit error instead of silently rendering zero fields in either mode.
					setTemplate( null );
					setTemplateError( __( 'Failed to load the character sheet layout. Try refreshing the page.', 'beyond-elysium' ) );
				} );
		}
	}, [ activeStackSlug, gameSlug, templateType, isNpc ] );

	const activeStack = isCreateMode ? createStack : store.stack;
	// Mirrors CharacterSheet.tsx's own flowing grid layout exactly, so the editor matches the printed sheet.
	const sections = useMemo( () => sortedSections( template ), [ template ] );

	// Unsaved-changes guard on navigation away, via the browser's beforeunload event.
	useEffect( () => {
		const dirty = isCreateMode ? Object.keys( draftSheetData ).length > 0 : store.dirty;
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
			setCreateError( __( 'A name and a creature type are required.', 'beyond-elysium' ) );
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
			} );

			setCreatedId( character.id );
			await store.loadCharacter( character.id, gameSlug );
		} catch ( error ) {
			setCreateError( errorMessage( error ) );
		} finally {
			setCreating( false );
		}
	}

	async function handleSubmit() {
		const result = await store.submitChanges();
		setSubmitResult( result );
	}

	if ( isCreateMode ) {
		return (
			<div className="be-character-editor be-character-editor--create">
				<h2 className="be-character-editor__title">{ __( 'New Character', 'beyond-elysium' ) }</h2>

				{ ( createError || templateError ) && (
					<div className="be-character-editor__error" role="alert">
						{ createError ?? templateError }
					</div>
				) }

				<div className="be-character-editor__field">
					<label htmlFor="be-character-editor-name">{ __( 'Name', 'beyond-elysium' ) }</label>
					<input
						id="be-character-editor-name"
						type="text"
						value={ draftName }
						onChange={ ( e ) => setDraftName( e.target.value ) }
					/>
				</div>

				{ ! stackSlug && (
					<div className="be-character-editor__field">
						<label htmlFor="be-character-editor-stack">{ __( 'Creature Type', 'beyond-elysium' ) }</label>
						<select
							id="be-character-editor-stack"
							value={ chosenStackSlug }
							onChange={ ( e ) => setChosenStackSlug( e.target.value ) }
						>
							<option value="">{ __( 'Choose one…', 'beyond-elysium' ) }</option>
							{ availableStacks.map( ( stack ) => (
								<option key={ stack.slug } value={ stack.slug }>
									{ stack.name }
								</option>
							) ) }
						</select>
					</div>
				) }

				{ activeStack && (
					<div className="be-character-editor__grid">
						{ sections.map( ( section ) => {
							const block = activeStack.blocks[ section.block_slug ];
							if ( ! block ) {
								return null;
							}
							return (
								<div
									className="be-character-editor__section"
									key={ section.block_slug }
									style={ { gridColumn: `span ${ spanFor( section.width ) }` } }
								>
									<h4>{ resolveSectionTitle( section, draftSheetData ) }</h4>
									<BlockEditor
										blockSlug={ section.block_slug }
										sectionType={ block.section_type }
										definition={ block.definition }
										data={ draftSheetData[ section.block_slug ] }
										onChange={ ( slug, data ) => setDraftSheetData( ( prev ) => ( { ...prev, [ slug ]: data } ) ) }
										sheetData={ draftSheetData }
									/>
								</div>
							);
						} ) }
					</div>
				) }

				<button type="button" disabled={ creating || ! chosenStackSlug } onClick={ handleCreate }>
					{ creating ? __( 'Creating…', 'beyond-elysium' ) : __( 'Create Character', 'beyond-elysium' ) }
				</button>
			</div>
		);
	}

	if ( store.loading ) {
		return <div className="be-character-editor__skeleton">{ __( 'Loading character…', 'beyond-elysium' ) }</div>;
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
	const totalCost = store.previewCosts?.results.reduce( ( sum, r ) => sum + r.xp_cost, 0 ) ?? 0;
	const resultingUnspent = store.previewCosts?.running_xp_unspent ?? store.character.xp_unspent;
	const overBudget = ! canManage && resultingUnspent < 0;

	// Seeds the drafts from the loaded character once per character, not on every re-render.
	if ( headerDraftsSeeded.current !== effectiveCharacterId ) {
		biographyDraft.current = store.character.biography ?? '';
		notesDraft.current = store.character.notes ?? '';
		setPortraitUrl( store.character.image_url ?? null );
		headerDraftsSeeded.current = effectiveCharacterId;
	}

	return (
		<div className="be-character-editor">
			{ store.restorableDraft && ! readOnly && (
				<div className="be-character-editor__draft-notice" role="status">
					<p>
						{ sprintf(
							/* translators: %s: when the draft was saved, e.g. "9/11/2026, 3:45:00 PM" */
							__( 'You have unsaved changes from a previous session, saved %s.', 'beyond-elysium' ),
							new Date( store.restorableDraft.savedAt ).toLocaleString()
						) }
					</p>
					<div className="be-character-editor__draft-notice-actions">
						<button type="button" onClick={ () => store.restoreDraft() }>
							{ __( 'Restore', 'beyond-elysium' ) }
						</button>
						<button type="button" onClick={ () => store.dismissDraft() }>
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
						alt={ sprintf( __( '%s portrait', 'beyond-elysium' ), store.character.name ) }
					/>
				) }
				<div>
					<h2>{ store.character.name }</h2>
					{ readOnly && (
						<p className="be-character-editor__readonly-note">
							{ __( 'You can view this sheet but not edit it.', 'beyond-elysium' ) }
						</p>
					) }
					{ ! readOnly && (
						<button type="button" className="be-character-editor__portrait-button" disabled={ savingPortrait } onClick={ pickPortrait }>
							{ savingPortrait
								? __( 'Saving…', 'beyond-elysium' )
								: portraitUrl
								? __( 'Change portrait…', 'beyond-elysium' )
								: __( 'Add a portrait…', 'beyond-elysium' ) }
						</button>
					) }
				</div>
			</div>

			{ ( store.error || templateError ) && (
				<div className="be-character-editor__error" role="alert">
					{ store.error ?? templateError }
				</div>
			) }

			<div className="be-character-editor__section be-character-editor__header-text" key={ `header-text-${ effectiveCharacterId }` }>
				<h4>{ __( 'Background', 'beyond-elysium' ) }</h4>
				<HtmlEditor
					id={ `be-biography-${ effectiveCharacterId }` }
					defaultValue={ biographyDraft.current }
					onChange={ ( html ) => ( biographyDraft.current = html ) }
					readOnly={ readOnly }
				/>

				<h4>{ __( 'Notes', 'beyond-elysium' ) }</h4>
				<HtmlEditor
					id={ `be-notes-${ effectiveCharacterId }` }
					defaultValue={ notesDraft.current }
					onChange={ ( html ) => ( notesDraft.current = html ) }
					readOnly={ readOnly }
				/>

				{ ! readOnly && (
					<div className="be-character-editor__header-text-actions">
						<button type="button" disabled={ savingHeader } onClick={ saveBackgroundAndNotes }>
							{ savingHeader ? __( 'Saving…', 'beyond-elysium' ) : __( 'Save Background & Notes', 'beyond-elysium' ) }
						</button>
						{ headerSaveMessage && <span className="be-character-editor__header-text-status">{ headerSaveMessage }</span> }
						{ headerSaveError && (
							<span className="be-character-editor__error" role="alert">
								{ headerSaveError }
							</span>
						) }
					</div>
				) }
			</div>

			<div className="be-character-editor__grid">
				{ sections.map( ( section ) => {
					const block = store.stack?.blocks[ section.block_slug ];
					if ( ! block ) {
						return null;
					}
					return (
						<div
							className="be-character-editor__section"
							key={ section.block_slug }
							style={ { gridColumn: `span ${ spanFor( section.width ) }` } }
						>
							<h4>{ resolveSectionTitle( section, store.sheetData ) }</h4>
							<BlockEditor
								blockSlug={ section.block_slug }
								sectionType={ block.section_type }
								definition={ block.definition }
								data={ store.sheetData[ section.block_slug ] }
								onChange={ store.setBlockData }
								readOnly={ readOnly }
								sheetData={ store.sheetData }
							/>
						</div>
					);
				} ) }
			</div>

			{ ! readOnly && (
				<div className="be-character-editor__summary">
					<h4>{ sprintf( __( 'Pending Changes (%d)', 'beyond-elysium' ), pendingTotal ) }</h4>
					{ pendingTotal === 0 && <p>{ __( 'No unsaved changes.', 'beyond-elysium' ) }</p> }
					<ul>
						{ store.pendingChanges.map( ( change, i ) => {
							const preview = store.previewCosts?.results[ i ];
							return (
								<li key={ i }>
									{ change.change_type } — { change.category }
									{ preview && (
										<>
											{ ' ' }
											({ preview.xp_cost >= 0 ? '+' : '' }
											{ sprintf( __( '%d XP', 'beyond-elysium' ), preview.xp_cost ) }, { preview.approval_level })
										</>
									) }
								</li>
							);
						} ) }
					</ul>
					{ pendingTotal > 0 && (
						<p className="be-character-editor__totals">
							{ sprintf(
								__( 'Total: %1$s XP — Unspent after: %2$d', 'beyond-elysium' ),
								`${ totalCost >= 0 ? '+' : '' }${ totalCost }`,
								resultingUnspent
							) }
							{ overBudget && (
								<span className="be-character-editor__over-budget">{ __( ' (exceeds available XP)', 'beyond-elysium' ) }</span>
							) }
						</p>
					) }

					<div className="be-character-editor__actions">
						<button type="button" disabled={ pendingTotal === 0 || overBudget } onClick={ handleSubmit } >
							{ store.saving ? __( 'Submitting…', 'beyond-elysium' ) : __( 'Submit Changes', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							disabled={ pendingTotal === 0 }
							onClick={ () => setConfirmingReset( true ) }
						>
							{ __( 'Discard', 'beyond-elysium' ) }
						</button>
					</div>

					{ submitResult && submitResult.failed.length > 0 && (
						<p className="be-character-editor__error" role="alert">
							{ sprintf(
								_n(
									'%1$d of %2$d changes were saved. %3$d did not - review and submit again to retry it.',
									'%1$d of %2$d changes were saved. %3$d did not - review and submit again to retry them.',
									submitResult.failed.length,
									'beyond-elysium'
								),
								submitResult.submitted.length,
								submitResult.submitted.length + submitResult.failed.length,
								submitResult.failed.length
							) }
						</p>
					) }

					{ submitResult && submitResult.pending.length > 0 && (
						<p className="be-character-editor__pending-notice" role="status">
							{ sprintf(
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
					// Navigates back to the sheet so the discard's effect is visible, not just a silent state reset.
					store.reset();
					window.location.href = `${ window.location.origin }/character-sheet/?character_id=${ characterId }&game_slug=${ encodeURIComponent( gameSlug ) }`;
				} }
				onCancel={ () => setConfirmingReset( false ) }
			/>
		</div>
	);
}

export default CharacterEditor;
