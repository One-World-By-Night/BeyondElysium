/**
 * A Zustand store is a plain state container - `.getState()` exercises it directly
 * without React or a DOM, so it fits this project's pure-logic unit-test convention the
 * same way `src/lib/*.test.ts` does.
 */
jest.mock( '../api/client', () => ( {
	__esModule: true,
	default: {
		characters: jest.fn(),
		creatureStacks: { resolve: jest.fn() },
		changes: jest.fn(),
	},
} ) );

import api from '../api/client';
import { loadDraft, saveDraft } from '../lib/draftStorage';
import { useCharacterEditorStore } from './characterEditorStore';

const mockedApi = api as jest.Mocked<typeof api>;

function resetStore() {
	useCharacterEditorStore.setState( {
		characterId: 1,
		gameSlug: 'kony',
		stackSlug: 'vampire',
		stack: { stack: {} as never, blocks: {} },
		character: null,
		sheetData: { disciplines: [ { name: 'Celerity', count: 1 } ] },
		originalSheetData: { disciplines: [] },
		pendingChanges: [],
		previewCosts: null,
		submittedChanges: [],
		dirty: true,
		loading: false,
		saving: false,
		error: null,
		restorableDraft: null,
	} );
}

describe( 'characterEditorStore.reset', () => {
	beforeEach( resetStore );

	it( 'restores sheetData from originalSheetData and clears dirty', () => {
		useCharacterEditorStore.getState().reset();
		const state = useCharacterEditorStore.getState();

		expect( state.sheetData ).toEqual( { disciplines: [] } );
		expect( state.dirty ).toBe( false );
	} );
} );

