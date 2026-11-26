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

describe( 'noticeText for the news that used to go only by email', () => {
	it( 'says downtime closes soon and who has sent nothing', () => {
		expect(
			noticeText(
				notice( 'downtime_due', {
					character_name: 'Isolde Marchetti',
					game_date: '2026-11-28',
					deadline: '2026-11-27 23:59:00',
				} )
			)
		).toEqual( {
			title: 'Downtime for Sat, Nov 28 closes Fri, Nov 27, 23:59',
			lines: [ "Isolde Marchetti hasn't sent any actions." ],
		} );
	} );

	it( 'says a Storyteller posted on a plot, for which character', () => {
		expect(
			noticeText(
				notice( 'plot_post', {
					plot_title: 'The Chantry',
					character_name: 'Isolde Marchetti',
				} )
			)
		).toEqual( {
			title: 'A Storyteller posted on The Chantry',
			lines: [ 'For Isolde Marchetti' ],
		} );
	} );

	it( 'says a player posted actions for staff', () => {
		expect(
			noticeText(
				notice( 'post_waiting', {
					posted_by: 'Isolde Marchetti',
					plot_title: 'Downtime',
				} )
			).title
		).toBe( 'Isolde Marchetti posted actions on Downtime' );
	} );

	it( 'counts what a release brought, leaving out what is zero', () => {
		expect(
			noticeText(
				notice( 'released', {
					character_names: [ 'Isolde Marchetti', 'Isolde Too' ],
					rumors: 3,
					entries: 0,
					reveals: 1,
				} )
			).title
		).toBe( 'New for Isolde Marchetti, Isolde Too: 3 rumors, 1 secret' );
	} );

	it( 'names what became visible by its kind', () => {
		const title = ( entity: string ) =>
			noticeText(
				notice( 'now_visible', { entity, title: 'The Locket' } )
			).title;
		expect( title( 'item' ) ).toBe( 'You can now see the item The Locket' );
		expect( title( 'location' ) ).toBe(
			'You can now see the location The Locket'
		);
		expect( title( 'secret_told' ) ).toBe(
			'A secret was told to you: The Locket'
		);
		expect( title( 'plot' ) ).toBe( 'You can now see the plot The Locket' );
	} );

	it( 'says a request to join was approved', () => {
		expect(
			noticeText( notice( 'join_answered', { chronicle: 'Kony' } ) ).title
		).toBe( 'Your request to join Kony was approved' );
	} );

	it( 'says a Grapevine file was accepted or not, with the note', () => {
		expect(
			noticeText(
				notice( 'file_answered', {
					character_name: 'Isolde',
					accepted: true,
					note: '',
				} )
			)
		).toEqual( {
			title: 'Your Grapevine file for Isolde was accepted',
			lines: [],
		} );
		expect(
			noticeText(
				notice( 'file_answered', {
					character_name: 'Isolde',
					accepted: false,
					note: 'Wrong chronicle.',
				} )
			)
		).toEqual( {
			title: "Your Grapevine file for Isolde wasn't accepted",
			lines: [ 'Wrong chronicle.' ],
		} );
	} );

	it( 'says who asks to join, with their message', () => {
		expect(
			noticeText(
				notice( 'join_requested', {
					applicant: 'Ana Souza',
					chronicle: 'Kony',
					message: 'Let me in.',
				} )
			)
		).toEqual( {
			title: 'Ana Souza asks to join Kony',
			lines: [ 'Let me in.' ],
		} );
	} );

	it( 'says a Discord link waits, with or without characters', () => {
		expect(
			noticeText(
				notice( 'link_requested', { character_names: [ 'Isolde' ] } )
			).title
		).toBe( 'A Discord link for Isolde waits for you' );
		expect(
			noticeText( notice( 'link_requested', { character_names: [] } ) )
				.title
		).toBe( 'A Discord link waits for you' );
	} );

	it( 'says a Grapevine file arrived and a secret was told', () => {
		expect(
			noticeText(
				notice( 'file_received', {
					sender: 'Marco Ruiz',
					character_name: 'Isolde',
				} )
			).title
		).toBe( 'Marco Ruiz sent a Grapevine file for Isolde' );
		expect(
			noticeText(
				notice( 'secret_told', {
					teller_name: 'Teller',
					recipient_name: 'Recipient',
					secret_title: 'A Secret',
				} )
			).title
		).toBe( 'Teller told Recipient a secret: A Secret' );
	} );
} );

describe( 'noticeHref for the news that used to go only by email', () => {
	const href = ( kind: string, payload: Record< string, unknown > = {} ) =>
		noticeHref( notice( kind, payload ), 'kony' );

	it( "opens a Storyteller's work on the page that holds it", () => {
		expect( href( 'file_received' ) ).toContain( 'tab=approval-queue' );
		expect( href( 'secret_told' ) ).toContain( 'tab=approval-queue' );
		expect( href( 'join_requested' ) ).toContain( 'tab=players' );
		expect( href( 'link_requested' ) ).toContain( 'tab=players' );
		expect( href( 'post_waiting', { plot_id: 12 } ) ).toContain(
			'tab=plots'
		);
		expect( href( 'post_waiting', { plot_id: 12 } ) ).toContain(
			'open_plot=12'
		);
	} );

	it( "opens a player's news on the plot, Home or characters", () => {
		expect( href( 'plot_post', { plot_id: 5 } ) ).toContain(
			'open_plot=5'
		);
		expect( href( 'released' ) ).toContain( 'tab=plots' );
		expect( href( 'downtime_due', { plot_id: 8 } ) ).toContain(
			'open_plot=8'
		);
		expect( href( 'downtime_due', { plot_id: null } ) ).toContain(
			'tab=plots'
		);
		expect( href( 'join_answered' ) ).toContain( 'tab=dashboard' );
		expect( href( 'file_answered' ) ).toContain( 'tab=characters' );
		expect( href( 'plot_post', { plot_id: 5 } ) ).toContain(
			'game_slug=kony'
		);
	} );

	it( 'follows a visible-now link that points at this site and no other', () => {
		const own = `${ window.location.origin }/my-chronicle/?tab=what-i-know`;
		expect( href( 'now_visible', { url: own } ) ).toBe( own );
		expect(
			href( 'now_visible', { url: 'https://elsewhere.example/phish' } )
		).toContain( 'tab=plots' );
		expect( href( 'now_visible', {} ) ).toContain( 'tab=plots' );
	} );
} );
