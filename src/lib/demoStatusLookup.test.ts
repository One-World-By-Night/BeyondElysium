import { beforeEach, describe, expect, it, vi } from 'vitest';
import { isDemoChronicle, resetDemoStatusLookup } from './demoStatusLookup';

describe( 'isDemoChronicle', () => {
	beforeEach( () => resetDemoStatusLookup() );

	it( 'asks once for a chronicle however many controls ask', async () => {
		const fetchStatus = vi.fn().mockResolvedValue( { on: true } );

		const answers = await Promise.all( [
			isDemoChronicle( 'be-demo', fetchStatus ),
			isDemoChronicle( 'be-demo', fetchStatus ),
			isDemoChronicle( 'be-demo', fetchStatus ),
		] );

		expect( answers ).toEqual( [ true, true, true ] );
		expect( fetchStatus ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'asks separately for each chronicle', async () => {
		const fetchStatus = vi
			.fn()
			.mockImplementation( ( slug: string ) =>
				Promise.resolve( { on: slug === 'be-demo' } )
			);

		expect( await isDemoChronicle( 'be-demo', fetchStatus ) ).toBe( true );
		expect( await isDemoChronicle( 'kony', fetchStatus ) ).toBe( false );
		expect( fetchStatus ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'reads a failed request as not a demo, and does not ask again', async () => {
		const fetchStatus = vi.fn().mockRejectedValue( new Error( 'down' ) );

		expect( await isDemoChronicle( 'kony', fetchStatus ) ).toBe( false );
		expect( await isDemoChronicle( 'kony', fetchStatus ) ).toBe( false );
		expect( fetchStatus ).toHaveBeenCalledTimes( 1 );
	} );
} );
