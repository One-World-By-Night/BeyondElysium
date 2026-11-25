import { plotDisplayTitle, plotTitleById } from './plotTitle';

afterEach( () => {
	delete window.beyondElysium;
} );

describe( 'plotDisplayTitle', () => {
	it( "shows a character's own plot without its id", () => {
		expect( plotDisplayTitle( 'Detective Rosa Alvarez [3219] Plot' ) ).toBe(
			"Detective Rosa Alvarez's plot"
		);
	} );

	it( 'keeps brackets that are part of the name', () => {
		expect( plotDisplayTitle( 'Raine [the Younger] [301] Plot' ) ).toBe(
			"Raine [the Younger]'s plot"
		);
	} );

	it( 'leaves a title a Storyteller wrote as written', () => {
		expect( plotDisplayTitle( 'The Siege of Boston' ) ).toBe(
			'The Siege of Boston'
		);
		expect( plotDisplayTitle( 'Raine [301] Plot twist' ) ).toBe(
			'Raine [301] Plot twist'
		);
		expect( plotDisplayTitle( 'Raine [abc] Plot' ) ).toBe(
			'Raine [abc] Plot'
		);
		expect( plotDisplayTitle( '[301] Plot' ) ).toBe( '[301] Plot' );
		expect( plotDisplayTitle( '' ) ).toBe( '' );
	} );
} );

describe( 'plotTitleById', () => {
	const plots = [
		{ id: 3219, title: 'Detective Rosa Alvarez [3219] Plot' },
		{ id: '7', title: 'The Siege of Boston' },
	];

	it( 'gives the display title of the plot with that id', () => {
		expect( plotTitleById( plots, 3219 ) ).toBe(
			"Detective Rosa Alvarez's plot"
		);
		expect( plotTitleById( plots, '3219' ) ).toBe(
			"Detective Rosa Alvarez's plot"
		);
		expect( plotTitleById( plots, 7 ) ).toBe( 'The Siege of Boston' );
	} );

	it( 'is null when the list holds no such plot', () => {
		expect( plotTitleById( plots, 99 ) ).toBeNull();
		expect( plotTitleById( [], 1 ) ).toBeNull();
	} );
} );
