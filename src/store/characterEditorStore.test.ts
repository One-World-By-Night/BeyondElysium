/**
 * A Zustand store is a plain state container.
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

const mockedApi = api as jest.Mocked< typeof api >;

/**
 * Lets every already-settled promise callback run.
 */
async function flush() {
	for ( let i = 0; i < 5; i++ ) {
		await Promise.resolve();
	}
}

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
		// Whatever a submission leaves unsaved is priced again.
		mockedApi.characters = jest.fn().mockReturnValue( {
			previewChanges: jest.fn().mockResolvedValue( null ),
		} ) as never;
		// Two blocks so computeChanges produces two changes to submit in sequence.
		useCharacterEditorStore.setState( {
			stack: {
				stack: {} as never,
				blocks: {
					disciplines: {
						section_type: 'trait_list',
						definition: { items: [] },
					} as never,
					virtues: {
						section_type: 'trait_list',
						definition: { items: [] },
					} as never,
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

		// Both queued changes are attempted.
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
		// The block that failed keeps its original (pre-edit) baseline.
		expect( state.originalSheetData[ failedCategory ] ).not.toEqual(
			state.sheetData[ failedCategory ]
		);
		expect( state.originalSheetData[ succeededCategory ] ).toEqual(
			state.sheetData[ succeededCategory ]
		);
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

		expect( create ).toHaveBeenCalledTimes( 1 );
		expect( retry.submitted ).toHaveLength( 1 );
		expect( retry.failed ).toHaveLength( 0 );
	} );

	it( 'resets the baseline and reports full success when every change lands', async () => {
		const create = jest
			.fn()
			.mockResolvedValue( { id: 1, status: 'approved' } );
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
		const create = jest
			.fn()
			.mockResolvedValue( { id: 1, status: 'pending' } );
		mockedApi.changes = jest.fn().mockReturnValue( { create } ) as never;

		const result = await useCharacterEditorStore.getState().submitChanges();

		expect( result.failed ).toHaveLength( 0 );
		expect( result.submitted ).toHaveLength( 2 );
		expect( result.pending ).toHaveLength( 2 );

		const state = useCharacterEditorStore.getState();
		// The draft must still differ from the baseline.
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

		expect( state.originalSheetData[ approvedCategory ] ).toEqual(
			state.sheetData[ approvedCategory ]
		);
		expect( state.originalSheetData[ pendingCategory ] ).not.toEqual(
			state.sheetData[ pendingCategory ]
		);
	} );

	/**
	 * While the requests are still out, an edit made after Submit stays unsaved and a second Submit is ignored.
	 */
	describe( 'while the requests are still out', () => {
		let release: ( created: unknown ) => void;
		let create: jest.Mock;

		beforeEach( () => {
			jest.useFakeTimers();
			window.localStorage.clear();
			mockedApi.characters = jest.fn().mockReturnValue( {
				previewChanges: jest.fn().mockResolvedValue( null ),
			} ) as never;
			create = jest
				.fn()
				.mockImplementationOnce(
					() =>
						new Promise( ( resolve ) => {
							release = resolve;
						} )
				)
				.mockResolvedValue( { id: 2, status: 'approved' } );
			mockedApi.changes = jest
				.fn()
				.mockReturnValue( { create } ) as never;
		} );

		afterEach( () => {
			jest.clearAllTimers();
			jest.useRealTimers();
		} );

		it( 'keeps an edit made after Submit as unsaved, baselining only what was sent', async () => {
			const submitting = useCharacterEditorStore
				.getState()
				.submitChanges();
			useCharacterEditorStore
				.getState()
				.setBlockData( 'disciplines', [
					{ name: 'Celerity', count: 2 },
				] );
			release( { id: 1, status: 'approved' } );
			await submitting;

			const state = useCharacterEditorStore.getState();
			expect( state.originalSheetData.disciplines ).toEqual( [
				{ name: 'Celerity', count: 1 },
			] );
			expect( state.dirty ).toBe( true );
			expect( state.pendingChanges ).toHaveLength( 1 );
			expect( loadDraft( 1 )?.sheetData.disciplines ).toEqual( [
				{ name: 'Celerity', count: 2 },
			] );
		} );

		it( 'ignores a second Submit, so no change is sent twice', async () => {
			const first = useCharacterEditorStore.getState().submitChanges();
			const second = await useCharacterEditorStore
				.getState()
				.submitChanges();
			release( { id: 1, status: 'approved' } );
			await first;

			expect( second.submitted ).toHaveLength( 0 );
			expect( create ).toHaveBeenCalledTimes( 2 );
		} );

		it( 'leaves another character alone when it was opened before the requests came back', async () => {
			const submitting = useCharacterEditorStore
				.getState()
				.submitChanges();
			useCharacterEditorStore.setState( {
				characterId: 2,
				sheetData: { disciplines: [ { name: 'Potence', count: 4 } ] },
				originalSheetData: {
					disciplines: [ { name: 'Potence', count: 3 } ],
				},
				submittedChanges: [],
			} );
			release( { id: 1, status: 'approved' } );
			await submitting;

			const state = useCharacterEditorStore.getState();
			expect( state.originalSheetData ).toEqual( {
				disciplines: [ { name: 'Potence', count: 3 } ],
			} );
			expect( state.submittedChanges ).toHaveLength( 0 );
			expect( state.saving ).toBe( false );
		} );

		it( 'prices what is left unsaved once the requests come back, and drops a preview that was already on its way', async () => {
			let answerStalePreview: ( value: unknown ) => void = () => {};
			const previewChanges = jest
				.fn()
				.mockImplementationOnce(
					() =>
						new Promise( ( resolve ) => {
							answerStalePreview = resolve;
						} )
				)
				.mockResolvedValue( {
					results: [ { xp_cost: 1 } ],
					running_xp_unspent: 8,
				} );
			mockedApi.characters = jest
				.fn()
				.mockReturnValue( { previewChanges } ) as never;

			// A preview is on its way when Submit is pressed.
			useCharacterEditorStore
				.getState()
				.setBlockData( 'disciplines', [
					{ name: 'Celerity', count: 1 },
				] );
			jest.advanceTimersByTime( 500 );
			const submitting = useCharacterEditorStore
				.getState()
				.submitChanges();
			useCharacterEditorStore
				.getState()
				.setBlockData( 'disciplines', [
					{ name: 'Celerity', count: 2 },
				] );
			release( { id: 1, status: 'approved' } );
			await submitting;
			await flush();

			expect( previewChanges ).toHaveBeenLastCalledWith(
				1,
				useCharacterEditorStore.getState().pendingChanges
			);
			expect( useCharacterEditorStore.getState().previewCosts ).toEqual( {
				results: [ { xp_cost: 1 } ],
				running_xp_unspent: 8,
			} );

			answerStalePreview( {
				results: [ { xp_cost: 99 }, { xp_cost: 99 } ],
				running_xp_unspent: -188,
			} );
			await flush();
			expect(
				useCharacterEditorStore.getState().previewCosts
					?.running_xp_unspent
			).toBe( 8 );
		} );

		it( 'drops a preview for the character that was open before another one loaded', async () => {
			let answerStalePreview: ( value: unknown ) => void = () => {};
			mockedApi.characters = jest.fn().mockReturnValue( {
				previewChanges: jest.fn().mockImplementationOnce(
					() =>
						new Promise( ( resolve ) => {
							answerStalePreview = resolve;
						} )
				),
				get: jest.fn().mockResolvedValue( {
					id: 2,
					stack_slug: 'vampire',
					sheet_data: { disciplines: [] },
				} ),
			} ) as never;
			mockedApi.creatureStacks = {
				resolve: jest
					.fn()
					.mockResolvedValue( { stack: {}, blocks: {} } ),
			} as never;

			useCharacterEditorStore
				.getState()
				.setBlockData( 'disciplines', [
					{ name: 'Celerity', count: 3 },
				] );
			jest.advanceTimersByTime( 500 );
			await useCharacterEditorStore.getState().loadCharacter( 2, 'kony' );
			answerStalePreview( {
				results: [ { xp_cost: 9 } ],
				running_xp_unspent: 1,
			} );
			await flush();

			expect(
				useCharacterEditorStore.getState().previewCosts
			).toBeNull();
		} );
	} );
} );

