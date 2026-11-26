import { createAutosaver, type AutosaveState } from './autosave';

function setup( options: { fail?: number } = {} ) {
	const saved: string[] = [];
	const states: Array< [ AutosaveState, string | undefined ] > = [];
	let failures = options.fail ?? 0;
	const autosaver = createAutosaver( {
		initial: 'a',
		save: ( value: string ) => {
			if ( failures > 0 ) {
				failures--;
				return Promise.reject( new Error( 'offline' ) );
			}
			saved.push( value );
			return Promise.resolve();
		},
		onState: ( state, message ) => states.push( [ state, message ] ),
		delay: 1500,
		retryDelay: 5000,
		maxRetries: 2,
	} );
	return { autosaver, saved, states };
}

const last = ( states: Array< [ AutosaveState, string | undefined ] > ) =>
	states[ states.length - 1 ][ 0 ];

beforeEach( () => {
	vi.useFakeTimers();
} );

afterEach( () => {
	vi.useRealTimers();
} );

describe( 'autosaving', () => {
	it( 'saves a moment after the last change, once', async () => {
		const { autosaver, saved, states } = setup();
		autosaver.change( 'ab' );
		autosaver.change( 'abc' );
		expect( last( states ) ).toBe( 'dirty' );
		await vi.advanceTimersByTimeAsync( 1400 );
		expect( saved ).toEqual( [] );
		await vi.advanceTimersByTimeAsync( 200 );
		expect( saved ).toEqual( [ 'abc' ] );
		expect( last( states ) ).toBe( 'saved' );
		expect( autosaver.hasUnsaved() ).toBe( false );
	} );

	it( 'passes through saving on the way to saved', async () => {
		const { autosaver, states } = setup();
		autosaver.change( 'b' );
		await vi.advanceTimersByTimeAsync( 1500 );
		expect( states.map( ( s ) => s[ 0 ] ) ).toEqual( [
			'dirty',
			'saving',
			'saved',
		] );
	} );

	it( 'saves nothing for a change back to what is saved', async () => {
		const { autosaver, saved, states } = setup();
		autosaver.change( 'b' );
		autosaver.change( 'a' );
		await vi.advanceTimersByTimeAsync( 5000 );
		expect( saved ).toEqual( [] );
		expect( last( states ) ).toBe( 'idle' );
		expect( autosaver.hasUnsaved() ).toBe( false );
	} );

	it( 'saves nothing when asked to flush with nothing changed', async () => {
		const { autosaver, saved, states } = setup();
		await autosaver.flush();
		expect( saved ).toEqual( [] );
		expect( states.map( ( s ) => s[ 0 ] ) ).toEqual( [ 'idle' ] );
	} );

	it( 'flushes at once, as when a field loses focus', async () => {
		const { autosaver, saved } = setup();
		autosaver.change( 'b' );
		await autosaver.flush();
		expect( saved ).toEqual( [ 'b' ] );
		await vi.advanceTimersByTimeAsync( 5000 );
		expect( saved ).toEqual( [ 'b' ] );
	} );

	it( 'saves a change made while a save was running, after it', async () => {
		let release: () => void = () => undefined;
		const saved: string[] = [];
		const states: AutosaveState[] = [];
		const autosaver = createAutosaver( {
			initial: 'a',
			save: ( value: string ) =>
				new Promise< void >( ( resolve ) => {
					release = () => {
						saved.push( value );
						resolve();
					};
				} ),
			onState: ( state ) => states.push( state ),
		} );
		autosaver.change( 'b' );
		const flushing = autosaver.flush();
		autosaver.change( 'bc' );
		expect( autosaver.hasUnsaved() ).toBe( true );
		release();
		await flushing;
		expect( states[ states.length - 1 ] ).toBe( 'dirty' );
		await vi.advanceTimersByTimeAsync( 1500 );
		release();
		await vi.advanceTimersByTimeAsync( 0 );
		expect( saved ).toEqual( [ 'b', 'bc' ] );
		expect( states[ states.length - 1 ] ).toBe( 'saved' );
	} );
} );

describe( 'when a save fails', () => {
	it( 'says why, keeps the change and tries again after a while', async () => {
		const { autosaver, saved, states } = setup( { fail: 1 } );
		autosaver.change( 'b' );
		await vi.advanceTimersByTimeAsync( 1500 );
		expect( states[ states.length - 1 ] ).toEqual( [
			'failed',
			'offline',
		] );
		expect( autosaver.hasUnsaved() ).toBe( true );
		await vi.advanceTimersByTimeAsync( 5000 );
		expect( saved ).toEqual( [ 'b' ] );
		expect( last( states ) ).toBe( 'saved' );
		expect( autosaver.hasUnsaved() ).toBe( false );
	} );

	it( 'gives up trying by itself after its retries, and Retry tries again at once', async () => {
		const { autosaver, saved, states } = setup( { fail: 4 } );
		autosaver.change( 'b' );
		await vi.advanceTimersByTimeAsync( 1500 );
		await vi.advanceTimersByTimeAsync( 5000 );
		await vi.advanceTimersByTimeAsync( 5000 );
		expect( last( states ) ).toBe( 'failed' );
		await vi.advanceTimersByTimeAsync( 20000 );
		expect( saved ).toEqual( [] );
		expect( autosaver.hasUnsaved() ).toBe( true );
		await autosaver.retry();
		expect( saved ).toEqual( [] );
		await autosaver.retry();
		expect( saved ).toEqual( [ 'b' ] );
		expect( last( states ) ).toBe( 'saved' );
	} );
} );

describe( 'disposing', () => {
	it( 'stops a save that was waiting', async () => {
		const { autosaver, saved } = setup();
		autosaver.change( 'b' );
		autosaver.dispose();
		await vi.advanceTimersByTimeAsync( 5000 );
		expect( saved ).toEqual( [] );
	} );
} );
