/**
 * The list calls that read a page's totals from its headers ask api-fetch not to parse the response, and a failed one
 * then rejected with the raw response.
 */
import apiFetch from '@wordpress/api-fetch';
import api from './client';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const mockedFetch = apiFetch as unknown as jest.Mock;

const pages: Array< [ string, () => Promise< unknown > ] > = [
	[ 'characters', () => api.characters( 'kony' ).listPaginated() ],
	[ 'the approval queue', () => api.changes( 'kony' ).queue() ],
	[ 'schema blocks', () => api.schemaBlocks.listPaginated() ],
	[ 'plots', () => api.plots( 'kony' ).listPaginated() ],
	[ 'world objects', () => api.worldObjects( 'kony' ).listPaginated() ],
	[
		'a query',
		() =>
			api.query( 'kony' ).runPaginated( {
				inventory: 'item',
				conditions: [],
				logic: 'AND',
			} ),
	],
];

function failedResponse( body: () => Promise< unknown > ) {
	return { ok: false, status: 400, json: body, headers: { get: () => null } };
}

describe( 'a page of results', () => {
	afterEach( () => mockedFetch.mockReset() );

	it.each( pages )(
		'for %s reads its totals from the headers',
		async ( _name, fetchPage ) => {
			mockedFetch.mockResolvedValue( {
				ok: true,
				status: 200,
				json: async () => [ { id: 1 }, { id: 2 } ],
				headers: {
					get: ( header: string ) =>
						(
							( {
								'X-WP-Total': '42',
								'X-WP-TotalPages': '3',
							} ) as Record< string, string >
						 )[ header ] ?? null,
				},
			} );

			await expect( fetchPage() ).resolves.toEqual( {
				items: [ { id: 1 }, { id: 2 } ],
				total: 42,
				totalPages: 3,
			} );
		}
	);

	it.each( pages )(
		"for %s that fails carries the server's code and message",
		async ( _name, fetchPage ) => {
			const error = {
				code: 'invalid_param',
				message: 'Clan is not a field of an item.',
				data: { status: 400 },
			};
			mockedFetch.mockRejectedValue(
				failedResponse( async () => error )
			);

			await expect( fetchPage() ).rejects.toEqual( error );
		}
	);

	it( 'that fails with no readable body rejects as it came, for the caller to name', async () => {
		const response = failedResponse( async () => {
			throw new SyntaxError( 'Unexpected token <' );
		} );
		mockedFetch.mockRejectedValue( response );

		await expect( api.changes( 'kony' ).queue() ).rejects.toBe( response );
	} );

	it( 'that never reached the server rejects with what api-fetch said', async () => {
		const offline = {
			code: 'fetch_error',
			message: 'You are probably offline.',
		};
		mockedFetch.mockRejectedValue( offline );

		await expect( api.plots( 'kony' ).listPaginated() ).rejects.toBe(
			offline
		);
	} );
} );
