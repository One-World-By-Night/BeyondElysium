import { batchApproval, toggleSelection } from './queueSelection';

/**
 * 1.0.0-review F-101: Approve Selected looked each ticked change's review token up on the page
 * on screen, so a change ticked on another page went without one - and was approved however it
 * had been edited since.
 */
describe( 'queueSelection', () => {
	it( 'keeps the review token of the version each change was ticked on', () => {
		let selection = toggleSelection( new Map(), 11, 'token-a' );
		selection = toggleSelection( selection, 42, 'token-b' );

		expect( batchApproval( selection ) ).toEqual( {
			ids: [ 11, 42 ],
			tokens: { 11: 'token-a', 42: 'token-b' },
		} );
	} );

	it( 'drops a change ticked a second time', () => {
		const selection = toggleSelection(
			toggleSelection( new Map(), 11, 'token-a' ),
			11,
			'token-a'
		);

		expect( batchApproval( selection ) ).toEqual( { ids: [], tokens: {} } );
	} );

	it( 'never changes the selection it was given', () => {
		const before = toggleSelection( new Map(), 11, 'token-a' );
		toggleSelection( before, 42, 'token-b' );

		expect( [ ...before.keys() ] ).toEqual( [ 11 ] );
	} );

	it( 'sends a change with no token without one', () => {
		expect(
			batchApproval( toggleSelection( new Map(), 7, undefined ) )
		).toEqual( { ids: [ 7 ], tokens: {} } );
	} );
} );
