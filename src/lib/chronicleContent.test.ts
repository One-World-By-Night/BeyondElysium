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
			const all = {
				characters: 1,
				plots: 1,
				world_objects: 1,
				templates: 1,
				schema_blocks: 1,
				saved_queries: 1,
				attestations: 1,
				transfers: 1,
			};
			expect( describeChronicleContent( all ) ).toBe(
				'1 character, 1 plot, 1 world object, 1 sheet template, 1 customized schema block, 1 saved query, 1 verification code, 1 transfer'
			);
		} );
	} );
} );
