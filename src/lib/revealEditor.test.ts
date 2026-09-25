import { revealEditor } from './revealEditor';

function panel( html: string ): HTMLElement {
	const el = document.createElement( 'form' );
	el.innerHTML = html;
	document.body.appendChild( el );
	el.scrollIntoView = jest.fn();
	return el;
}

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'revealEditor', () => {
	it( 'does nothing and reports false when there is no editor', () => {
		expect( revealEditor( null ) ).toBe( false );
	} );

	it( 'scrolls the editor to the top of the view', () => {
		const el = panel( '<input name="name" />' );

		expect( revealEditor( el ) ).toBe( true );
		expect( el.scrollIntoView ).toHaveBeenCalledWith( {
			behavior: 'smooth',
			block: 'start',
		} );
	} );

	it( 'focuses the first field that can take input', () => {
		const el = panel(
			'<input type="hidden" name="id" /><input name="slug" disabled /><select name="type"><option>a</option></select><input name="name" />'
		);

		revealEditor( el );

		expect( el.ownerDocument.activeElement ).toBe(
			el.querySelector( 'select[name="type"]' )
		);
	} );

	it( 'focuses the editor itself when it holds no usable field', () => {
		const el = panel( '<p>Nothing to edit</p>' );
		el.tabIndex = -1;

		revealEditor( el );

		expect( el.ownerDocument.activeElement ).toBe( el );
	} );
} );
