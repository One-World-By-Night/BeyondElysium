/**
 * Zustand store powering the character sheet editor. Holds the
 * loaded character, its stack definition, and the in-progress
 * sheet edits, and exposes actions to load a character, stage
 * edits, compute and submit changes, and manage the local
 * autosave draft.
 */
import { create } from 'zustand';
import api from '../api/client';
import { computeChanges as computeChangesPure } from '../lib/computeChanges';
import { clearDraft, draftDiffersFrom, loadDraft, saveDraft, type StoredDraft } from '../lib/draftStorage';
import type { ResolvedStack } from '../types';
import type { Character, ChangeRequest, PreviewChangesResponse, SheetData } from '../types/character';

/**
 * The minimal shape of an apiFetch rejection this store knows how
 * to read a message from, matching WordPress's api-fetch
 * package's own error shape.
 */
interface RestError {
	message?: string;
	data?: { status?: number };
}

/**
 * Extracts a human-readable message from a caught error, falling
 * back to a generic message when the error does not carry one.
 */
function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null ) {
		const restError = error as RestError;
		if ( restError.message ) {
			return restError.message;
		}
	}
	return 'Something went wrong.';
}

/**
 * Deep-clones a JSON-serializable value via a serialize/parse
 * round trip, so the copy shares no object references with the
 * original.
 */
function deepClone<T>( value: T ): T {
	return JSON.parse( JSON.stringify( value ) );
}

/**
 * The outcome of submitting a batch of pending changes: which
 * requests were actually submitted, which failed outright, and
 * which succeeded but still need Storyteller review before they
 * take effect.
 */
export interface SubmitResult {
	submitted: ChangeRequest[];
	/** Every change that failed to submit, not just the first one encountered. */
	failed: ChangeRequest[];
	/** Submitted successfully but landed pending review rather than being applied immediately. */
	pending: ChangeRequest[];
}

/**
 * The full state and actions exposed by the character editor
 * store: the currently loaded character and its sheet data, the
 * diff between the original and edited sheet, submission and
 * draft-recovery status, and the actions that drive all of it.
 */
interface CharacterEditorState {
	characterId: number | null;
	gameSlug: string | null;
	stackSlug: string | null;
	stack: ResolvedStack | null;
	/** The loaded character record, used for display fields such as name and xp_unspent. */
	character: Character | null;
	sheetData: SheetData;
	originalSheetData: SheetData;
	pendingChanges: ChangeRequest[];
	previewCosts: PreviewChangesResponse | null;
	/** Changes submitted this session that are still awaiting Storyteller approval. */
	submittedChanges: ChangeRequest[];
	dirty: boolean;
	loading: boolean;
	saving: boolean;
	error: string | null;
	/** A locally saved draft that differs from the server's data, offered to the player to restore or dismiss. */
	restorableDraft: StoredDraft | null;

	/** Loads a character and its resolved stack, and checks for a restorable local draft. */
	loadCharacter: ( id: number, gameSlug: string ) => Promise<void>;
	/** Stages an edit to one sheet block and saves it to the local autosave draft. */
	setBlockData: ( blockSlug: string, data: unknown ) => void;
	/** Recomputes the pending change list from the diff between the original and edited sheet data. */
	computeChanges: () => ChangeRequest[];
	/** Submits every pending change to the server and updates local state with the outcome. */
	submitChanges: () => Promise<SubmitResult>;
	/** Discards unsaved edits, reverting sheetData back to originalSheetData. */
	reset: () => void;
	/** Applies the restorable draft to sheetData and clears it. */
	restoreDraft: () => void;
	/** Discards the restorable draft without applying it. */
	dismissDraft: () => void;
}

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

/**
 * The character editor's Zustand store hook. Initializes all
 * state to empty/idle values and wires up each action's
 * implementation against that state.
 */
