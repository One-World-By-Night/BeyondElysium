import {
	correctionMessage,
	correctionPlace,
	displayValue,
} from './correctionLabel';
import type { CatalogCorrection } from '../types';

function flag( over: Partial< CatalogCorrection > ): CatalogCorrection {
	return {
		kind: 'block',
		target: 'vampire-disciplines',
		target_name: 'Disciplines',
		path: [ '_meta', 'costs', 'elder' ],
		labels: [ null, null, null ],
		removed: false,
		was: 12,
		was_set: true,
		now: 15,
		now_set: true,
		yours: 10,
		yours_set: true,
		...over,
	};
}

describe( 'correctionLabel', () => {
	it( 'names a rank cost in the cost table', () => {
		expect( correctionPlace( flag( {} ) ) ).toBe(
			'Disciplines › Elder cost'
		);
	} );

	it( 'names an Elder pick by family, rank and name', () => {
		expect(
			correctionPlace(
				flag( {
					path: [
						'powers',
						[ 'Animalism' ],
						'elder',
						'master',
						[ 'Stampede' ],
						'cost',
					],
					labels: [ null, 'Animalism', null, null, 'Stampede', null ],
				} )
			)
		).toBe( 'Disciplines › Animalism › Master › Stampede › Cost' );
	} );

	it( "names a creature type's section by its label, not its block slug", () => {
		expect(
			correctionPlace(
				flag( {
					kind: 'stack',
					target: 'vampire',
					target_name: 'Vampire',
					path: [
						'stack_definition',
						'sections',
						[ 'vampire-blood-magic' ],
						'hidden',
					],
					labels: [ null, null, 'Blood Magic', null ],
				} )
			)
		).toBe( 'Vampire › Blood Magic › Hidden' );
	} );

	it( 'says what the book said, says now, and what the chronicle has', () => {
		expect( correctionMessage( flag( {} ) ) ).toBe(
			'The book said 12, now says 15. Yours: 10.'
		);
	} );

	it( 'says the book removed an entry and what it costs here', () => {
		expect(
			correctionMessage(
				flag( {
					path: [
						'powers',
						[ 'Animalism' ],
						'elder',
						'master',
						[ 'Stampede' ],
					],
					labels: [ null, 'Animalism', null, null, 'Stampede' ],
					removed: true,
					now: null,
					now_set: false,
					yours: { power_name: 'Stampede', cost: '10' },
					changes: [
						{
							path: [
								'powers',
								[ 'Animalism' ],
								'elder',
								'master',
								[ 'Stampede' ],
								'cost',
							],
							was: '15',
							was_set: true,
							now: null,
							now_set: false,
							yours: '10',
							yours_set: true,
						},
					],
				} )
			)
		).toBe( 'The book removed Stampede; yours costs 10.' );
	} );

	it( 'says the book now has an entry the chronicle made its own', () => {
		expect(
			correctionMessage(
				flag( {
					path: [ 'items', [ 'Contacts' ] ],
					labels: [ null, 'Contacts' ],
					was: null,
					was_set: false,
					now: { name: 'Contacts', cost: '1' },
					yours: { name: 'Contacts', cost: '2' },
				} )
			)
		).toBe(
			'The book now has its own Contacts. Yours stays until you choose.'
		);
	} );

	it( 'says the book changed an entry the chronicle removed', () => {
		expect(
			correctionMessage(
				flag( {
					path: [ 'items', [ 'Allies' ] ],
					labels: [ null, 'Allies' ],
					was: { name: 'Allies' },
					now: { name: 'Allies', note: 'x' },
					yours: null,
					yours_set: false,
				} )
			)
		).toBe( 'The book changed Allies, which you removed.' );
	} );

	it( 'falls back to plain words when a value is too complex to name', () => {
		expect(
			correctionMessage(
				flag( { was: { a: 1 }, now: { a: 2 }, yours: { a: 3 } } )
			)
		).toBe( 'The book changed this since you did.' );
	} );

	it( 'names simple values', () => {
		expect( displayValue( true, true ) ).toBe( 'yes' );
		expect( displayValue( [ 'Celerity', 'Potence' ], true ) ).toBe(
			'Celerity, Potence'
		);
		expect( displayValue( 'x', false ) ).toBe( 'nothing' );
		expect( displayValue( { a: 1 }, true ) ).toBeNull();
	} );
} );
