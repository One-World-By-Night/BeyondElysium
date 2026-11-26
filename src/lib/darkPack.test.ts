import { footerNotice } from './darkPack';

const AGREEMENT =
	'Portions of the materials are the copyrights and trademarks of Paradox Interactive AB, and are used with permission. All rights reserved. For more information please visit worldofdarkness.com.';

describe( "the app footer's Dark Pack lines", () => {
	it( "carries Paradox's notice word for word, linking worldofdarkness.com", () => {
		const footer = footerNotice( '' );
		expect( `${ footer.notice } ${ footer.linkText }.` ).toBe( AGREEMENT );
		expect( footer.linkUrl ).toBe( 'https://www.worldofdarkness.com/' );
	} );

	it( 'says the project is a non-commercial community one', () => {
		expect( footerNotice( '' ).community ).toBe(
			'Beyond Elysium is a non-commercial community project, not official World of Darkness material.'
		);
	} );

	it( "serves the logo from the plugin's own images, and none without them", () => {
		expect(
			footerNotice(
				'https://example.test/wp-content/plugins/beyond-elysium/assets/'
			).logoUrl
		).toBe(
			'https://example.test/wp-content/plugins/beyond-elysium/assets/dark-pack-logo.png'
		);
		expect( footerNotice( '' ).logoUrl ).toBe( '' );
	} );
} );