export const useCharacterEditorStore = create<CharacterEditorState>( ( set, get ) => ( {
	characterId: null,
	gameSlug: null,
	stackSlug: null,
	stack: null,
	character: null,
	sheetData: {},
	originalSheetData: {},
	pendingChanges: [],
	previewCosts: null,
	submittedChanges: [],
	dirty: false,
	loading: false,
	saving: false,
	error: null,
	restorableDraft: null,

	/**
	 * Loads a character and its resolved creature stack, resets
	 * every editing field back to a clean baseline, and checks for
	 * a local draft worth offering the player to restore.
	 */
	loadCharacter: async ( id, gameSlug ) => {
		set( { loading: true, error: null } );
		try {
			const character = await api.characters( gameSlug ).get( id );
			const stack = await api.creatureStacks.resolve( character.stack_slug, gameSlug );
			// Cloned separately so sheetData and originalSheetData never share references.
			const sheet = deepClone( character.sheet_data );

			// Only offered when it actually differs from what the server just returned.
			const draft = loadDraft( id );
			const restorableDraft = draft && draftDiffersFrom( draft.sheetData, sheet ) ? draft : null;

			set( {
				characterId: id,
				gameSlug,
				stackSlug: character.stack_slug,
				stack,
				character,
				sheetData: sheet,
				originalSheetData: deepClone( sheet ),
				pendingChanges: [],
				previewCosts: null,
				submittedChanges: [],
				dirty: false,
				loading: false,
				restorableDraft,
			} );
		} catch ( error ) {
			set( { loading: false, error: errorMessage( error ) } );
		}
	},

	/**
	 * Stages an edit to a single sheet block, marks the sheet
	 * dirty, saves the change to the local autosave draft
	 * immediately, and debounces a server round trip to recompute
	 * the pending changes' previewed cost.
	 */
	setBlockData: ( blockSlug, data ) => {
		set( ( state ) => ( {
			sheetData: { ...state.sheetData, [ blockSlug ]: data },
			dirty: true,
		} ) );

		// Saved on every edit, not debounced, so a crash or closed tab loses nothing.
		const { characterId, sheetData } = get();
		if ( characterId ) {
			saveDraft( characterId, sheetData );
		}

		if ( debounceTimer ) {
			clearTimeout( debounceTimer );
		}
		debounceTimer = setTimeout( () => {
			const changes = get().computeChanges();
			const { characterId, gameSlug } = get();
			if ( ! characterId || ! gameSlug || changes.length === 0 ) {
				set( { previewCosts: null } );
				return;
			}
			api
				.characters( gameSlug )
				.previewChanges( characterId, changes )
				.then( ( previewCosts ) => set( { previewCosts } ) )
				.catch( ( error ) => set( { error: errorMessage( error ) } ) );
		}, 500 );
	},

	/**
	 * Recomputes the pending change list by diffing sheetData
	 * against originalSheetData for every block in the loaded
	 * stack, stores the result, and returns it.
	 */
	computeChanges: () => {
		const { originalSheetData, sheetData, stack } = get();
		const changes = computeChangesPure( originalSheetData, sheetData, stack?.blocks ?? {} );
		set( { pendingChanges: changes } );
		return changes;
	},

	/**
	 * Submits every currently pending change to the server, one
	 * request per change, continuing through the whole batch even
	 * if some requests fail. Updates originalSheetData to match
	 * for any category that fully succeeded, and manages the local
	 * draft and dirty state based on what is left unresolved.
	 */
	submitChanges: async () => {
		// Recomputed synchronously so a submit racing the debounce sends the on-screen diff.
		const changes = get().computeChanges();
		const { characterId, gameSlug } = get();

		if ( ! characterId || ! gameSlug ) {
			return { submitted: [], failed: changes, pending: [] };
		}

		set( { saving: true, error: null } );

		// Every queued change is attempted, even after an earlier one in the batch fails.
		const submitted: ChangeRequest[] = [];
		const failed: ChangeRequest[] = [];
		const pending: ChangeRequest[] = [];
		let lastError: unknown = null;
		for ( const change of changes ) {
			try {
				const created = await api.changes( gameSlug ).create( characterId, change );
				submitted.push( change );
				// A created change only reaches sheet_data once approved; otherwise it stays pending.
				if ( created.status !== 'approved' ) {
					pending.push( change );
				}
			} catch ( error ) {
				failed.push( change );
				lastError = error;
			}
		}

		// Only a category with nothing failed or still pending is safe to baseline as saved.
		const failedCategories = new Set( failed.map( ( change ) => change.category ) );
		const pendingCategories = new Set( pending.map( ( change ) => change.category ) );
		const succeededCategories = new Set(
			submitted
				.map( ( change ) => change.category )
				.filter( ( category ) => ! failedCategories.has( category ) && ! pendingCategories.has( category ) )
		);

		set( ( state ) => {
			const nextOriginal = { ...state.originalSheetData };
			for ( const category of succeededCategories ) {
				nextOriginal[ category ] = deepClone( state.sheetData[ category ] );
			}
			return {
				saving: false,
				error: failed.length > 0 ? errorMessage( lastError ) : null,
				originalSheetData: nextOriginal,
				previewCosts: null,
				submittedChanges: [ ...state.submittedChanges, ...submitted ],
			};
		} );

		// Still-pending categories stay live in the diff, so they keep showing as unsaved.
		const stillPending = get().computeChanges();
		const isDirty = stillPending.length > 0;
		set( { dirty: isDirty } );

		// The draft is only cleared once nothing is left unresolved.
		if ( ! isDirty && characterId ) {
			clearDraft( characterId );
		}

		return { submitted, failed, pending };
	},

	/**
	 * Discards all unsaved edits, reverting sheetData back to
	 * originalSheetData, clearing the pending diff and preview
	 * cost, and clearing the local autosave draft.
	 */
	reset: () => {
		const { characterId } = get();
		if ( characterId ) {
			clearDraft( characterId );
		}
		set( ( state ) => ( {
			sheetData: deepClone( state.originalSheetData ),
			pendingChanges: [],
			previewCosts: null,
			dirty: false,
			restorableDraft: null,
		} ) );
	},

	/**
	 * Applies the offered local draft to sheetData, marks the
	 * sheet dirty, clears the restorable draft, and recomputes the
	 * pending change list against the restored data.
	 */
	restoreDraft: () => {
		const { restorableDraft } = get();
		if ( ! restorableDraft ) {
			return;
		}
		set( { sheetData: deepClone( restorableDraft.sheetData ), dirty: true, restorableDraft: null } );
		get().computeChanges();
	},

	/**
	 * Discards the offered local draft without applying it,
	 * deleting it from local storage and clearing it from state.
	 */
	dismissDraft: () => {
		const { characterId } = get();
		if ( characterId ) {
			clearDraft( characterId );
		}
		set( { restorableDraft: null } );
	},
} ) );

export default useCharacterEditorStore;
