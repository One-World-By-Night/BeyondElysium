import { addFilter, removeFilter } from '@wordpress/hooks';
import { resetLocaleData, setLocaleData } from '@wordpress/i18n';
import {
	describeChange,
	describeChangeCost,
	describeChangeDetail,
} from './describeChange';
import changeDescriptionInput from '../../tests/fixtures/change-description-input.json';
import changeDescriptionExpected from '../../tests/fixtures/change-description-expected.json';

describe( 'describeChange', () => {
	it( 'shows a true before/after for a tiered_power level change', () => {
		expect(
			describeChange( 'modify_trait', {
				trait: { name: 'Celerity', level: 4 },
				previous: { name: 'Celerity', level: 2 },
			} )
		).toBe( 'Celerity 2 → 4' );
	} );

	it( 'degrades to only the new value when previous is absent', () => {
		expect(
			describeChange( 'modify_trait', {
				trait: { name: 'Celerity', level: 4 },
			} )
		).toBe( 'Celerity → level 4' );
	} );

	it( 'shows a true before/after for a trait_list count change', () => {
		expect(
			describeChange( 'modify_trait', {
				trait: { name: 'Boon', count: 3 },
				previous: { name: 'Boon', count: 1 },
			} )
		).toBe( 'Boon x1 → x3' );
	} );

	it( 'describes adding a new tiered power', () => {
		expect(
			describeChange( 'add_trait', {
				trait: { name: 'Fortitude', level: 1 },
			} )
		).toBe( 'Added Fortitude 1' );
	} );

	it( 'describes adding a trait_list item with a specialization', () => {
		expect(
			describeChange( 'add_trait', {
				trait: { name: 'Contacts', count: 1, specialization: 'Police' },
			} )
		).toBe( 'Added Contacts (Police)' );
	} );

	it( 'describes removing a trait', () => {
		expect(
			describeChange( 'remove_trait', { trait: { name: 'Potence' } } )
		).toBe( 'Removed Potence' );
	} );

	it( 'describes a resource pool change', () => {
		expect(
			describeChange( 'modify_resource', {
				values: { Blood: { permanent: 10, temporary: 8 } },
			} )
		).toBe( 'Blood: 10 perm / 8 temp' );
	} );

	it( 'describes an identity field change', () => {
		expect(
			describeChange( 'modify_identity', {
				fields: { Clan: 'Toreador' },
			} )
		).toBe( 'Clan → Toreador' );
	} );

	it( 'describes XP earn with a reason', () => {
		expect(
			describeChange( 'xp_earn', {
				amount: 3,
				reason: 'Game attendance',
			} )
		).toBe( '+3 XP (Game attendance)' );
	} );
} );

/**
 * Same fixture, same expected output as `tests/unit/Display/ChangeDescriptionParityTest.php`.
 */
describe( 'describeChange — parity with Change_Description.php', () => {
	changeDescriptionInput.forEach( ( testCase, i ) => {
		it( `matches the shared fixture: ${ testCase.name }`, () => {
			expect(
				describeChange(
					testCase.change_type as Parameters<
						typeof describeChange
					>[ 0 ],
					testCase.change_data
				)
			).toBe( changeDescriptionExpected[ i ].output );
		} );
	} );
} );

/**
 * The words were bare English that never reached the translation file.
 */
