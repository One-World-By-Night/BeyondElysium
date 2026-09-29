/**
 * Fails a test that writes to the console.
 */
import { afterEach, beforeEach, vi } from 'vitest';
import type { MockInstance } from 'vitest';

const METHODS = [ 'error', 'warn', 'info', 'log' ] as const;

let spies: Array< [ string, MockInstance ] > = [];

beforeEach( () => {
	spies = METHODS.map( ( method ) => [
		method,
		vi.spyOn( console, method ).mockImplementation( () => undefined ),
	] );
} );

afterEach( () => {
	const written = spies.filter( ( [ , spy ] ) => spy.mock.calls.length > 0 );
	spies.forEach( ( [ , spy ] ) => spy.mockRestore() );
	spies = [];
	if ( written.length > 0 ) {
		const [ method, spy ] = written[ 0 ];
		throw new Error(
			`console.${ method } was called: ${ spy.mock.calls[ 0 ].join( ' ' ) }`
		);
	}
} );