describe( 'characterEditorStore.submitChanges', () => {
	beforeEach( () => {
		resetStore();
		// Two blocks so computeChanges produces two changes to submit in sequence.
		useCharacterEditorStore.setState( {
			stack: {
				stack: {} as never,
				blocks: {
					disciplines: { section_type: 'trait_list', definition: { items: [] } } as never,
					virtues: { section_type: 'trait_list', definition: { items: [] } } as never,
				},
			},
			sheetData: {
				disciplines: [ { name: 'Celerity', count: 1 } ],
				virtues: [ { name: 'Conscience', count: 1 } ],
			},
			originalSheetData: { disciplines: [], virtues: [] },
		} );
	} );

	it( 'attempts every change even after an earlier one fails (workflow-0.9.md Step 0b), baselining only what succeeded', async () => {
		const create = jest
			.fn()
			.mockResolvedValueOnce( { id: 1, status: 'approved' } )
			.mockRejectedValueOnce( { message: 'server exploded' } );
		mockedApi.changes = jest.fn().mockReturnValue( { create } ) as never;

		const result = await useCharacterEditorStore.getState().submitChanges();

		// Both queued changes are attempted - the old behavior stopped after the first
		// and never called create() for the second at all.
		expect( create ).toHaveBeenCalledTimes( 2 );
		expect( result.submitted ).toHaveLength( 1 );
		expect( result.failed ).toHaveLength( 1 );

		const state = useCharacterEditorStore.getState();
		expect( state.saving ).toBe( false );
		expect( state.error ).toBe( 'server exploded' );
		// The failed block's edit is still live to retry.
		expect( state.dirty ).toBe( true );

		const failedCategory = result.failed[ 0 ].category;
		const succeededCategory = result.submitted[ 0 ].category;
		// The block that failed keeps its original (pre-edit) baseline, so it still shows
		// as a pending diff; the block that succeeded is baselined so it does NOT.
		expect( state.originalSheetData[ failedCategory ] ).not.toEqual( state.sheetData[ failedCategory ] );
		expect( state.originalSheetData[ succeededCategory ] ).toEqual( state.sheetData[ succeededCategory ] );
	} );

	it( 'does not resubmit an already-succeeded block on a retry after a partial failure', async () => {
		const create = jest
			.fn()
			.mockResolvedValueOnce( { id: 1, status: 'approved' } )
			.mockRejectedValueOnce( { message: 'server exploded' } );
		mockedApi.changes = jest.fn().mockReturnValue( { create } ) as never;

		await useCharacterEditorStore.getState().submitChanges();
		create.mockClear();
		create.mockResolvedValue( { id: 2, status: 'approved' } );

		const retry = await useCharacterEditorStore.getState().submitChanges();

		// Only the one still-pending (previously failed) block is submitted again.
		expect( create ).toHaveBeenCalledTimes( 1 );
		expect( retry.submitted ).toHaveLength( 1 );
		expect( retry.failed ).toHaveLength( 0 );
	} );

	it( 'resets the baseline and reports full success when every change lands', async () => {
		const create = jest.fn().mockResolvedValue( { id: 1, status: 'approved' } );
		mockedApi.changes = jest.fn().mockReturnValue( { create } ) as never;

		const result = await useCharacterEditorStore.getState().submitChanges();

		expect( result.failed ).toHaveLength( 0 );
		expect( result.pending ).toHaveLength( 0 );
		expect( result.submitted ).toHaveLength( 2 );

		const state = useCharacterEditorStore.getState();
		expect( state.dirty ).toBe( false );
		expect( state.pendingChanges ).toEqual( [] );
		expect( state.submittedChanges ).toHaveLength( 2 );
		expect( state.originalSheetData ).toEqual( state.sheetData );
	} );

	it( 'does NOT baseline a change that submitted successfully but landed pending review (real bug, user report 2026-09-11: a discipline edit appeared saved, then reverted the next time the character reloaded)', async () => {
		const create = jest.fn().mockResolvedValue( { id: 1, status: 'pending' } );
		mockedApi.changes = jest.fn().mockReturnValue( { create } ) as never;

		const result = await useCharacterEditorStore.getState().submitChanges();

		expect( result.failed ).toHaveLength( 0 );
		expect( result.submitted ).toHaveLength( 2 );
		expect( result.pending ).toHaveLength( 2 );

		const state = useCharacterEditorStore.getState();
		// The draft must still differ from the baseline - if these ever match, a fresh
		// loadCharacter() (which reads the server's real, still-unapplied sheet_data) would
		// silently overwrite the user's edit with no explanation, exactly the reported bug.
		expect( state.originalSheetData ).not.toEqual( state.sheetData );
		expect( state.dirty ).toBe( true );
		expect( state.pendingChanges ).toHaveLength( 2 );
	} );

	it( 'baselines only the categories that were actually approved when a submission batch is mixed', async () => {
		const create = jest
			.fn()
			.mockResolvedValueOnce( { id: 1, status: 'approved' } )
			.mockResolvedValueOnce( { id: 2, status: 'pending' } );
		mockedApi.changes = jest.fn().mockReturnValue( { create } ) as never;

		const result = await useCharacterEditorStore.getState().submitChanges();
		const state = useCharacterEditorStore.getState();

		expect( result.pending ).toHaveLength( 1 );
		const approvedCategory = result.submitted.find(
			( c ) => ! result.pending.includes( c )
		)!.category;
		const pendingCategory = result.pending[ 0 ].category;

		expect( state.originalSheetData[ approvedCategory ] ).toEqual( state.sheetData[ approvedCategory ] );
		expect( state.originalSheetData[ pendingCategory ] ).not.toEqual( state.sheetData[ pendingCategory ] );
	} );
} );

/**
 * User request, 2026-09-11: "During sheet entry etc we need an auto save draft feature."
 */
