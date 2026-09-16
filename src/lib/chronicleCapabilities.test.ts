import { canIn } from './chronicleCapabilities';
import type { MyCapabilities } from '../types';

/**
 * 1.0.0-review F-103: My Chronicle's screens decided what to show from the site-wide snapshot of
 * what the person can do anywhere, so a Storyteller of one chronicle switching to one where they
 * only play saw the Storyteller dashboard - which then failed to load - and manager controls on
 * characters that weren't theirs to manage.
 */
describe( 'canIn', () => {
	const siteWide = {
		be_manage_characters: true,
		be_manage_plots: true,
		be_manage_connections: true,
		be_manage_boons: true,
		be_manage_schemas: true,
	};
	const playerHere: MyCapabilities = {
		be_manage_characters: false,
		be_manage_plots: false,
		be_manage_schemas: false,
		be_manage_connections: false,
		be_manage_boons: false,
	};

	beforeEach( () => {
		( window as unknown as { beyondElysium: unknown } ).beyondElysium = {
			capabilities: siteWide,
		};
	} );

	afterEach( () => {
		delete ( window as unknown as { beyondElysium?: unknown } )
			.beyondElysium;
	} );

	it( "answers from the chronicle's own capabilities when the page resolved them", () => {
		expect( canIn( 'be_manage_characters', playerHere ) ).toBe( false );
		expect(
			canIn( 'be_manage_boons', { ...playerHere, be_manage_boons: true } )
		).toBe( true );
	} );

	it( 'answers from the site-wide snapshot for a screen placed on its own, with no chronicle picker', () => {
		expect( canIn( 'be_manage_characters' ) ).toBe( true );
	} );

	it( 'answers no when there is nothing to answer from', () => {
		delete ( window as unknown as { beyondElysium?: unknown } )
			.beyondElysium;
		expect( canIn( 'be_manage_plots' ) ).toBe( false );
	} );
} );
