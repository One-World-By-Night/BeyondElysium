import { applyGivens, givenLines, NO_GIVENS } from './builderGivens';
import type { CreationTally } from '../types/character';

function tally(
	steps: CreationTally[ 'steps' ],
	grants: CreationTally[ 'grants_missing' ] = []
): CreationTally {
	return {
		steps,
		pools: {},
		limits: [],
		grants_missing: grants,
		unbuyable: [],
		xp: { starting: 0, needed: 0, left: 0 },
	};
}

function start(
	target: string,
	value: number | null,
	applies = true,
	label = 'Rage'
) {
	return {
		kind: 'start' as const,
		label,
		applies,
		target,
		target_label: label,
		value,
	};
}

describe( 'applyGivens starts', () => {
	it( 'writes a start into a pool the player has not set', () => {
		const result = applyGivens(
			{},
			tally( [ start( 'wolf.Rage', 5 ) ] ),
			NO_GIVENS
		);
		expect( result.sheet ).toEqual( {
			wolf: { Rage: { permanent: 5, temporary: 5 } },
		} );
		expect( result.memory.starts[ 'wolf.Rage' ].value ).toBe( 5 );
	} );

	it( 'moves the pool when the start moves and the player never changed it', () => {
		const first = applyGivens(
			{},
			tally( [ start( 'wolf.Rage', 5 ) ] ),
			NO_GIVENS
		);
		const second = applyGivens(
			first.sheet,
			tally( [ start( 'wolf.Rage', 2 ) ] ),
			first.memory
		);
		expect( second.sheet ).toEqual( {
			wolf: { Rage: { permanent: 2, temporary: 2 } },
		} );
		expect( second.memory.starts[ 'wolf.Rage' ].value ).toBe( 2 );
	} );

	it( 'never writes over a value the player set', () => {
		const first = applyGivens(
			{},
			tally( [ start( 'wolf.Rage', 5 ) ] ),
			NO_GIVENS
		);
		const played = { wolf: { Rage: { permanent: 3, temporary: 3 } } };
		const second = applyGivens(
			played,
			tally( [ start( 'wolf.Rage', 2 ) ] ),
			first.memory
		);
		expect( second.sheet ).toBe( played );
		expect( second.sheet ).toEqual( {
			wolf: { Rage: { permanent: 3, temporary: 3 } },
		} );
	} );

	it( 'leaves a value the player set before the rules ever gave one', () => {
		const played = { wolf: { Rage: { permanent: 4, temporary: 4 } } };
		const result = applyGivens(
			played,
			tally( [ start( 'wolf.Rage', 5 ) ] ),
			NO_GIVENS
		);
		expect( result.sheet ).toBe( played );
		expect( result.memory ).toBe( NO_GIVENS );
	} );

	it( 'takes a start back when its rule stops applying and the player never changed it', () => {
		const first = applyGivens(
			{},
			tally( [ start( 'wolf.Willpower', 3, true, 'Willpower' ) ] ),
			NO_GIVENS
		);
		const second = applyGivens(
			first.sheet,
			tally( [ start( 'wolf.Willpower', null, false, 'Willpower' ) ] ),
			first.memory
		);
		expect( second.sheet ).toEqual( { wolf: {} } );
		expect( second.memory.starts ).toEqual( {} );
	} );

	it( 'keeps a changed value when its rule stops applying', () => {
		const first = applyGivens(
			{},
			tally( [ start( 'wolf.Willpower', 3, true, 'Willpower' ) ] ),
			NO_GIVENS
		);
		const played = { wolf: { Willpower: { permanent: 6, temporary: 6 } } };
		const second = applyGivens(
			played,
			tally( [ start( 'wolf.Willpower', null, false, 'Willpower' ) ] ),
			first.memory
		);
		expect( second.sheet ).toBe( played );
	} );

	it( 'takes the one rule that applies when several share a target', () => {
		const steps = [
			start( 'wolf.Glory', null, false, 'Glory' ),
			start( 'wolf.Glory', 2, true, 'Glory' ),
			start( 'wolf.Glory', null, false, 'Glory' ),
		];
		const result = applyGivens( {}, tally( steps ), NO_GIVENS );
		expect( result.sheet ).toEqual( {
			wolf: { Glory: { permanent: 2, temporary: 2 } },
		} );
	} );

	it( 'changes nothing when the pool already holds the start', () => {
		const first = applyGivens(
			{},
			tally( [ start( 'wolf.Rage', 5 ) ] ),
			NO_GIVENS
		);
		const again = applyGivens(
			first.sheet,
			tally( [ start( 'wolf.Rage', 5 ) ] ),
			first.memory
		);
		expect( again.sheet ).toBe( first.sheet );
		expect( again.memory ).toBe( first.memory );
	} );
} );

describe( 'applyGivens grants', () => {
	const missing = [ { section: 'wraith-status', name: 'Status', level: 1 } ];

	it( 'adds a granted entry once', () => {
		const first = applyGivens( {}, tally( [], missing ), NO_GIVENS );
		expect( first.sheet ).toEqual( {
			'wraith-status': [ { name: 'Status', level: 1 } ],
		} );
		const again = applyGivens(
			first.sheet,
			tally( [], missing ),
			first.memory
		);
		expect( again.sheet ).toBe( first.sheet );
	} );

	it( 'does not add it back once the player removed it', () => {
		const first = applyGivens( {}, tally( [], missing ), NO_GIVENS );
		const removed = { 'wraith-status': [] };
		const again = applyGivens(
			removed,
			tally( [], missing ),
			first.memory
		);
		expect( again.sheet ).toBe( removed );
	} );

	it( 'appends to rows the player already holds', () => {
		const held = { 'wraith-status': [ { name: 'Other', level: 2 } ] };
		const result = applyGivens( held, tally( [], missing ), NO_GIVENS );
		expect( result.sheet[ 'wraith-status' ] ).toEqual( [
			{ name: 'Other', level: 2 },
			{ name: 'Status', level: 1 },
		] );
	} );
} );

describe( 'givenLines', () => {
	it( 'lists what the rules gave in a step’s own sections', () => {
		const result = applyGivens(
			{},
			tally(
				[ start( 'wolf.Rage', 5 ) ],
				[ { section: 'other', name: 'Mark' } ]
			),
			NO_GIVENS
		);
		expect( givenLines( result.memory, [ 'wolf' ] ) ).toEqual( [
			'Rage 5',
		] );
		expect( givenLines( result.memory, [ 'other' ] ) ).toEqual( [
			'Mark',
		] );
		expect( givenLines( result.memory, [ 'nowhere' ] ) ).toEqual( [] );
	} );
} );
