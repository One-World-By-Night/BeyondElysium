/**
 * Zustand store powering the character sheet editor.
 */
import { create, type StoreApi } from 'zustand';
import api from '../api/client';
import { computeChanges as computeChangesPure } from '../lib/computeChanges';
import { removeLine } from '../lib/cartRemove';
import { clearCart, loadCart, restoreCart, saveCart } from '../lib/cartStorage';
import {
	clearDraft,
	draftDiffersFrom,
	loadDraft,
	type StoredDraft,
} from '../lib/draftStorage';
import { errorMessage } from '../lib/errorMessage';
import type { ResolvedStack, SchemaBlock } from '../types';
import type {
	Character,
	ChangeRequest,
	PreviewChangesResponse,
	SheetData,
} from '../types/character';

/**
 * Deep-clones a JSON-serializable value via a serialize/parse round trip.
 */
function deepClone< T >( value: T ): T {
	return JSON.parse( JSON.stringify( value ) );
}

/**
 * The outcome of submitting a batch of pending changes: which requests were actually submitted.
 */
export interface SubmitResult {
	submitted: ChangeRequest[];
	/**
	 * Every change that failed to submit.
	 */
	failed: ChangeRequest[];
	/**
	 * Submitted successfully but landed pending review.
	 */
	pending: ChangeRequest[];
	/**
	 * How many of the submitted changes the server applied at once.
	 */
	applied: number;
	/**
	 * How many wait for a Storyteller, and the XP they hold.
	 */
	waiting: number;
	held: number;
}

/**
 * The full state and actions exposed by the character editor store.
 */
interface CharacterEditorState {
	characterId: number | null;
	gameSlug: string | null;
	stackSlug: string | null;
	stack: ResolvedStack | null;
	/**
	 * The loaded character record, used for display fields such as name and xp_unspent.
	 */
	character: Character | null;
	sheetData: SheetData;
	originalSheetData: SheetData;
	/**
	 * Blocks a Storyteller has added to a creature type that allows any block, shown as a section before they hold anything.
	 */
	addedBlocks: string[];
	pendingChanges: ChangeRequest[];
	previewCosts: PreviewChangesResponse | null;
	/**
	 * Changes submitted this session that are still awaiting Storyteller approval.
	 */
	submittedChanges: ChangeRequest[];
	dirty: boolean;
	loading: boolean;
	saving: boolean;
	error: string | null;
	/**
	 * An older whole-sheet draft that differs from the server's data, offered to the player once to restore or dismiss.
	 */
	restorableDraft: StoredDraft | null;
	/**
	 * Blocks the cart held that the server changed since they were edited, named so the player can add them again.
	 */
	droppedBlocks: string[];
	/**
	 * Whether the Review dialog is open, from the meter, the header chip or the trait panel.
	 */
	reviewOpen: boolean;
	/**
	 * What the last Submit did, for the confirmation Review shows; null until one is sent.
	 */
	lastSubmit: SubmitResult | null;

	/**
	 * Loads a character and its resolved stack, and checks for a restorable local draft.
	 */
	loadCharacter: ( id: number, gameSlug: string ) => Promise< void >;
	/**
	 * The load itself, which loadCharacter() shares between callers asking for the same character.
	 */
	loadCharacterNow: ( id: number, gameSlug: string ) => Promise< void >;
	/**
	 * Stages an edit to one sheet block and saves it to the local autosave draft.
	 */
	setBlockData: ( blockSlug: string, data: unknown ) => void;
	/**
	 * Opens an empty section for a schema block the creature type does not list, so the first entry can be added to it.
	 */
	addSection: ( block: SchemaBlock ) => void;
	/**
	 * Recomputes the pending change list from the diff between the original and edited sheet data.
	 */
	computeChanges: () => ChangeRequest[];
	/**
	 * Submits every pending change to the server and updates local state with the outcome. A note for the Storyteller
	 * goes with every change in the set.
	 */
	submitChanges: ( note?: string ) => Promise< SubmitResult >;
	/**
	 * Takes one line out of the cart, putting the rows it changed back as the sheet holds them.
	 */
	removeLine: ( index: number ) => void;
	openReview: () => void;
	closeReview: () => void;
	/**
	 * Discards unsaved edits, reverting sheetData back to originalSheetData.
	 */
	reset: () => void;
	/**
	 * Applies the restorable draft to sheetData and clears it.
	 */
	restoreDraft: () => void;
	/**
	 * Discards the restorable draft without applying it.
	 */
	dismissDraft: () => void;
	/**
	 * Stops naming the blocks the server changed since the cart held them.
	 */
	dismissDropped: () => void;
}

