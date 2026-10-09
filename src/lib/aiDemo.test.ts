import { describe, expect, it } from 'vitest';
import { aiDemoMessage } from './aiDemo';

describe( 'aiDemoMessage', () => {
	it( 'says drafting is off on the demo and that nothing was sent', () => {
		const message = aiDemoMessage();
		expect( message ).toContain( 'switched off on the public demo' );
		expect( message ).toContain( 'nothing was sent' );
	} );
} );
