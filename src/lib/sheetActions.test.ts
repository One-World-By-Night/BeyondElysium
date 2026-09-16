import { sheetActions } from './sheetActions';

/**
 * Owner, 2026-09-15: the Character Sheet's ten toolbar buttons were "WAY too many". One picker
 * lists what the viewer can do; each action shows only to someone allowed to use it.
 */
describe( 'sheetActions', () => {
	it( 'offers a player the actions every viewer of the sheet has', () => {
		expect(
			sheetActions( {
				can_edit: false,
				can_customize_sheet: false,
				can_manage: false,
			} )
		).toEqual( [ 'print', 'items', 'history', 'ledger', 'gex' ] );
	} );

	it( 'adds editing and appearance for a viewer allowed them', () => {
		expect(
			sheetActions( {
				can_edit: true,
				can_customize_sheet: true,
				can_manage: false,
			} )
		).toEqual( [
			'print',
			'items',
			'edit',
			'appearance',
			'history',
			'ledger',
			'gex',
		] );
	} );

	it( 'adds Send Sheet and the point audit for a Storyteller', () => {
		expect(
			sheetActions( {
				can_edit: true,
				can_customize_sheet: true,
				can_manage: true,
			} )
		).toEqual( [
			'print',
			'items',
			'edit',
			'appearance',
			'history',
			'ledger',
			'send',
			'audit',
			'gex',
		] );
	} );
} );
