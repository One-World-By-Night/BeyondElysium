import { describe, expect, it } from 'vitest';
import { stackableProseTables } from './stackableProseTables';

function parse( html: string ): HTMLElement {
	const root = document.createElement( 'div' );
	root.innerHTML = html;
	return root;
}

describe( 'stackableProseTables', () => {
	it( 'returns text without a table unchanged', () => {
		expect( stackableProseTables( '<p>No table here.</p>' ) ).toBe(
			'<p>No table here.</p>'
		);
		expect( stackableProseTables( '' ) ).toBe( '' );
	} );

	it( 'wraps a table in a table box and joins the responsive mechanism', () => {
		const root = parse(
			stackableProseTables(
				'<p>Before</p><table><thead><tr><th>Name</th><th>Role</th></tr></thead><tbody><tr><td>Sophia</td><td>Ductus</td></tr></tbody></table><p>After</p>'
			)
		);

		const table = root.querySelector( '.be-table-box > table' );
		expect( table ).not.toBeNull();
		expect( table?.classList.contains( 'be-responsive-table' ) ).toBe(
			true
		);
		expect( root.querySelector( 'p' )?.textContent ).toBe( 'Before' );
		expect( root.lastElementChild?.textContent ).toBe( 'After' );
	} );

	it( 'labels each body cell with its column heading', () => {
		const root = parse(
			stackableProseTables(
				'<table><thead><tr><th>Name</th><th>Role</th></tr></thead><tbody><tr><td>Sophia</td><td>Ductus</td></tr><tr><td>Hannah</td><td>Priest</td></tr></tbody></table>'
			)
		);

		const labels = Array.from( root.querySelectorAll( 'td' ) ).map(
			( cell ) => cell.getAttribute( 'data-label' )
		);
		expect( labels ).toEqual( [ 'Name', 'Role', 'Name', 'Role' ] );
		expect( root.querySelectorAll( 'th[data-label]' ) ).toHaveLength( 0 );
	} );

	it( 'drops Storyteller markers from a label and keeps them in the cell', () => {
		const root = parse(
			stackableProseTables(
				'<table><thead><tr><th>[ST]Faction[/ST]</th></tr></thead><tbody><tr><td>[ST]Moderates[/ST]</td></tr></tbody></table>'
			)
		);

		const cell = root.querySelector( 'td' );
		expect( cell?.getAttribute( 'data-label' ) ).toBe( 'Faction' );
		expect( cell?.textContent ).toBe( '[ST]Moderates[/ST]' );
	} );

	it( 'reads the headings from a header-only first row when there is no thead', () => {
		const root = parse(
			stackableProseTables(
				'<table><tr><th>Name</th><th>Role</th></tr><tr><td>Sophia</td><td>Ductus</td></tr></table>'
			)
		);

		const labels = Array.from( root.querySelectorAll( 'td' ) ).map(
			( cell ) => cell.getAttribute( 'data-label' )
		);
		expect( labels ).toEqual( [ 'Name', 'Role' ] );
	} );

	it( 'leaves cells unlabelled when the table has no heading row', () => {
		const root = parse(
			stackableProseTables(
				'<table><tbody><tr><td>One</td><td>Two</td></tr></tbody></table>'
			)
		);

		expect( root.querySelectorAll( 'td[data-label]' ) ).toHaveLength( 0 );
		expect( root.querySelector( '.be-table-box > table' ) ).not.toBeNull();
	} );

	it( 'drops body rows whose cells are all empty', () => {
		const root = parse(
			stackableProseTables(
				'<table><thead><tr><th>Name</th><th>Role</th></tr></thead><tbody><tr><td></td><td> </td></tr><tr><td>Sophia</td><td>Ductus</td></tr></tbody></table>'
			)
		);

		expect( root.querySelectorAll( 'tbody tr' ) ).toHaveLength( 1 );
		expect( root.querySelector( 'tbody td' )?.textContent ).toBe(
			'Sophia'
		);
	} );

	it( 'drops a table whose every body row is empty', () => {
		const html = stackableProseTables(
			'<p>Before</p><table><thead><tr><th></th><th></th></tr></thead><tbody><tr><td></td><td></td></tr><tr><td></td><td></td></tr></tbody></table><p>After</p>'
		);
		const root = parse( html );

		expect( root.querySelector( 'table' ) ).toBeNull();
		expect( root.querySelector( '.be-table-box' ) ).toBeNull();
		expect( root.textContent ).toBe( 'BeforeAfter' );
	} );

	it( 'keeps a row whose only content is an image', () => {
		const root = parse(
			stackableProseTables(
				'<table><tbody><tr><td><img src="a.png" alt=""></td></tr></tbody></table>'
			)
		);

		expect( root.querySelectorAll( 'tbody tr' ) ).toHaveLength( 1 );
	} );

	it( 'handles several tables', () => {
		const root = parse(
			stackableProseTables(
				'<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table><table><thead><tr><th>B</th></tr></thead><tbody><tr><td>2</td></tr></tbody></table>'
			)
		);

		expect( root.querySelectorAll( '.be-table-box' ) ).toHaveLength( 2 );
		const labels = Array.from( root.querySelectorAll( 'td' ) ).map(
			( cell ) => cell.getAttribute( 'data-label' )
		);
		expect( labels ).toEqual( [ 'A', 'B' ] );
	} );
} );
