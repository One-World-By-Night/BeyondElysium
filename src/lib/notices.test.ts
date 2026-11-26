import { noticeHref, noticeText, type Notice } from './notices';

function notice( kind: string, payload: Record< string, unknown > ): Notice {
	return {
		id: 1,
		game_id: 1,
		kind,
		payload,
		created_at: '2026-11-26 10:00:00',
		read_at: null,
	};
}

describe( 'noticeText', () => {
	it( 'says what a Storyteller decided, with the lines and notes', () => {
		const text = noticeText(
			notice( 'changes_decided', {
				character_name: 'Isolde Marchetti',
				approved: 2,
				refused: 1,
				lines: [
					{ text: 'Occult x4', status: 'approved', note: '' },
					{ text: 'Fire Walk', status: 'refused', note: 'Not yet.' },
				],
			} )
		);

		expect( text.title ).toBe( 'Isolde Marchetti: 2 approved, 1 refused' );
		expect( text.lines ).toEqual( [
			'Approved: Occult x4',
			'Refused: Fire Walk — Not yet.',
		] );
	} );

	it( 'leaves out a count that is zero', () => {
		expect(
			noticeText(
				notice( 'changes_decided', {
					character_name: 'Isolde Marchetti',
					approved: 3,
					refused: 0,
					lines: [],
				} )
			).title
		).toBe( 'Isolde Marchetti: 3 approved' );
	} );

	it( 'says what was undone', () => {
		expect(
			noticeText(
				notice( 'changes_undone', {
					character_name: 'Isolde Marchetti',
					undone: 1,
					lines: [
						{
							text: 'Occult x4',
							status: 'undone',
							note: 'By mistake.',
						},
					],
				} )
			)
		).toEqual( {
			title: 'Isolde Marchetti: 1 undone',
			lines: [ 'Undone: Occult x4 — By mistake.' ],
		} );
	} );

	it( 'says a Storyteller changed a request, with their note', () => {
		expect(
			noticeText(
				notice( 'change_edited', {
					text: 'Occult x4',
					note: 'Priced at four.',
				} )
			)
		).toEqual( {
			title: 'A Storyteller changed your request: Occult x4',
			lines: [ 'Priced at four.' ],
		} );
	} );

	it( 'says an XP award with why and when', () => {
		expect(
			noticeText(
				notice( 'xp_awarded', {
					character_name: 'Isolde Marchetti',
					amount: 3,
					reason: 'Game night',
					date: '2026-11-21',
				} )
			).title
		).toBe( '+3 XP for Isolde Marchetti: Game night, Nov 21, 2026' );
	} );

	it( 'says work is waiting for a Storyteller', () => {
		expect(
			noticeText(
				notice( 'changes_waiting', {
					character_name: 'Isolde Marchetti',
					total: 3,
					waiting: 2,
				} )
			).title
		).toBe( 'Isolde Marchetti sent 3 changes; 2 wait for you' );
		expect(
			noticeText(
				notice( 'changes_waiting', {
					character_name: 'Isolde Marchetti',
					total: 1,
					waiting: 1,
				} )
			).title
		).toBe( 'Isolde Marchetti sent 1 change; 1 waits for you' );
	} );

	it( 'names a kind it does not know as stored', () => {
		expect( noticeText( notice( 'something_new', {} ) ).title ).toBe(
			'something_new'
		);
	} );
} );

describe( 'noticeHref', () => {
	it( "points a player's notice at the character's History", () => {
		const href = noticeHref(
			notice( 'changes_decided', { character_id: 7 } ),
			'kony'
		);
		expect( href ).toContain( 'tab=history' );
		expect( href ).toContain( 'character_id=7' );
		expect( href ).toContain( 'game_slug=kony' );
	} );

	it( "points a Storyteller's notice at the Approval Queue", () => {
		const href = noticeHref(
			notice( 'changes_waiting', { character_id: 7 } ),
			'kony'
		);
		expect( href ).toContain( 'tab=approval-queue' );
		expect( href ).toContain( 'game_slug=kony' );
	} );
} );
