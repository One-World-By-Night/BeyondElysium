import { cardHref, needsYou, nextSessionLines } from './playerHome';
import type { PlayerHomeData } from '../types/home';

function home( over: Partial< PlayerHomeData > = {} ): PlayerHomeData {
	return {
		today: '2026-11-26',
		characters: [],
		due: { downtime: [], reports: [] },
		notices: { unread: 0, items: [] },
		next_session: null,
		plots: [],
		...over,
	};
}

describe( 'needsYou', () => {
	it( 'says nothing when nothing is due', () => {
		expect( needsYou( home(), 'kony' ) ).toEqual( [] );
	} );

	it( 'says open downtime with its deadline and who has sent nothing', () => {
		const [ item ] = needsYou(
			home( {
				due: {
					downtime: [
						{
							character_id: 7,
							character_name: 'Isolde Marchetti',
							game_date: '2026-11-28',
							deadline: '2026-11-27 23:59:00',
							plot_id: 12,
						},
					],
					reports: [],
				},
			} ),
			'kony'
		);

		expect( item.kind ).toBe( 'downtime' );
		expect( item.title ).toBe(
			'Downtime for Sat, Nov 28 is open until Fri, Nov 27, 23:59.'
		);
		expect( item.lines ).toEqual( [
			"Isolde Marchetti hasn't sent any actions.",
		] );
		expect( item.href ).toContain( 'tab=plots' );
		expect( item.href ).toContain( 'open_plot=12' );
		expect( item.href ).toContain( 'game_slug=kony' );
	} );

	it( 'says open downtime with no deadline, and goes to the plots when there is no plot yet', () => {
		const [ item ] = needsYou(
			home( {
				due: {
					downtime: [
						{
							character_id: 7,
							character_name: 'Isolde Marchetti',
							game_date: '2026-11-28',
							deadline: null,
							plot_id: null,
						},
					],
					reports: [],
				},
			} ),
			'kony'
		);

		expect( item.title ).toBe( 'Downtime for Sat, Nov 28 is open.' );
		expect( item.href ).toContain( 'tab=plots' );
		expect( item.href ).not.toContain( 'open_plot' );
	} );

	it( 'puts the soonest deadline first, then reports, then notices', () => {
		const items = needsYou(
			home( {
				due: {
					downtime: [
						{
							character_id: 1,
							character_name: 'Late',
							game_date: '2026-12-05',
							deadline: '2026-12-04 20:00:00',
							plot_id: null,
						},
						{
							character_id: 2,
							character_name: 'Open Ended',
							game_date: '2026-12-05',
							deadline: null,
							plot_id: null,
						},
						{
							character_id: 3,
							character_name: 'Soon',
							game_date: '2026-11-28',
							deadline: '2026-11-27 20:00:00',
							plot_id: null,
						},
					],
					reports: [
						{
							character_id: 3,
							character_name: 'Soon',
							session_id: 9,
							game_date: '2026-11-21',
							due_at: '2026-11-29 00:00:00',
						},
					],
				},
				notices: {
					unread: 1,
					items: [
						{
							id: 5,
							game_id: 1,
							kind: 'join_answered',
							payload: { chronicle: 'Kony' },
							created_at: '2026-11-25 10:00:00',
							read_at: null,
						},
					],
				},
			} ),
			'kony'
		);

		expect( items.map( ( i ) => i.kind ) ).toEqual( [
			'downtime',
			'downtime',
			'downtime',
			'report',
			'notice',
		] );
		expect( items[ 0 ].lines[ 0 ] ).toContain( 'Soon' );
		expect( items[ 1 ].lines[ 0 ] ).toContain( 'Late' );
		expect( items[ 2 ].lines[ 0 ] ).toContain( 'Open Ended' );
		expect( items[ 4 ].noticeId ).toBe( 5 );
	} );

	it( 'leaves out a downtime reminder the open-downtime line already says', () => {
		const reminder = (
			id: number,
			characterId: number,
			night: string
		) => ( {
			id,
			game_id: 1,
			kind: 'downtime_due',
			payload: {
				session_id: 9,
				character_id: characterId,
				character_name: 'X',
				game_date: night,
				deadline: '2026-11-27 18:00:00',
				plot_id: null,
			},
			created_at: '2026-11-26 09:00:00',
			read_at: null,
		} );
		const items = needsYou(
			home( {
				due: {
					downtime: [
						{
							character_id: 3,
							character_name: 'Isolde Marchetti',
							game_date: '2026-12-17',
							deadline: '2026-11-27 18:00:00',
							plot_id: null,
						},
					],
					reports: [],
				},
				notices: {
					unread: 2,
					items: [
						reminder( 11, 3, '2026-12-17' ),
						reminder( 12, 4, '2026-12-17' ),
					],
				},
			} ),
			'kony'
		);

		expect( items.map( ( i ) => i.kind ) ).toEqual( [
			'downtime',
			'notice',
		] );
		expect( items[ 1 ].noticeId ).toBe( 12 );
	} );

	it( 'sends a due report to the After-Game Report on that session and character', () => {
		const [ item ] = needsYou(
			home( {
				due: {
					downtime: [],
					reports: [
						{
							character_id: 7,
							character_name: 'Isolde Marchetti',
							session_id: 9,
							game_date: '2026-11-21',
							due_at: '2026-11-24 00:00:00',
						},
					],
				},
			} ),
			'kony'
		);

		expect( item.title ).toBe(
			'After-Game Report for Sat, Nov 21 is due Tue, Nov 24.'
		);
		expect( item.lines ).toEqual( [
			"Isolde Marchetti hasn't written one.",
		] );
		expect( item.href ).toContain( 'tab=after-game-report' );
		expect( item.href ).toContain( 'character_id=7' );
		expect( item.href ).toContain( 'session_id=9' );
	} );
} );

describe( 'cardHref', () => {
	it( 'opens the Character section on the view last used', () => {
		const href = cardHref( 7, 'kony' );
		expect( href ).toContain( 'tab=character' );
		expect( href ).toContain( 'character_id=7' );
		expect( href ).toContain( 'game_slug=kony' );
	} );
} );

describe( 'nextSessionLines', () => {
	it( 'says when downtime closes and who the person is playing', () => {
		expect(
			nextSessionLines( {
				id: 1,
				game_date: '2026-11-28',
				start_time: '7pm',
				place: 'The Chantry',
				downtime_opens_at: null,
				downtime_deadline_at: '2026-11-27 23:59:00',
				reports_due_at: null,
				castings: [ 'Ashkelon' ],
			} )
		).toEqual( [
			'Downtime closes Fri, Nov 27, 23:59',
			"You're playing Ashkelon",
		] );
	} );

	it( 'says nothing for a night with neither', () => {
		expect(
			nextSessionLines( {
				id: 1,
				game_date: '2026-11-28',
				start_time: null,
				place: null,
				downtime_opens_at: null,
				downtime_deadline_at: null,
				reports_due_at: null,
				castings: [],
			} )
		).toEqual( [] );
	} );
} );
