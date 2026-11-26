import { sheetActions } from './sheetActions';

/**
 * The Character Sheet's action buttons: each action shows only to someone allowed to use it. Editing and history are
 * views of the Character section, not actions.
 */
describe( 'sheetActions', () => {
	it( 'offers a player the actions every viewer of the sheet has', () => {
		expect(
			sheetActions( {
				can_customize_sheet: false,
				can_manage: false,
			} )
		).toEqual( [ 'print', 'items', 'ledger', 'gex' ] );
	} );

	it( 'adds appearance for a viewer allowed it', () => {
		expect(
			sheetActions( {
				can_customize_sheet: true,
				can_manage: false,
			} )
		).toEqual( [ 'print', 'items', 'appearance', 'ledger', 'gex' ] );
	} );

	it( 'adds Send Sheet and the point audit for a Storyteller', () => {
		expect(
			sheetActions( {
				can_customize_sheet: true,
				can_manage: true,
			} )
		).toEqual( [
			'print',
			'items',
			'appearance',
			'ledger',
			'send',
			'audit',
			'gex',
		] );
	} );

	it( 'adds the build tally for a Storyteller while the character is pending', () => {
		expect(
			sheetActions( { can_manage: true, status: 'pending' } )
		).toContain( 'tally' );
		expect(
			sheetActions( { can_manage: true, status: 'active' } )
		).not.toContain( 'tally' );
		expect(
			sheetActions( { can_manage: false, status: 'pending' } )
		).not.toContain( 'tally' );
	} );

	it( 'never lists Edit or History, which are views now', () => {
		const all = sheetActions( {
			can_customize_sheet: true,
			can_manage: true,
			status: 'pending',
		} ) as string[];
		expect( all ).not.toContain( 'edit' );
		expect( all ).not.toContain( 'history' );
	} );
} );
