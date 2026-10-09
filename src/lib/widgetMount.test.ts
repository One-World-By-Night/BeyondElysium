import { markWidgetMount } from './widgetMount';

describe( 'markWidgetMount', () => {
	it( 'tells dark-mode plugins and translation plugins to skip the mount point', () => {
		const el = document.createElement( 'div' );
		markWidgetMount( el );

		expect( el.classList.contains( 'wp-dark-mode-ignore' ) ).toBe( true );
		expect( el.hasAttribute( 'data-no-translation' ) ).toBe( true );
	} );

	it( 'keeps the classes and attributes the mount point already has', () => {
		const el = document.createElement( 'div' );
		el.className = 'be-mount';
		el.setAttribute( 'data-be-widget', 'my-plots' );
		markWidgetMount( el );

		expect( el.classList.contains( 'be-mount' ) ).toBe( true );
		expect( el.getAttribute( 'data-be-widget' ) ).toBe( 'my-plots' );
	} );

	it( 'can run twice without changing anything further', () => {
		const el = document.createElement( 'div' );
		markWidgetMount( el );
		markWidgetMount( el );

		expect( el.className ).toBe( 'wp-dark-mode-ignore' );
	} );
} );
