import {
	isPortugueseLocale,
	localizedFieldLabel,
	localizedIdentityValue,
	localizedItemName,
	localizedPoolLabel,
	localizedPowerName,
} from './localizeName';

function setLocale( locale: string | undefined ) {
	(
		window as unknown as { beyondElysium?: { locale?: string } }
	 ).beyondElysium = locale === undefined ? undefined : { locale };
}

describe( 'localizeName', () => {
	afterEach( () => {
		setLocale( undefined );
	} );

	it( 'isPortugueseLocale is false when no locale is set', () => {
		expect( isPortugueseLocale() ).toBe( false );
	} );

	it( 'isPortugueseLocale is false for an English site', () => {
		setLocale( 'en_US' );
		expect( isPortugueseLocale() ).toBe( false );
	} );

	it( 'isPortugueseLocale is true for pt_BR', () => {
		setLocale( 'pt_BR' );
		expect( isPortugueseLocale() ).toBe( true );
	} );

	it( 'localizedItemName returns the canonical name on an English site even with a translation present', () => {
		setLocale( 'en_US' );
		expect(
			localizedItemName( { name: 'Celerity', name_pt: 'Presteza' } )
		).toBe( 'Celerity' );
	} );

	it( 'localizedItemName returns name_pt on a pt_BR site when one exists', () => {
		setLocale( 'pt_BR' );
		expect(
			localizedItemName( { name: 'Celerity', name_pt: 'Presteza' } )
		).toBe( 'Presteza' );
	} );

	it( 'localizedItemName falls back to the canonical name on a pt_BR site when no translation exists yet', () => {
		setLocale( 'pt_BR' );
		expect( localizedItemName( { name: 'Some New Merit' } ) ).toBe(
			'Some New Merit'
		);
	} );

	it( 'localizedPowerName follows the identical rule for a tiered_power level', () => {
		setLocale( 'pt_BR' );
		expect(
			localizedPowerName( {
				power_name: 'Alacrity',
				power_name_pt: 'Presteza',
			} )
		).toBe( 'Presteza' );
		expect( localizedPowerName( { power_name: 'Alacrity' } ) ).toBe(
			'Alacrity'
		);

		setLocale( 'en_US' );
		expect(
			localizedPowerName( {
				power_name: 'Alacrity',
				power_name_pt: 'Presteza',
			} )
		).toBe( 'Alacrity' );
	} );

	it( 'localizedFieldLabel follows the identical rule for an identity_field', () => {
		setLocale( 'pt_BR' );
		expect( localizedFieldLabel( { name: 'Clan', label_pt: 'Clã' } ) ).toBe(
			'Clã'
		);
		expect( localizedFieldLabel( { name: 'Concept' } ) ).toBe( 'Concept' );

		setLocale( 'en_US' );
		expect( localizedFieldLabel( { name: 'Clan', label_pt: 'Clã' } ) ).toBe(
			'Clan'
		);
	} );

	it( 'localizedPoolLabel follows the identical rule for a resource_pool', () => {
		setLocale( 'pt_BR' );
		expect(
			localizedPoolLabel( {
				name: 'Willpower',
				label_pt: 'Força de Vontade',
			} )
		).toBe( 'Força de Vontade' );

		setLocale( 'en_US' );
		expect(
			localizedPoolLabel( {
				name: 'Willpower',
				label_pt: 'Força de Vontade',
			} )
		).toBe( 'Willpower' );
	} );

	it( 'localizedIdentityValue passes any value through untouched on an English site', () => {
		setLocale( 'en_US' );
		expect(
			localizedIdentityValue(
				{ options_pt: { Assamite: 'Assamita' } },
				'Assamite'
			)
		).toBe( 'Assamite' );
	} );

	it( 'localizedIdentityValue translates a plain string value on a pt_BR site', () => {
		setLocale( 'pt_BR' );
		expect(
			localizedIdentityValue(
				{ options_pt: { Assamite: 'Assamita' } },
				'Assamite'
			)
		).toBe( 'Assamita' );
	} );

	it( 'localizedIdentityValue translates each choice of a multiselect value independently', () => {
		setLocale( 'pt_BR' );
		expect(
			localizedIdentityValue(
				{ options_pt: { Ally: 'Aliado', Contact: 'Contato' } },
				[ 'Ally', 'Contact', 'Resources' ]
			)
		).toEqual( [ 'Aliado', 'Contato', 'Resources' ] );
	} );

	it( 'localizedIdentityValue leaves a non-string value (a number field) untouched', () => {
		setLocale( 'pt_BR' );
		expect(
			localizedIdentityValue( { options_pt: { '1': 'um' } }, 3 )
		).toBe( 3 );
	} );

	it( 'localizedIdentityValue falls back to the value itself with no options_pt map at all', () => {
		setLocale( 'pt_BR' );
		expect( localizedIdentityValue( {}, 'Assamite' ) ).toBe( 'Assamite' );
	} );
} );