let debounceTimer: ReturnType< typeof setTimeout > | null = null;

/**
 * The load that is under way, so a second request for the same character shares it.
 */
let loading: { key: string; promise: Promise< void > } | null = null;

/**
 * Counts cost-preview requests.
 */
let previewRequest = 0;

/**
 * Asks the server what the pending changes cost, or clears the preview when nothing is pending.
 */
function refreshPreview(
	get: StoreApi< CharacterEditorState >[ 'getState' ],
	set: StoreApi< CharacterEditorState >[ 'setState' ]
): void {
	const request = ++previewRequest;
	const changes = get().computeChanges();
	const { characterId, gameSlug } = get();
	if ( ! characterId || ! gameSlug || changes.length === 0 ) {
		set( { previewCosts: null } );
		return;
	}
	api.characters( gameSlug )
		.previewChanges( characterId, changes )
		.then( ( previewCosts ) => {
			if ( request === previewRequest ) {
				set( { previewCosts } );
			}
		} )
		.catch( ( error ) => {
			if ( request === previewRequest ) {
				set( {
					error: errorMessage( error, 'Something went wrong.' ),
				} );
			}
		} );
}

/**
 * The character editor's Zustand store hook.
 */
export const useCharacterEditorStore = create< CharacterEditorState >(
	( set, get ) => ( {
		characterId: null,
		gameSlug: null,
		stackSlug: null,
		stack: null,
		character: null,
		sheetData: {},
		originalSheetData: {},
		addedBlocks: [],
		pendingChanges: [],
		previewCosts: null,
		submittedChanges: [],
		dirty: false,
		loading: false,
		saving: false,
		error: null,
		restorableDraft: null,
		droppedBlocks: [],
		reviewOpen: false,
		lastSubmit: null,

		/**
		 * Loads a character and its resolved creature stack, resets every editing field back to a clean baseline, brings
		 * back the cart kept on this device for every block the server still holds as it was edited from, and checks for an
		 * older whole-sheet draft worth offering the player to restore.
		 */
		loadCharacter: ( id, gameSlug ) => {
			const key = `${ gameSlug }:${ id }`;
			if ( loading?.key === key ) {
				return loading.promise;
			}
			const promise = get()
				.loadCharacterNow( id, gameSlug )
				.finally( () => {
					if ( loading?.promise === promise ) {
						loading = null;
					}
				} );
			loading = { key, promise };
			return promise;
		},

		loadCharacterNow: async ( id, gameSlug ) => {
			// A cost preview still on its way belongs to the sheet being replaced.
			previewRequest++;
			set( { loading: true, error: null } );
			try {
				const character = await api.characters( gameSlug ).get( id );
				const stack = await api.creatureStacks.resolve(
					character.stack_slug,
					gameSlug,
					undefined,
					id
				);
				// Cloned separately so sheetData and originalSheetData never share references.
				const sheet = deepClone( character.sheet_data );

				const cart = loadCart( id );
				const kept = cart
					? restoreCart( cart, sheet )
					: { sheet: deepClone( sheet ), restored: [], dropped: [] };

				const draft = cart ? null : loadDraft( id );
				const restorableDraft =
					draft && draftDiffersFrom( draft.sheetData, sheet )
						? draft
						: null;

				set( {
					characterId: id,
					gameSlug,
					stackSlug: character.stack_slug,
					stack,
					character,
					sheetData: kept.sheet,
					originalSheetData: deepClone( sheet ),
					addedBlocks: [],
					pendingChanges: [],
					previewCosts: null,
					submittedChanges: [],
					dirty: kept.restored.length > 0,
					loading: false,
					restorableDraft,
					droppedBlocks: kept.dropped,
					reviewOpen: false,
					lastSubmit: null,
				} );

				if ( kept.restored.length > 0 ) {
					get().computeChanges();
					refreshPreview( get, set );
				}
				if ( kept.dropped.length > 0 ) {
					saveCart( id, sheet, kept.sheet );
				}
			} catch ( error ) {
				set( {
					loading: false,
					error: errorMessage( error, 'Something went wrong.' ),
				} );
			}
		},

		/**
		 * Stages an edit to a single sheet block, marks the sheet dirty, saves the change to the local autosave draft
		 * immediately, and debounces a server round trip to recompute the pending changes' previewed cost.
		 */
		setBlockData: ( blockSlug, data ) => {
			set( ( state ) => ( {
				sheetData: { ...state.sheetData, [ blockSlug ]: data },
				dirty: true,
			} ) );

			// Saved and diffed on every edit, not debounced; only the server's pricing waits.
			const { characterId, originalSheetData, sheetData } = get();
			if ( characterId ) {
				saveCart( characterId, originalSheetData, sheetData );
			}
			get().computeChanges();

			if ( debounceTimer ) {
				clearTimeout( debounceTimer );
			}
			debounceTimer = setTimeout( () => refreshPreview( get, set ), 500 );
		},

		/**
		 * Adds a schema block's definition to the loaded stack and lists it among the sections shown.
		 */
		addSection: ( block ) => {
			set( ( state ) => {
				if ( ! state.stack ) {
					return {};
				}
				return {
					stack: {
						...state.stack,
						blocks: {
							...state.stack.blocks,
							[ block.slug ]: block,
						},
					},
					addedBlocks: state.addedBlocks.includes( block.slug )
						? state.addedBlocks
						: [ ...state.addedBlocks, block.slug ],
				};
			} );
		},

		/**
		 * Recomputes the pending change list by diffing sheetData against originalSheetData for every block in the loaded
		 * stack, stores the result, and returns it.
		 */
		computeChanges: () => {
			const { originalSheetData, sheetData, stack } = get();
			const changes = computeChangesPure(
				originalSheetData,
				sheetData,
				stack?.blocks ?? {}
			);
			set( { pendingChanges: changes } );
			return changes;
		},

		/**
		 * Submits every currently pending change to the server together, in one call, under one shared submission id - a
		 * chronicle with the removal/lowering switch on needs the whole set to wait together when any of it is caught.
		 */
		submitChanges: async ( note ) => {
			const nothing = { applied: 0, waiting: 0, held: 0 };
			if ( get().saving ) {
				return { submitted: [], failed: [], pending: [], ...nothing };
			}

			const written = ( note ?? '' ).trim();
			const changes = get()
				.computeChanges()
				.map( ( change ) =>
					written === '' ? change : { ...change, notes: written }
				);
			const { characterId, gameSlug, sheetData } = get();

			if ( ! characterId || ! gameSlug ) {
				return {
					submitted: [],
					failed: changes,
					pending: [],
					...nothing,
				};
			}

			// The sheet stays editable while the request is out.
			const sent: SheetData = {};
			for ( const change of changes ) {
				sent[ change.category ] = deepClone(
					sheetData[ change.category ]
				);
			}

			// A cost preview already on its way prices the diff being sent, not what will be left.
			previewRequest++;
			set( { saving: true, error: null } );

			// The whole set succeeds or fails together - a validation or pricing failure on any one change leaves
			// nothing submitted, matching the server's own one-transaction contract.
			const submitted: ChangeRequest[] = [];
			const failed: ChangeRequest[] = [];
			const pending: ChangeRequest[] = [];
			let lastError: unknown = null;
			let applied = 0;
			let held = 0;
			if ( changes.length > 0 ) {
				try {
					const result = await api
						.changes( gameSlug )
						.submitSet( characterId, changes );
					changes.forEach( ( change, index ) => {
						submitted.push( change );
						const created = result.changes[ index ];
						// A created change only reaches sheet_data once approved.
						if ( ! created || created.status !== 'approved' ) {
							pending.push( change );
							held += Math.max(
								0,
								Number( created?.xp_cost ?? 0 )
							);
						} else {
							applied++;
						}
					} );
				} catch ( error ) {
					failed.push( ...changes );
					lastError = error;
				}
			}

			// What the server holds now: a change that waits is an order, not an edit, so the cart rebases on it.
			let fresh: Character | null = null;
			if ( submitted.length > 0 && failed.length === 0 ) {
				try {
					fresh = await api.characters( gameSlug ).get( characterId );
				} catch {
					fresh = null;
				}
			}

			// Only a category with nothing failed or still pending is safe to baseline as saved.
			const failedCategories = new Set(
				failed.map( ( change ) => change.category )
			);
			const pendingCategories = new Set(
				pending.map( ( change ) => change.category )
			);
			const succeededCategories = new Set(
				submitted
					.map( ( change ) => change.category )
					.filter(
						( category ) =>
							! failedCategories.has( category ) &&
							! pendingCategories.has( category )
					)
			);

			const outcome: SubmitResult = {
				submitted,
				failed,
				pending,
				applied,
				waiting: pending.length,
				held,
			};

			// Another character opened meanwhile: nothing here describes its sheet.
			if ( get().characterId !== characterId ) {
				set( { saving: false } );
				return outcome;
			}

			set( ( state ) => {
				let nextOriginal = { ...state.originalSheetData };
				let nextSheet = state.sheetData;
				if ( fresh ) {
					// Each block sent and not edited since takes the server's value; one edited meanwhile stays edited.
					nextOriginal = deepClone( fresh.sheet_data );
					nextSheet = { ...state.sheetData };
					for ( const category of Object.keys( sent ) ) {
						if (
							draftDiffersFrom(
								{ v: state.sheetData[ category ] },
								{ v: sent[ category ] }
							)
						) {
							continue;
						}
						if ( fresh.sheet_data[ category ] === undefined ) {
							delete nextSheet[ category ];
						} else {
							nextSheet[ category ] = deepClone(
								fresh.sheet_data[ category ]
							);
						}
					}
				} else {
					for ( const category of succeededCategories ) {
						nextOriginal[ category ] = deepClone(
							sent[ category ]
						);
					}
				}
				return {
					saving: false,
					lastSubmit: outcome,
					...( fresh ? { character: fresh } : {} ),
					sheetData: nextSheet,
					error:
						failed.length > 0
							? errorMessage( lastError, 'Something went wrong.' )
							: null,
					originalSheetData: nextOriginal,
					previewCosts: null,
					submittedChanges: [
						...state.submittedChanges,
						...submitted,
					],
				};
			} );

			// Without the server's copy, still-pending categories stay live in the diff and keep showing as unsaved.
			const stillPending = get().computeChanges();
			const isDirty = stillPending.length > 0;
			set( { dirty: isDirty } );

			if ( characterId ) {
				if ( isDirty ) {
					// What is left in the cart stays kept.
					const { originalSheetData, sheetData: left } = get();
					saveCart( characterId, originalSheetData, left );
				} else {
					clearCart( characterId );
					clearDraft( characterId );
				}
			}
			if ( isDirty ) {
				// What is left - a failed or pending change, or an edit made while sending - is priced afresh.
				refreshPreview( get, set );
			}

			return outcome;
		},

		/**
		 * Takes one line out of the cart: the rows it changed go back as the sheet holds them, and the rest of the cart
		 * is kept and priced again.
		 */
		removeLine: ( index ) => {
			const { originalSheetData, sheetData, stack, characterId } = get();
			const { sheet } = removeLine(
				originalSheetData,
				sheetData,
				stack?.blocks ?? {},
				index
			);
			set( { sheetData: sheet } );
			if ( characterId ) {
				saveCart( characterId, originalSheetData, sheet );
			}
			const left = get().computeChanges();
			set( { dirty: left.length > 0 } );
			refreshPreview( get, set );
		},

		openReview: () => set( { reviewOpen: true } ),

		closeReview: () => set( { reviewOpen: false, lastSubmit: null } ),

		/**
		 * Discards all unsaved edits, reverting sheetData back to originalSheetData, clearing the pending diff and preview
		 * cost, and clearing the local autosave draft.
		 */
		reset: () => {
			const { characterId } = get();
			if ( characterId ) {
				clearCart( characterId );
				clearDraft( characterId );
			}
			set( ( state ) => ( {
				sheetData: deepClone( state.originalSheetData ),
				pendingChanges: [],
				previewCosts: null,
				dirty: false,
				restorableDraft: null,
				droppedBlocks: [],
				reviewOpen: false,
			} ) );
		},

		/**
		 * Applies the offered local draft to sheetData, marks the sheet dirty, clears the restorable draft, and recomputes
		 * the pending change list against the restored data.
		 */
		restoreDraft: () => {
			const { restorableDraft } = get();
			if ( ! restorableDraft ) {
				return;
			}
			set( {
				sheetData: deepClone( restorableDraft.sheetData ),
				dirty: true,
				restorableDraft: null,
			} );
			const { characterId, originalSheetData, sheetData } = get();
			if ( characterId ) {
				saveCart( characterId, originalSheetData, sheetData );
				clearDraft( characterId );
			}
			get().computeChanges();
			refreshPreview( get, set );
		},

		/**
		 * Discards the offered local draft without applying it, deleting it from local storage and clearing it from state.
		 */
		dismissDraft: () => {
			const { characterId } = get();
			if ( characterId ) {
				clearDraft( characterId );
			}
			set( { restorableDraft: null } );
		},

		/**
		 * Stops naming the blocks the server changed since the cart held them.
		 */
		dismissDropped: () => {
			set( { droppedBlocks: [] } );
		},
	} )
);

export default useCharacterEditorStore;
