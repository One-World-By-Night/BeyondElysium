import { chronicleSetupTabKeys } from './hubTabs';

describe( 'chronicleSetupTabKeys', () => {
	it( 'shows no tab to someone the bundle told nothing', () => {
		expect( chronicleSetupTabKeys( undefined ) ).toEqual( [] );
		expect( chronicleSetupTabKeys( {} ) ).toEqual( [] );
	} );

	it( 'shows a player nothing: being able to view characters is not enough', () => {
		expect(
			chronicleSetupTabKeys( {
				be_view_characters: true,
				be_edit_own_characters: true,
				be_submit_actions: true,
			} )
		).toEqual( [] );
	} );

	it( 'gives an HST the setup, action-and-rumor and AI tabs, but not Chronicle Access', () => {
		expect(
			chronicleSetupTabKeys( {
				be_manage_chronicle_setup: true,
				be_manage_apr: true,
			} )
		).toEqual( [ 'setup', 'apr', 'ai-assist' ] );
	} );

	it( 'gives an administrator all four, in order', () => {
		expect(
			chronicleSetupTabKeys( {
				be_manage_chronicle_setup: true,
				be_manage_games: true,
				be_manage_apr: true,
			} )
		).toEqual( [ 'setup', 'access', 'apr', 'ai-assist' ] );
	} );

	it( 'does not let one capability stand in for another tab', () => {
		expect( chronicleSetupTabKeys( { be_manage_games: true } ) ).toEqual( [
			'access',
		] );
		expect( chronicleSetupTabKeys( { be_manage_apr: true } ) ).toEqual( [
			'apr',
			'ai-assist',
		] );
		expect(
			chronicleSetupTabKeys( { be_manage_chronicle_setup: true } )
		).toEqual( [ 'setup' ] );
	} );

	it( 'reads only an explicit true, never a truthy stand-in', () => {
		expect(
			chronicleSetupTabKeys( {
				be_manage_chronicle_setup: 1 as unknown as boolean,
			} )
		).toEqual( [] );
	} );
} );
