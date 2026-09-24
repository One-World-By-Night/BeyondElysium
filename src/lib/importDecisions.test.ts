/**
 * Import decisions belong to one file and one merge target: nothing chosen
 * carries over to the next file or to another chronicle, a decision can be
 * withdrawn, an unmatched trait stops blocking once it is kept as written, and
 * a file uploaded again is a new job.
 */
import {
	blockingCount,
	decisionsFor,
	keyFor,
	noDecisions,
	previewKey,
	withDecision,
} from './importDecisions';
import type { ImportPreview } from '../types/import';

function previewOf( parts: Partial< ImportPreview > ): ImportPreview {
	return {
		job_id: 'job-a',
		format: 'GVBG',
		version: 3,
		counts: {} as ImportPreview[ 'counts' ],
		players_needing_match: [],
		flagged_traits: [],
		unresolved: [],
		duplicates: [],
		world_object_duplicates: [],
		warnings: [],
		...parts,
	} as ImportPreview;
}

describe( 'importDecisions', () => {
	const fileA = previewKey( 'job-a' );
	const fileB = previewKey( 'job-b' );

	it( 'offers nothing chosen for one file on the next one', () => {
		const decided = withDecision(
			noDecisions(),
			fileA,
			'duplicates',
			'Prince Marcus',
			'overwrite'
		);

		expect( decisionsFor( decided, fileA ).duplicates ).toEqual( {
			'Prince Marcus': 'overwrite',
		} );
		expect( decisionsFor( decided, fileB ).duplicates ).toEqual( {} );
	} );

	it( 'offers nothing chosen against one chronicle when merging into another, and keeps it for the same one', () => {
		const intoBoston = previewKey( 'job-a', 'boston' );
		const decided = withDecision(
			noDecisions(),
			intoBoston,
			'worldObjects',
			'item:Dagger',
			'skip'
		);

		expect(
			decisionsFor( decided, previewKey( 'job-a', 'kony' ) ).worldObjects
		).toEqual( {} );
		expect(
			decisionsFor( decided, previewKey( 'job-a' ) ).worldObjects
		).toEqual( {} );
		expect(
			decisionsFor( decided, previewKey( 'job-a', 'boston' ) )
				.worldObjects
		).toEqual( { 'item:Dagger': 'skip' } );
	} );

	it( "starts a new file from nothing rather than adding to the last file's choices", () => {
		const onA = withDecision(
			noDecisions(),
			fileA,
			'duplicates',
			'Prince Marcus',
			'overwrite'
		);
		const onB = withDecision(
			onA,
			fileB,
			'traits',
			'Marcus|disciplines|Celerity|0',
			{
				character: 'Marcus',
				block: 'disciplines',
				raw: 'Celerity',
				action: 'keep_custom',
			}
		);

		expect( decisionsFor( onB, fileB ) ).toEqual( {
			madeFor: fileB,
			traits: {
				'Marcus|disciplines|Celerity|0': {
					character: 'Marcus',
					block: 'disciplines',
					raw: 'Celerity',
					action: 'keep_custom',
				},
			},
			duplicates: {},
			worldObjects: {},
		} );
	} );

	it( 'withdraws a decision set back to nothing', () => {
		const decided = withDecision(
			noDecisions(),
			fileA,
			'duplicates',
			'Prince Marcus',
			'overwrite'
		);

		expect(
			decisionsFor(
				withDecision(
					decided,
					fileA,
					'duplicates',
					'Prince Marcus',
					null
				),
				fileA
			).duplicates
		).toEqual( {} );
	} );

	it( 'tells the same file apart when it is uploaded again, since that is a new job', () => {
		expect( previewKey( 'job-a' ) ).not.toBe( previewKey( 'job-a2' ) );
		expect( previewKey( 'job-a', '' ) ).toBe( previewKey( 'job-a' ) );
	} );
} );

/**
 * The game-file wizard counted its own way: every unmatched trait blocked even once kept as written, and any choice
 * at all resolved a character in another chronicle.
 */
describe( 'blockingCount', () => {
	const unmatched = {
		character: 'Ian Kincaid II',
		block: 'vampire-merits',
		raw: 'A Merit Nobody Catalogued',
	};

	it( 'stops counting an unmatched trait once it is kept as written', () => {
		const preview = previewOf( { unresolved: [ unmatched ] } );

		expect( blockingCount( preview, {}, {}, {} ) ).toBe( 1 );
		expect(
			blockingCount(
				preview,
				{
					[ keyFor( unmatched, 0 ) ]: {
						...unmatched,
						action: 'keep_custom',
					},
				},
				{},
				{}
			)
		).toBe( 0 );
	} );

	it( 'still counts a character from another chronicle set to Overwrite, which the server refuses', () => {
		const preview = previewOf( {
			duplicates: [
				{
					character: 'Ian Kincaid II',
					existing_id: 0,
					existing_uuid: 'u',
					matched_by: 'uuid_elsewhere',
				},
			],
		} );

		expect(
			blockingCount( preview, {}, { 'Ian Kincaid II': 'overwrite' }, {} )
		).toBe( 1 );
		expect(
			blockingCount(
				preview,
				{},
				{ 'Ian Kincaid II': 'import_as_new' },
				{}
			)
		).toBe( 0 );
	} );

	it( 'counts a duplicate as unresolved when the only decision for it was made on another file', () => {
		const preview = previewOf( {
			job_id: 'job-b',
			duplicates: [
				{
					character: 'Prince Marcus',
					existing_id: 7,
					existing_uuid: 'u',
					matched_by: 'name',
				},
			],
		} );
		const onFileA = withDecision(
			noDecisions(),
			previewKey( 'job-a' ),
			'duplicates',
			'Prince Marcus',
			'overwrite'
		);
		const onThisFile = decisionsFor(
			onFileA,
			previewKey( preview.job_id )
		);

		expect(
			blockingCount(
				preview,
				onThisFile.traits,
				onThisFile.duplicates,
				onThisFile.worldObjects
			)
		).toBe( 1 );
	} );
} );
