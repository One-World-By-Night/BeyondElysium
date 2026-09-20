import { describeChronicleContent } from './chronicleContent';
import type { ChronicleContentCounts } from '../types';

const NONE: ChronicleContentCounts = {
	characters: 0,
	plots: 0,
	world_objects: 0,
	templates: 0,
	schema_blocks: 0,
	saved_queries: 0,
	attestations: 0,
	transfers: 0,
	factions: 0,
	positions: 0,
	secrets: 0,
	game_sessions: 0,
	attendance: 0,
	release_batches: 0,
	notification_queue: 0,
	npc_castings: 0,
	after_game_reports: 0,
};

describe( 'chronicleContent', () => {
	describe( 'describeChronicleContent', () => {
		it( 'names only what is there, singular and plural', () => {
			expect(
				describeChronicleContent( {
					...NONE,
					characters: 12,
					plots: 1,
					attestations: 2,
				} )
			).toBe( '12 characters, 1 plot, 2 verification codes' );
		} );

		it( 'covers every counted kind', () => {
			const all: ChronicleContentCounts = {
				characters: 1,
				plots: 1,
				world_objects: 1,
				templates: 1,
				schema_blocks: 1,
				saved_queries: 1,
				attestations: 1,
				transfers: 1,
				factions: 1,
				positions: 1,
				secrets: 1,
				game_sessions: 1,
				attendance: 1,
				release_batches: 1,
				notification_queue: 1,
				npc_castings: 1,
				after_game_reports: 1,
			};
			expect( describeChronicleContent( all ) ).toBe(
				'1 character, 1 plot, 1 world object, 1 sheet template, 1 customized schema block, 1 saved query, 1 verification code, 1 transfer, 1 faction, 1 position, 1 secret, 1 game session, 1 attendance record, 1 release batch, 1 queued notification, 1 NPC casting, 1 after-game report'
			);
		} );

		// D1 (1.2.5-design-workflow.md §D): the nine kinds the confirmation dialog never
		// named before this fix, so an admin deleting a chronicle without --with-content
		// could lose them with no warning at all.
		it( 'names the D1 content kinds the confirmation dialog used to silently skip', () => {
			expect(
				describeChronicleContent( {
					...NONE,
					factions: 2,
					positions: 3,
					secrets: 1,
					game_sessions: 5,
					attendance: 40,
					release_batches: 4,
					notification_queue: 6,
					npc_castings: 7,
					after_game_reports: 8,
				} )
			).toBe(
				'2 factions, 3 positions, 1 secret, 5 game sessions, 40 attendance records, 4 release batches, 6 queued notifications, 7 NPC castings, 8 after-game reports'
			);
		} );
	} );
} );
