/**
 * Closing the media picker without choosing an image left its promise pending forever.
 */
import { pickMediaImage } from './pickMediaImage';

type Handler = () => void;

function stubPicker( onOpen: ( fire: ( event: string ) => void ) => void ) {
	const handlers: Record< string, Handler[] > = {};
	const fire = ( event: string ) =>
		( handlers[ event ] ?? [] ).forEach( ( handler ) => handler() );
	const frame = {
		on: ( event: string, handler: Handler ) => {
			handlers[ event ] = [ ...( handlers[ event ] ?? [] ), handler ];
		},
		open: () => onOpen( fire ),
		state: () => ( {
			get: () => ( {
				first: () => ( {
					toJSON: () => ( {
						id: 7,
						url: 'https://example.test/portrait.jpg',
					} ),
				} ),
			} ),
		} ),
	};
	( globalThis as unknown as { wp: unknown } ).wp = { media: () => frame };
}

describe( 'pickMediaImage', () => {
	afterEach( () => {
		delete ( globalThis as unknown as { wp?: unknown } ).wp;
	} );

	it( 'resolves null when the picker is closed without a choice', async () => {
		stubPicker( ( fire ) => fire( 'close' ) );

		await expect(
			pickMediaImage( 'Choose a portrait' )
		).resolves.toBeNull();
	} );

	it( "resolves the image when Select closes the picker before announcing it, as WordPress's does", async () => {
		stubPicker( ( fire ) => {
			fire( 'close' );
			fire( 'select' );
		} );

		await expect( pickMediaImage( 'Choose a portrait' ) ).resolves.toEqual(
			{ id: 7, url: 'https://example.test/portrait.jpg' }
		);
	} );

	it( 'resolves the image when the picker announces it before closing', async () => {
		stubPicker( ( fire ) => {
			fire( 'select' );
			fire( 'close' );
		} );

		await expect( pickMediaImage( 'Choose a portrait' ) ).resolves.toEqual(
			{ id: 7, url: 'https://example.test/portrait.jpg' }
		);
	} );
} );
