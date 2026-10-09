/**
 * `renderReason()` turns every OWBN Character Bylaws citation a reason string carries into a link to the real
 * clause on council.owbn.net, keeping the rest as plain text. `forwardedFromLabel()` names the host chronicle a
 * forwarded change arrived from.
 */
import { isValidElement } from 'react';
import { forwardedFromLabel, renderReason } from './ApprovalQueue';
import type { QueueChange } from '../../types/character';

function queueChange( overrides: Partial< QueueChange > = {} ): QueueChange {
	return {
		id: 1,
		character_id: 1,
		change_type: 'add_trait',
		category: null,
		change_data: {},
		xp_cost: 0,
		status: 'pending',
		submitted_by: 1,
		reviewed_by: null,
		submitted_at: '2026-10-03 00:00:00',
		reviewed_at: null,
		notes: null,
		reason: null,
		character_name: 'Test Character',
		approval_level: 'st',
		submitted_by_name: null,
		review_token: 'token',
		...overrides,
	};
}

/**
 * A rendered citation link's own props, typed - `isValidElement()` alone narrows `props` to `unknown`.
 */
function linkProps( node: unknown ): { href: string; children: string } {
	if ( ! isValidElement( node ) ) {
		throw new Error( 'Expected a React element.' );
	}
	return node.props as { href: string; children: string };
}

describe( 'renderReason', () => {
	it( 'returns null for no reason', () => {
		expect( renderReason( null ) ).toBeNull();
	} );

	it( 'returns a plain reason with no citation untouched', () => {
		const parts = renderReason( 'Chronicle house rule note.' ) as unknown[];

		expect( parts ).toEqual( [ 'Chronicle house rule note.' ] );
	} );

	it( 'turns one citation into a link to the real clause', () => {
		const parts = renderReason(
			'Coordinator Notify — Hunter Coordinator — applies to: "True Faith 1-5" [OWBN Character Bylaws 10.e.v, clause 7838]'
		) as unknown[];

		expect( parts ).toHaveLength( 2 );
		expect( parts[ 0 ] ).toBe(
			'Coordinator Notify — Hunter Coordinator — applies to: "True Faith 1-5" '
		);

		const props = linkProps( parts[ 1 ] );
		expect( props.href ).toBe( 'https://council.owbn.net/?p=7838' );
		expect( props.children ).toBe(
			'[OWBN Character Bylaws 10.e.v, clause 7838]'
		);
	} );

	it( 'turns two citations on two lines into two separate links', () => {
		const parts = renderReason(
			'Coordinator Notify [OWBN Character Bylaws 10.e.v, clause 1]\nDisallowed [OWBN Character Bylaws 10.f.ii, clause 2]'
		) as unknown[];

		const links = parts.filter( isValidElement );
		expect( links ).toHaveLength( 2 );
		expect( linkProps( links[ 0 ] ).href ).toBe(
			'https://council.owbn.net/?p=1'
		);
		expect( linkProps( links[ 1 ] ).href ).toBe(
			'https://council.owbn.net/?p=2'
		);
		// The newline between the two citations survives as plain text, rendered by the cell's own
		// `white-space: pre-line`.
		expect(
			parts.some( ( p ) => typeof p === 'string' && p.includes( '\n' ) )
		).toBe( true );
	} );
} );

describe( 'forwardedFromLabel', () => {
	it( 'returns null when the change carries no host chronicle', () => {
		expect( forwardedFromLabel( queueChange() ) ).toBeNull();
	} );

	it( 'names the host chronicle a forwarded change arrived from', () => {
		expect(
			forwardedFromLabel(
				queueChange( {
					change_type: 'visit_note',
					host_chronicle: 'Boston',
				} )
			)
		).toBe( 'From Boston' );
	} );
} );
