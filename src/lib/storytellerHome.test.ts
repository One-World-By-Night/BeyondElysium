import { nightLines, spotlightLine, waitingLines } from './storytellerHome';
import type { StorytellerHomeData } from '../types/home';

function home(
	waiting: Partial< StorytellerHomeData[ 'waiting' ] > = {}
): StorytellerHomeData {
	return {
		today: '2026-11-26',
		waiting: {
			approvals: null,
			downtime: null,
			plots: null,
			players: null,
			castings: [],
			...waiting,
		},
		next_session: null,
		spotlight: null,
	};
}

describe( 'waitingLines', () => {
	it( 'is empty when nothing waits', () => {
		expect(
			waitingLines(
				home( {
					approvals: {
						changes: 0,
						xp_requests: 0,
						files: 0,
						new_characters: 0,
					},
					downtime: { mine: 0, unassigned: 0 },
					plots: { mine: 0, unassigned: 0 },
					players: { join_requests: 0, links: 0 },
				} ),
				'kony'
			)
		).toEqual( [] );
	} );

	it( 'says how many of the waiting changes are with Coordinators', () => {
		const line = ( withCoordinators: number ) =>
			waitingLines(
				home( {
					approvals: {
						changes: 5,
						xp_requests: 0,
						files: 0,
						new_characters: 0,
						with_coordinators: withCoordinators,
					},
				} ),
				'kony'
			)[ 0 ].text;

		expect( line( 3 ) ).toBe(
			'Approval Queue: 5 waiting · 3 with Coordinators'
		);
		expect( line( 1 ) ).toBe(
			'Approval Queue: 5 waiting · 1 with a Coordinator'
		);
		expect( line( 0 ) ).toBe( 'Approval Queue: 5 waiting' );
	} );

	it( 'says what waits in the Approval Queue and opens it', () => {
		const [ line ] = waitingLines(
			home( {
				approvals: {
					changes: 7,
					xp_requests: 2,
					files: 1,
					new_characters: 0,
				},
			} ),
			'kony'
		);

		expect( line.text ).toBe(
			'Approval Queue: 7 waiting · 2 XP requests · 1 Grapevine file'
		);
		expect( line.href ).toContain( 'tab=approval-queue' );
		expect( line.href ).toContain( 'game_slug=kony' );
	} );

	it( 'leaves out a part that is zero and a file alone still counts', () => {
		const [ line ] = waitingLines(
			home( {
				approvals: {
					changes: 0,
					xp_requests: 0,
					files: 2,
					new_characters: 0,
				},
			} ),
			'kony'
		);

		expect( line.text ).toBe( 'Approval Queue: 2 Grapevine files' );
	} );

	it( 'counts a new character that waits beside the changes', () => {
		const [ line ] = waitingLines(
			home( {
				approvals: {
					changes: 7,
					xp_requests: 0,
					files: 0,
					new_characters: 1,
				},
			} ),
			'kony'
		);

		expect( line.text ).toBe(
			'Approval Queue: 7 waiting · 1 new character'
		);
	} );

	it( 'shows the line for a new character alone', () => {
		const [ line ] = waitingLines(
			home( {
				approvals: {
					changes: 0,
					xp_requests: 0,
					files: 0,
					new_characters: 2,
				},
			} ),
			'kony'
		);

		expect( line.text ).toBe( 'Approval Queue: 2 new characters' );
	} );

	it( 'splits downtime into what is the persons and what nobody has taken', () => {
		const lines = waitingLines(
			home( {
				downtime: { mine: 3, unassigned: 4 },
				plots: { mine: 0, unassigned: 1 },
			} ),
			'kony'
		);

		expect( lines[ 0 ].text ).toBe(
			'Downtime: 3 to answer · 4 nobody has taken'
		);
		expect( lines[ 0 ].href ).toContain( 'tab=my-queue' );
		expect( lines[ 1 ].text ).toBe( 'Plot posts: 1 nobody has taken' );
		expect( lines[ 1 ].href ).toContain( 'tab=plots' );
	} );

	it( 'says who asks to join and which links wait', () => {
		const [ line ] = waitingLines(
			home( { players: { join_requests: 1, links: 2 } } ),
			'kony'
		);

		expect( line.text ).toBe(
			'Players: 1 asks to join · 2 Discord links to approve'
		);
		expect( line.href ).toContain( 'tab=players' );
	} );

	it( 'lists the persons own castings', () => {
		const [ line ] = waitingLines(
			home( {
				castings: [
					{ character_name: 'Ashkelon', game_date: '2026-11-28' },
					{ character_name: 'Brigid', game_date: '2026-12-05' },
				],
			} ),
			'kony'
		);

		expect( line.text ).toBe(
			'Your castings: Ashkelon on Sat, Nov 28; Brigid on Sat, Dec 5'
		);
	} );
} );

describe( 'nightLines', () => {
	const night = {
		id: 1,
		game_date: '2026-11-28',
		start_time: null,
		place: null,
		downtime_deadline_at: null,
		downtime: null,
		reports: null,
	};

	it( 'says nothing for a night with neither downtime nor reports', () => {
		expect( nightLines( night ) ).toEqual( [] );
	} );

	it( 'says how far downtime has come, with the deadline when there is one', () => {
		expect(
			nightLines( {
				...night,
				downtime: { sent: 6, total: 14 },
				downtime_deadline_at: '2026-11-27 23:59:00',
			} )
		).toEqual( [
			'Downtime closes Fri, Nov 27, 23:59: 6 of 14 characters have sent actions',
		] );
		expect(
			nightLines( { ...night, downtime: { sent: 1, total: 1 } } )
		).toEqual( [ '1 of 1 character has sent actions' ] );
	} );

	it( 'says how far the last nights reports have come', () => {
		expect(
			nightLines( {
				...night,
				reports: {
					game_date: '2026-11-21',
					written: 3,
					attended: 9,
					due_at: '2026-11-29 00:00:00',
				},
			} )
		).toEqual( [ 'Reports for Sat, Nov 21: 3 of 9 have written theirs' ] );
	} );
} );

describe( 'spotlightLine', () => {
	it( 'counts the characters and the days', () => {
		expect( spotlightLine( 4, 42 ) ).toBe(
			'4 characters have had no staff post in 42 days'
		);
		expect( spotlightLine( 1, 42 ) ).toBe(
			'1 character has had no staff post in 42 days'
		);
	} );
} );