/**
 * Draft autosave: a draft of the sheet is saved locally on each edit and offered back on reload.
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
		mockedApi.creatureStacks = {
			resolve: jest.fn().mockResolvedValue( { stack: {}, blocks: {} } ),
		} as never;
	} );

	it( 'saves a draft to localStorage on every setBlockData call', () => {
		useCharacterEditorStore
			.getState()
			.setBlockData( 'disciplines', [ { name: 'Fortitude', count: 2 } ] );

		const draft = loadDraft( 1 );
		expect( draft?.sheetData ).toEqual( {
			disciplines: [ { name: 'Fortitude', count: 2 } ],
		} );
	} );

	it( 'offers a draft on loadCharacter() when it genuinely differs from the server value', async () => {
		saveDraft( 1, { disciplines: [ { name: 'Obfuscate', count: 1 } ] } );

		await useCharacterEditorStore.getState().loadCharacter( 1, 'kony' );

		const state = useCharacterEditorStore.getState();
		expect( state.restorableDraft?.sheetData ).toEqual( {
			disciplines: [ { name: 'Obfuscate', count: 1 } ],
		} );
		// The draft is only ever offered.
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
			restorableDraft: {
				sheetData: { disciplines: [ { name: 'Auspex', count: 3 } ] },
				savedAt: Date.now(),
			},
		} );

		useCharacterEditorStore.getState().restoreDraft();

		const state = useCharacterEditorStore.getState();
		expect( state.sheetData ).toEqual( {
			disciplines: [ { name: 'Auspex', count: 3 } ],
		} );
		expect( state.dirty ).toBe( true );
		expect( state.restorableDraft ).toBeNull();
	} );

	it( 'dismissDraft() clears the offer and the underlying stored draft without touching sheetData', () => {
		saveDraft( 1, { disciplines: [ { name: 'Auspex', count: 3 } ] } );
		useCharacterEditorStore.setState( {
			sheetData: { disciplines: [] },
			restorableDraft: {
				sheetData: { disciplines: [ { name: 'Auspex', count: 3 } ] },
				savedAt: Date.now(),
			},
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
		const create = jest
			.fn()
			.mockResolvedValue( { id: 1, status: 'approved' } );
		mockedApi.changes = jest.fn().mockReturnValue( { create } ) as never;

		await useCharacterEditorStore.getState().submitChanges();

		expect( loadDraft( 1 ) ).toBeNull();
	} );
} );
