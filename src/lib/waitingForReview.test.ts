import { mergeWaitingRows } from './waitingForReview';
import type { Transfer } from '../types/transfer';
import type { Submission } from '../types/submission';

function transfer( id: number, initiatedAt: string ): Transfer {
	return {
		id,
		character_uuid: `uuid-${ id }`,
		character_id: null,
		character_name: `Transfer ${ id }`,
		direction: 'inbound',
		state: 'offered',
		home_slug: 'home',
		home_site: 'https://home.example',
		home_chronicle: 'Home Chronicle',
		host_slug: 'host',
		host_site: 'https://host.example',
		host_chronicle: 'Host Chronicle',
		attestation_id: null,
		snapshot_id: null,
		payload_hash: 'x',
		initiated_by: 1,
		initiated_at: initiatedAt,
		acknowledged_at: null,
		returned_at: null,
		notes: null,
	};
}

function submission( id: number, createdAt: string ): Submission {
	return {
		id,
		game_id: 1,
		submitted_by: 1,
		arrival: 'joining',
		home_chronicle: null,
		character_name: `Submission ${ id }`,
		stack_slug: 'vampire',
		source_file: 'sheet.gex',
		format: 'XML',
		file_hash: 'x',
		state: 'waiting',
		character_id: null,
		answered_by: null,
		answer_note: null,
		created_at: createdAt,
		answered_at: null,
	};
}

describe( 'mergeWaitingRows', () => {
	it( 'orders mixed transfer and submission rows newest first', () => {
		const rows = mergeWaitingRows(
			[
				transfer( 1, '2026-09-10 10:00:00' ),
				transfer( 2, '2026-09-15 09:00:00' ),
			],
			[
				submission( 10, '2026-09-12 12:00:00' ),
				submission( 11, '2026-09-16 08:00:00' ),
			]
		);

		expect( rows.map( ( r ) => `${ r.kind }-${ r.row.id }` ) ).toEqual( [
			'submission-11',
			'transfer-2',
			'submission-10',
			'transfer-1',
		] );
	} );

	it( 'returns an empty list when nothing is waiting', () => {
		expect( mergeWaitingRows( [], [] ) ).toEqual( [] );
	} );

	it( 'handles one kind being entirely absent', () => {
		const rows = mergeWaitingRows(
			[ transfer( 1, '2026-09-10 10:00:00' ) ],
			[]
		);
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].kind ).toBe( 'transfer' );
	} );
} );
