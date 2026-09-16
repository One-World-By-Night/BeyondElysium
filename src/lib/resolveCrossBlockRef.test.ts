import {
	resolveCrossBlockValue,
	resolveSectionTitle,
	resolvePoolName,
} from './resolveCrossBlockRef';
import type {
	TemplateLayoutSection,
	ResourcePool,
	CrossBlockRef,
} from '../types';
import input from '../../tests/fixtures/cross-block-ref-input.json';
import expected from '../../tests/fixtures/cross-block-ref-expected.json';

function section(
	overrides: Partial< TemplateLayoutSection >
): TemplateLayoutSection {
	return {
		block_slug: 'x',
		column: 1,
		order: 1,
		title: 'Virtues',
		display: null,
		collapsed: false,
		...overrides,
	};
}

function pool( overrides: Partial< ResourcePool > ): ResourcePool {
	return {
		name: 'Conscience',
		value_type: 'integer',
		default_start: 1,
		...overrides,
	};
}

describe( 'resolveCrossBlockValue', () => {
	it( 'reads a plain identity_field value', () => {
		const sheetData = {
			'vampire-identity': { 'Morality Path': 'Path of Caine' },
		};
		expect(
			resolveCrossBlockValue(
				{ block_slug: 'vampire-identity', field: 'Morality Path' },
				sheetData
			)
		).toBe( 'Path of Caine' );
	} );

	it( 'reads a resource_pool value by its permanent rating, not the whole object', () => {
		const sheetData = {
			'vampire-resources': { Morality: { permanent: 8, temporary: 8 } },
		};
		expect(
			resolveCrossBlockValue(
				{ block_slug: 'vampire-resources', field: 'Morality' },
				sheetData
			)
		).toBe( '8' );
	} );

	it( 'returns null when the block is absent', () => {
		expect(
			resolveCrossBlockValue(
				{ block_slug: 'vampire-identity', field: 'Morality Path' },
				{}
			)
		).toBeNull();
	} );

	it( 'returns null when the field is unset (no Path chosen yet)', () => {
		const sheetData = { 'vampire-identity': { Clan: 'Ventrue' } };
		expect(
			resolveCrossBlockValue(
				{ block_slug: 'vampire-identity', field: 'Morality Path' },
				sheetData
			)
		).toBeNull();
	} );

	it( 'returns null for an empty string, not the literal ""', () => {
		const sheetData = { 'vampire-identity': { 'Morality Path': '' } };
		expect(
			resolveCrossBlockValue(
				{ block_slug: 'vampire-identity', field: 'Morality Path' },
				sheetData
			)
		).toBeNull();
	} );
} );

describe( 'resolveSectionTitle', () => {
	it( 'returns the bare title when title_refs is unset - every section authored before this field existed', () => {
		expect( resolveSectionTitle( section( {} ), {} ) ).toBe( 'Virtues' );
	} );

	it( 'appends every resolved ref, space-joined, when all resolve', () => {
		const withRefs = section( {
			title_refs: [
				{ block_slug: 'vampire-identity', field: 'Morality Path' },
				{ block_slug: 'vampire-resources', field: 'Morality' },
			],
		} );
		const sheetData = {
			'vampire-identity': { 'Morality Path': 'Path of Caine' },
			'vampire-resources': { Morality: { permanent: 8, temporary: 8 } },
		};

		expect( resolveSectionTitle( withRefs, sheetData ) ).toBe(
			'Virtues Path of Caine 8'
		);
	} );

	it( 'falls back to the bare title the moment any one ref fails to resolve - no Path chosen yet', () => {
		const withRefs = section( {
			title_refs: [
				{ block_slug: 'vampire-identity', field: 'Morality Path' },
				{ block_slug: 'vampire-resources', field: 'Morality' },
			],
		} );
		const sheetData = {
			'vampire-resources': { Morality: { permanent: 7, temporary: 7 } },
		};

		expect( resolveSectionTitle( withRefs, sheetData ) ).toBe( 'Virtues' );
	} );
} );

describe( 'resolvePoolName', () => {
	it( 'returns the plain name when name_lookup is unset - every pool authored before this field existed', () => {
		expect( resolvePoolName( pool( {} ), {} ) ).toBe( 'Conscience' );
	} );

	it( 'returns the looked-up override when the keyed value has a table entry', () => {
		const withLookup = pool( {
			name_lookup: {
				keyed_by: {
					block_slug: 'vampire-identity',
					field: 'Morality Path',
				},
				table: { 'Path of Caine': 'Conviction' },
			},
		} );
		const sheetData = {
			'vampire-identity': { 'Morality Path': 'Path of Caine' },
		};

		expect( resolvePoolName( withLookup, sheetData ) ).toBe( 'Conviction' );
	} );

	it( 'falls back to the plain name when the keyed value has no table entry (e.g. Humanity)', () => {
		const withLookup = pool( {
			name_lookup: {
				keyed_by: {
					block_slug: 'vampire-identity',
					field: 'Morality Path',
				},
				table: { 'Path of Caine': 'Conviction' },
			},
		} );
		const sheetData = {
			'vampire-identity': { 'Morality Path': 'Humanity' },
		};

		expect( resolvePoolName( withLookup, sheetData ) ).toBe( 'Conscience' );
	} );

	it( 'falls back to the plain name when no Path has been chosen at all', () => {
		const withLookup = pool( {
			name_lookup: {
				keyed_by: {
					block_slug: 'vampire-identity',
					field: 'Morality Path',
				},
				table: { 'Path of Caine': 'Conviction' },
			},
		} );

		expect( resolvePoolName( withLookup, {} ) ).toBe( 'Conscience' );
	} );

	it( "never changes the pool's own name field - only what a caller displays", () => {
		const withLookup = pool( {
			name_lookup: {
				keyed_by: {
					block_slug: 'vampire-identity',
					field: 'Morality Path',
				},
				table: { 'Path of Caine': 'Conviction' },
			},
		} );
		const sheetData = {
			'vampire-identity': { 'Morality Path': 'Path of Caine' },
		};

		resolvePoolName( withLookup, sheetData );

		expect( withLookup.name ).toBe( 'Conscience' );
	} );
} );

/**
 * Same fixture, same expected output as
 * `tests/unit/Display/CrossBlockRefParityTest.php` - this is the TypeScript half of
 * proving the two implementations agree.
 */
describe( 'resolveCrossBlockRef — parity with Cross_Block_Ref.php', () => {
	it( 'resolveCrossBlockValue matches the shared fixture exactly', () => {
		input.resolveCrossBlockValue.forEach( ( testCase, i ) => {
			const actual = resolveCrossBlockValue(
				testCase.ref as CrossBlockRef,
				testCase.sheetData as Record< string, unknown >
			);
			expect( actual ).toBe( expected.resolveCrossBlockValue[ i ] );
		} );
	} );

	it( 'resolveSectionTitle matches the shared fixture exactly', () => {
		input.resolveSectionTitle.forEach( ( testCase, i ) => {
			const actual = resolveSectionTitle(
				testCase.section as unknown as TemplateLayoutSection,
				testCase.sheetData as Record< string, unknown >
			);
			expect( actual ).toBe( expected.resolveSectionTitle[ i ] );
		} );
	} );

	it( 'resolvePoolName matches the shared fixture exactly', () => {
		input.resolvePoolName.forEach( ( testCase, i ) => {
			const actual = resolvePoolName(
				testCase.pool as unknown as ResourcePool,
				testCase.sheetData as Record< string, unknown >
			);
			expect( actual ).toBe( expected.resolvePoolName[ i ] );
		} );
	} );
} );
