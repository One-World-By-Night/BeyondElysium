import { readFileSync } from 'fs';
import { join } from 'path';
import { isRowOpen, rowControl } from './setupRows';

describe( 'isRowOpen', () => {
	it( 'opens a row that needs attention and folds every other row', () => {
		expect( isRowOpen( {}, { id: 'a', status: 'attention' } ) ).toBe(
			true
		);
		expect( isRowOpen( {}, { id: 'a', status: 'ok' } ) ).toBe( false );
		expect( isRowOpen( {}, { id: 'a', status: 'info' } ) ).toBe( false );
	} );

	it( 'lets the viewer close a row that started open and open one that started folded', () => {
		expect(
			isRowOpen( { a: false }, { id: 'a', status: 'attention' } )
		).toBe( false );
		expect( isRowOpen( { a: true }, { id: 'a', status: 'info' } ) ).toBe(
			true
		);
	} );

	it( 'folds a row that was open only because it needed attention once it no longer does', () => {
		expect( isRowOpen( {}, { id: 'a', status: 'attention' } ) ).toBe(
			true
		);
		expect( isRowOpen( {}, { id: 'a', status: 'ok' } ) ).toBe( false );
	} );

	it( 'keeps a row the viewer opened open after it turns green', () => {
		expect( isRowOpen( { a: true }, { id: 'a', status: 'ok' } ) ).toBe(
			true
		);
	} );

	it( 'treats each row on its own', () => {
		expect( isRowOpen( { a: true }, { id: 'b', status: 'ok' } ) ).toBe(
			false
		);
	} );
} );

describe( 'rowControl', () => {
	const link = { kind: 'link', href: '/somewhere' } as const;
	const inline = { kind: 'inline' } as const;

	it( 'gives a row with a panel a toggle when the viewer can act, and a note when not', () => {
		expect(
			rowControl(
				{ actionable: true, fix: inline },
				{ hasPanel: true, hasExtra: false }
			)
		).toBe( 'toggle' );
		expect(
			rowControl(
				{ actionable: false, fix: inline },
				{ hasPanel: true, hasExtra: false }
			)
		).toBe( 'note' );
	} );

	it( 'gives a row with its own controls those controls and nothing else', () => {
		expect(
			rowControl(
				{ actionable: true, fix: link },
				{ hasPanel: true, hasExtra: true }
			)
		).toBe( 'extra' );
	} );

	it( 'links a row with no panel to where it is fixed, and leaves one with nowhere to go alone', () => {
		expect(
			rowControl(
				{ actionable: true, fix: link },
				{ hasPanel: false, hasExtra: false }
			)
		).toBe( 'link' );
		expect(
			rowControl(
				{ actionable: true, fix: inline },
				{ hasPanel: false, hasExtra: false }
			)
		).toBe( 'none' );
	} );
} );

describe( 'the Chronicle Setup rows', () => {
	it( 'are given their own controls as a prop, never as children that can all be empty', () => {
		const source = readFileSync(
			join( __dirname, '../components/admin/AdminChronicleSetup.tsx' ),
			'utf-8'
		);
		expect( source ).not.toMatch( /<\/StatusRow>/ );
	} );
} );
