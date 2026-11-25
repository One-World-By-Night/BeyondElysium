import {
	STAFF_AREAS,
	areaLabel,
	areaNote,
	areaTickable,
	logLine,
	roleLabel,
	sameAreas,
	toggledAreas,
} from './staffGrants';
import type { StaffGrantLogEntry } from '../types/staffGrants';

describe( 'the areas a member can be given', () => {
	it( 'lists the five areas in the panel order', () => {
		expect( STAFF_AREAS.map( ( info ) => info.area ) ).toEqual( [
			'be_manage_schemas',
			'be_manage_approval_rules',
			'be_manage_templates',
			'be_manage_chronicle_setup',
			'be_delete_characters',
		] );
		expect( STAFF_AREAS.map( ( info ) => areaLabel( info.area ) ) ).toEqual(
			[
				'Catalog',
				'Approval Rules',
				'Templates',
				'Chronicle Setup',
				'Delete characters',
			]
		);
	} );

	it( 'marks the three wp-admin areas and leaves the two Toolkit ones alone', () => {
		const notes = STAFF_AREAS.map( ( info ) => areaNote( info.place ) );
		expect( notes ).toEqual( [
			'In the Storyteller Toolkit',
			'Opens in wp-admin. Needs an Editor account on this site.',
			'Opens in wp-admin. Needs an Editor account on this site.',
			'Opens in wp-admin. Needs an Editor account on this site.',
			'In the Storyteller Toolkit',
		] );
	} );

	it( 'names a role, and says so when there is none', () => {
		expect( roleLabel( 'ast' ) ).toBe( 'AST' );
		expect( roleLabel( 'narrator' ) ).toBe( 'Narrator' );
		expect( roleLabel( 'boons' ) ).toBe( 'Harpy' );
		expect( roleLabel( null ) ).toBe( 'No role here' );
	} );
} );

describe( 'ticking boxes', () => {
	it( 'adds and takes away an area, keeping the panel order', () => {
		expect(
			toggledAreas( [ 'be_delete_characters' ], 'be_manage_schemas' )
		).toEqual( [ 'be_manage_schemas', 'be_delete_characters' ] );
		expect(
			toggledAreas(
				[ 'be_manage_schemas', 'be_delete_characters' ],
				'be_manage_schemas'
			)
		).toEqual( [ 'be_delete_characters' ] );
	} );

	it( 'treats the same areas in another order as the same', () => {
		expect(
			sameAreas(
				[ 'be_manage_schemas', 'be_manage_templates' ],
				[ 'be_manage_templates', 'be_manage_schemas' ]
			)
		).toBe( true );
		expect( sameAreas( [ 'be_manage_schemas' ], [] ) ).toBe( false );
	} );

	it( 'keeps Delete characters off a Narrator but lets an old tick be cleared', () => {
		expect( areaTickable( 'be_delete_characters', 'narrator', [] ) ).toBe(
			false
		);
		expect(
			areaTickable( 'be_delete_characters', 'narrator', [
				'be_delete_characters',
			] )
		).toBe( true );
		expect( areaTickable( 'be_delete_characters', 'ast', [] ) ).toBe(
			true
		);
		expect( areaTickable( 'be_manage_schemas', 'narrator', [] ) ).toBe(
			true
		);
	} );
} );

describe( 'the log', () => {
	const given: StaffGrantLogEntry = {
		action: 'granted',
		area: 'be_manage_schemas',
		wp_user_id: 2,
		user_name: 'Ben Ito',
		by: 1,
		by_name: 'Ana Ruiz',
		at: '2026-11-25 14:30:00',
	};

	it( 'reads a grant as who gave what to whom and when', () => {
		expect( logLine( given ) ).toBe(
			'Ana Ruiz gave Catalog to Ben Ito, Nov 25, 2026.'
		);
	} );

	it( 'reads a removal the other way round', () => {
		expect(
			logLine( {
				...given,
				action: 'revoked',
				at: '2026-11-26 09:00:00',
			} )
		).toBe( 'Ana Ruiz took away Catalog from Ben Ito, Nov 26, 2026.' );
	} );

	it( 'names an account that is gone as a former member', () => {
		expect( logLine( { ...given, by_name: null, user_name: null } ) ).toBe(
			'A former member gave Catalog to A former member, Nov 25, 2026.'
		);
	} );
} );
