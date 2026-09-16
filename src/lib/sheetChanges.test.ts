import { groupSheetChanges, sheetChangeKind } from './sheetChanges';
import type { SheetChange } from '../types/import';

const change = (
	section: string,
	entry: string,
	here: string | null,
	arriving: string | null
): SheetChange => ( {
	section,
	entry,
	here,
	arriving,
} );

describe( 'sheetChanges', () => {
	describe( 'sheetChangeKind', () => {
		it( 'tells an addition, a removal, and a changed value apart', () => {
			expect(
				sheetChangeKind( change( 'Disciplines', 'Potence', null, '1' ) )
			).toBe( 'added' );
			expect(
				sheetChangeKind( change( 'Merits', 'Danger Sense', '2', null ) )
			).toBe( 'removed' );
			expect(
				sheetChangeKind(
					change( 'Details', 'Title', 'Pack Priest', 'Bishop' )
				)
			).toBe( 'changed' );
		} );

		it( 'treats an empty value as a value, not an absence', () => {
			expect(
				sheetChangeKind(
					change( 'Details', 'Notes', '', 'Came back scarred.' )
				)
			).toBe( 'changed' );
		} );
	} );

	describe( 'groupSheetChanges', () => {
		it( 'keeps sections in the order they first appear, each with its own rows in order', () => {
			const groups = groupSheetChanges( [
				change( 'Details', 'Title', 'Pack Priest', 'Bishop' ),
				change( 'Abilities', 'Streetwise', '4', '5' ),
				change( 'Details', 'Sect', 'Sabbat', 'Anarch' ),
				change( 'Abilities', 'Brawl', null, '2' ),
			] );

			expect( groups.map( ( g ) => g.section ) ).toEqual( [
				'Details',
				'Abilities',
			] );
			expect( groups[ 0 ].changes.map( ( c ) => c.entry ) ).toEqual( [
				'Title',
				'Sect',
			] );
			expect( groups[ 1 ].changes.map( ( c ) => c.entry ) ).toEqual( [
				'Streetwise',
				'Brawl',
			] );
		} );

		it( 'returns nothing for no changes', () => {
			expect( groupSheetChanges( [] ) ).toEqual( [] );
		} );
	} );
} );
