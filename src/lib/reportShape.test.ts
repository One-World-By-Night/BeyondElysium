import { reportShapeLabel } from './reportShape';

describe( 'reportShapeLabel', () => {
	it( 'names each shape a report has', () => {
		expect( reportShapeLabel( 'table' ) ).toBe( 'Table' );
		expect( reportShapeLabel( 'card' ) ).toBe( 'Cards' );
		expect( reportShapeLabel( 'statistics' ) ).toBe( 'Statistics' );
		expect( reportShapeLabel( 'narrative' ) ).toBe( 'Narrative' );
		expect( reportShapeLabel( 'calendar' ) ).toBe( 'Calendar' );
		expect( reportShapeLabel( 'house_rules' ) ).toBe( 'House rules' );
	} );

	it( 'gives a shape it does not know as it was stored', () => {
		expect( reportShapeLabel( 'something_new' ) ).toBe( 'something_new' );
	} );
} );