describe( 'describeChange — translation', () => {
	afterEach( () => {
		removeFilter( 'i18n.gettext_beyond-elysium', 'beyond-elysium/test' );
		removeFilter( 'i18n.ngettext_beyond-elysium', 'beyond-elysium/test' );
		resetLocaleData( undefined, 'beyond-elysium' );
	} );

	it( "reads a catalog update in the reader's own plural forms", () => {
		setLocaleData(
			{
				'': {
					domain: 'beyond-elysium',
					plural_forms: 'nplurals=2; plural=(n > 1);',
				},
				'Catalog update: %s': [ 'Atualização do catálogo: %s' ],
				'%d row moved to its new catalog section': [
					'%d linha movida para a nova seção do catálogo',
					'%d linhas movidas para as novas seções do catálogo',
				],
				'%d custom entry matched to the catalog': [
					'%d item personalizado associado ao catálogo',
					'%d itens personalizados associados ao catálogo',
				],
			},
			'beyond-elysium'
		);

		expect(
			describeChange( 'catalog_rekey', {
				counts: { moved_rows: 24, rekeyed: 1 },
			} )
		).toBe(
			'Atualização do catálogo: 24 linhas movidas para as novas seções do catálogo, 1 item personalizado associado ao catálogo'
		);
	} );

	it( "reads a translated chronicle's own words around the change's names", () => {
		setLocaleData(
			{
				'': { domain: 'beyond-elysium' },
				'Added %1$s x%2$s%3$s': [ 'Adicionado %1$s x%2$s%3$s' ],
				'%1$s: %2$s perm / %3$s temp': [
					'%1$s: %2$s perm. / %3$s temp.',
				],
				'Unknown change': [ 'Alteração desconhecida' ],
			},
			'beyond-elysium'
		);

		expect(
			describeChange( 'add_trait', {
				trait: { name: 'Occult', count: 3 },
			} )
		).toBe( 'Adicionado Occult x3' );
		expect(
			describeChange( 'modify_resource', {
				values: { Blood: { permanent: 10, temporary: 8 } },
			} )
		).toBe( 'Blood: 10 perm. / 8 temp.' );
		expect(
			describeChange(
				'something_else' as Parameters< typeof describeChange >[ 0 ],
				{}
			)
		).toBe( 'Alteração desconhecida' );
	} );

	it( 'leaves no word of any description outside translation', () => {
		// Marks every translated phrase; what is left once the marks are taken out was never translated.
		addFilter(
			'i18n.gettext_beyond-elysium',
			'beyond-elysium/test',
			( translation: string ) => `⟦${ translation }⟧`
		);
		addFilter(
			'i18n.ngettext_beyond-elysium',
			'beyond-elysium/test',
			( translation: string ) => `⟦${ translation }⟧`
		);

		changeDescriptionInput.forEach( ( testCase ) => {
			const described = describeChange(
				testCase.change_type as Parameters<
					typeof describeChange
				>[ 0 ],
				testCase.change_data
			);
			let left = described;
			let before;
			do {
				before = left;
				left = left.replace( /⟦[^⟦⟧]*⟧/g, '' );
			} while ( left !== before );

			const ownText =
				testCase.change_type === 'import_note'
					? ( testCase.change_data as { reason?: string } ).reason ??
					  ''
					: '';
			expect( { case: testCase.name, left } ).toEqual( {
				case: testCase.name,
				left: ownText,
			} );
		} );
	} );
} );

describe( 'describeChangeDetail', () => {
	const records = [
		{ outcome: 'rekeyed', from: 'Alertness', to: 'Alertness' },
		{
			outcome: 'rekeyed',
			from: 'Lore: Kindred',
			to: 'Lore',
			label: 'Kindred',
		},
		{ outcome: 'kept', from: 'Basket Weaving', reason: 'no_match' },
		{ outcome: 'kept', from: 'Brawling', to: 'Brawl', reason: 'collision' },
	];

	it( 'lists each entry a catalog update matched, and only those', () => {
		expect( describeChangeDetail( 'catalog_rekey', { records } ) ).toEqual(
			[ 'Alertness → Alertness', 'Lore: Kindred → Lore (Kindred)' ]
		);
	} );

	it( 'lists nothing for a catalog update that matched nothing', () => {
		expect(
			describeChangeDetail( 'catalog_rekey', {
				records: [ records[ 2 ] ],
			} )
		).toEqual( [] );
		expect( describeChangeDetail( 'catalog_rekey', {} ) ).toEqual( [] );
	} );

	it( 'lists nothing for any other kind of change, even one that carries records', () => {
		expect(
			describeChangeDetail( 'catalog_rekey_revert', { records } )
		).toEqual( [] );
		expect( describeChangeDetail( 'import_note', { records } ) ).toEqual(
			[]
		);
	} );
} );

describe( 'describeChangeCost', () => {
	it( 'says nothing for a change that cost nothing, however the zero arrives', () => {
		expect( describeChangeCost( 0 ) ).toBeNull();
		expect( describeChangeCost( '0' ) ).toBeNull();
		expect( describeChangeCost( '0.00' ) ).toBeNull();
		expect( describeChangeCost( '' ) ).toBeNull();
	} );

	it( 'signs a cost and a refund', () => {
		expect( describeChangeCost( '3.00' ) ).toBe( '+3 XP' );
		expect( describeChangeCost( 12 ) ).toBe( '+12 XP' );
		expect( describeChangeCost( -2 ) ).toBe( '-2 XP' );
		expect( describeChangeCost( '-2.00' ) ).toBe( '-2 XP' );
	} );
} );
