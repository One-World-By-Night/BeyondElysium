import { compendiumHref, compendiumTarget } from './compendiumLinks';

describe( 'compendiumHref', () => {
	it( 'opens the compendium tab on one entry of one section', () => {
		const href = compendiumHref(
			'kony',
			'vampire',
			'vampire-merits',
			'Iron Will',
			7
		);

		expect( href ).toContain( 'tab=compendium' );
		expect( href ).toContain( 'game_slug=kony' );
		expect( href ).toContain( 'stack=vampire' );
		expect( href ).toContain( 'block=vampire-merits' );
		expect( href ).toContain( 'entry=Iron%20Will' );
		expect( href ).toContain( 'character_id=7' );
	} );

	it( 'leaves the character out when there is none', () => {
		expect(
			compendiumHref(
				'kony',
				'vampire',
				'vampire-merits',
				'Iron Will',
				null
			)
		).not.toContain( 'character_id' );
	} );
} );

describe( 'compendiumTarget', () => {
	it( 'reads what an address asks the compendium to open', () => {
		expect(
			compendiumTarget(
				'?tab=compendium&stack=vampire&block=vampire-merits&entry=Iron%20Will&character_id=7'
			)
		).toEqual( {
			stack: 'vampire',
			block: 'vampire-merits',
			entry: 'Iron Will',
			characterId: 7,
		} );
	} );

	it( 'has nothing to open without a stack, a section and an entry', () => {
		expect( compendiumTarget( '?tab=compendium' ) ).toBeNull();
		expect(
			compendiumTarget( '?stack=vampire&block=vampire-merits' )
		).toBeNull();
	} );

	it( 'reads no character from a bad id', () => {
		expect(
			compendiumTarget( '?stack=a&block=b&entry=c&character_id=x' )
				?.characterId
		).toBeNull();
	} );
} );