describe( 'characterEditorStore draft autosave', () => {
	beforeEach( () => {
		window.localStorage.clear();
		resetStore();
		mockedApi.characters = jest.fn().mockReturnValue( {
			get: jest.fn().mockResolvedValue( {
				id: 1,
				stack_slug: 'vampire',
				sheet_data: { disciplines: [] },
			} ),
		} ) as never;
		mockedApi.creatureStacks = { resolve: jest.fn().mockResolvedValue( { stack: {}, blocks: {} } ) } as never;
	} );

	it( 'saves a draft to localStorage on every setBlockData call', () => {
		useCharacterEditorStore.getState().setBlockData( 'disciplines', [ { name: 'Fortitude', count: 2 } ] );

		const draft = loadDraft( 1 );
		expect( draft?.sheetData ).toEqual( {
			disciplines: [ { name: 'Fortitude', count: 2 } ],
		} );
	} );

	it( 'offers a draft on loadCharacter() when it genuinely differs from the server value', async () => {
		saveDraft( 1, { disciplines: [ { name: 'Obfuscate', count: 1 } ] } );

		await useCharacterEditorStore.getState().loadCharacter( 1, 'kony' );

		const state = useCharacterEditorStore.getState();
		expect( state.restorableDraft?.sheetData ).toEqual( { disciplines: [ { name: 'Obfuscate', count: 1 } ] } );
		// The draft is only ever offered, never auto-applied - the live sheetData still
		// matches what the server actually returned until the player chooses to restore it.
		expect( state.sheetData ).toEqual( { disciplines: [] } );
	} );

	it( 'does not offer a draft that matches what the server already returned', async () => {
		saveDraft( 1, { disciplines: [] } );

		await useCharacterEditorStore.getState().loadCharacter( 1, 'kony' );

		expect( useCharacterEditorStore.getState().restorableDraft ).toBeNull();
	} );

	it( 'does not offer a draft when none was ever saved', async () => {
		await useCharacterEditorStore.getState().loadCharacter( 1, 'kony' );

		expect( useCharacterEditorStore.getState().restorableDraft ).toBeNull();
	} );

	it( 'restoreDraft() applies the draft into sheetData, marks dirty, and clears the offer', () => {
		useCharacterEditorStore.setState( {
			sheetData: { disciplines: [] },
			originalSheetData: { disciplines: [] },
			dirty: false,
			restorableDraft: { sheetData: { disciplines: [ { name: 'Auspex', count: 3 } ] }, savedAt: Date.now() },
		} );

		useCharacterEditorStore.getState().restoreDraft();

		const state = useCharacterEditorStore.getState();
		expect( state.sheetData ).toEqual( { disciplines: [ { name: 'Auspex', count: 3 } ] } );
		expect( state.dirty ).toBe( true );
		expect( state.restorableDraft ).toBeNull();
	} );

	it( 'dismissDraft() clears the offer and the underlying stored draft without touching sheetData', () => {
		saveDraft( 1, { disciplines: [ { name: 'Auspex', count: 3 } ] } );
		useCharacterEditorStore.setState( {
			sheetData: { disciplines: [] },
			restorableDraft: { sheetData: { disciplines: [ { name: 'Auspex', count: 3 } ] }, savedAt: Date.now() },
		} );

		useCharacterEditorStore.getState().dismissDraft();

		const state = useCharacterEditorStore.getState();
		expect( state.restorableDraft ).toBeNull();
		expect( state.sheetData ).toEqual( { disciplines: [] } );
		expect( loadDraft( 1 ) ).toBeNull();
	} );

	it( 'reset() (Discard) also clears the stored draft, not just live state', () => {
		saveDraft( 1, { disciplines: [ { name: 'Auspex', count: 3 } ] } );

		useCharacterEditorStore.getState().reset();

		expect( loadDraft( 1 ) ).toBeNull();
	} );

	it( 'clears the stored draft once submitChanges() resolves with nothing left pending', async () => {
		saveDraft( 1, { disciplines: [ { name: 'Celerity', count: 1 } ] } );
		const create = jest.fn().mockResolvedValue( { id: 1, status: 'approved' } );
		mockedApi.changes = jest.fn().mockReturnValue( { create } ) as never;

		await useCharacterEditorStore.getState().submitChanges();

		expect( loadDraft( 1 ) ).toBeNull();
	} );
} );
