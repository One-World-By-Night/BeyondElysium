import { updateLogLine } from './TransferPanel';

describe( 'updateLogLine', () => {
	it( 'names what changed', () => {
		expect(
			updateLogLine( {
				when: '2026-10-02 12:00:00',
				sequence: 3,
				changed: [ 'vampire-disciplines', 'resources' ],
				custom: [],
			} )
		).toBe( '2026-10-02 12:00:00 — vampire-disciplines, resources' );
	} );

	it( 'reads as nothing new when nothing changed', () => {
		expect(
			updateLogLine( {
				when: '2026-10-02 12:00:00',
				sequence: 3,
				changed: [],
				custom: [],
			} )
		).toBe( '2026-10-02 12:00:00 — nothing new' );
	} );

	it( 'names what landed custom', () => {
		expect(
			updateLogLine( {
				when: '2026-10-02 12:00:00',
				sequence: 3,
				changed: [ 'vampire-disciplines' ],
				custom: [ 'vampire-disciplines: Celerity' ],
			} )
		).toBe(
			'2026-10-02 12:00:00 — vampire-disciplines - landed custom: vampire-disciplines: Celerity'
		);
	} );
} );
