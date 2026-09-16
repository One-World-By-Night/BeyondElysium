import { duplicateActionsFor } from './duplicateActions';

describe( 'duplicateActionsFor', () => {
	it( 'offers every decision for a character already in this chronicle', () => {
		expect( duplicateActionsFor( 'uuid' ) ).toEqual( [
			'skip',
			'overwrite',
			'import_as_new',
		] );
		expect( duplicateActionsFor( 'name' ) ).toEqual( [
			'skip',
			'overwrite',
			'import_as_new',
		] );
	} );

	it( 'never offers to overwrite a character that lives in another chronicle', () => {
		expect( duplicateActionsFor( 'uuid_elsewhere' ) ).toEqual( [
			'skip',
			'import_as_new',
		] );
	} );

	it( 'drops Skip when accepting a transfer', () => {
		expect( duplicateActionsFor( 'uuid', false ) ).toEqual( [
			'overwrite',
			'import_as_new',
		] );
		expect( duplicateActionsFor( 'uuid_elsewhere', false ) ).toEqual( [
			'import_as_new',
		] );
	} );

	it( 'drops Overwrite when the existing character belongs to someone else (F-122)', () => {
		expect( duplicateActionsFor( 'name', true, false ) ).toEqual( [
			'skip',
			'import_as_new',
		] );
		expect( duplicateActionsFor( 'uuid', false, false ) ).toEqual( [
			'import_as_new',
		] );
	} );

	it( 'uuid_elsewhere never offers Overwrite regardless of allowOverwrite', () => {
		expect( duplicateActionsFor( 'uuid_elsewhere', true, true ) ).toEqual( [
			'skip',
			'import_as_new',
		] );
	} );
} );
