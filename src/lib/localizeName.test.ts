import { isPortugueseLocale, localizedItemName, localizedPowerName } from './localizeName';

function setLocale( locale: string | undefined ) {
	( window as unknown as { beyondElysium?: { locale?: string } } ).beyondElysium = locale === undefined ? undefined : { locale };
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
		expect( localizedItemName( { name: 'Celerity', name_pt: 'Presteza' } ) ).toBe( 'Celerity' );
	} );

	it( 'localizedItemName returns name_pt on a pt_BR site when one exists', () => {
		setLocale( 'pt_BR' );
		expect( localizedItemName( { name: 'Celerity', name_pt: 'Presteza' } ) ).toBe( 'Presteza' );
	} );

	it( 'localizedItemName falls back to the canonical name on a pt_BR site when no translation exists yet', () => {
		setLocale( 'pt_BR' );
		expect( localizedItemName( { name: 'Some New Merit' } ) ).toBe( 'Some New Merit' );
	} );

	it( 'localizedPowerName follows the identical rule for a tiered_power level', () => {
		setLocale( 'pt_BR' );
		expect( localizedPowerName( { power_name: 'Alacrity', power_name_pt: 'Presteza' } ) ).toBe( 'Presteza' );
		expect( localizedPowerName( { power_name: 'Alacrity' } ) ).toBe( 'Alacrity' );

		setLocale( 'en_US' );
		expect( localizedPowerName( { power_name: 'Alacrity', power_name_pt: 'Presteza' } ) ).toBe( 'Alacrity' );
	} );
} );
