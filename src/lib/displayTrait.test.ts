import { displayTrait, type Trait } from './displayTrait';

const CELERITY: Trait = { name: 'Celerity', total: 3, note: 'Fast' };

describe( 'displayTrait — all 11 modes against the fixture trait', () => {
	it( 'simple: name only, note suppressed', () => {
		expect( displayTrait( CELERITY, 'simple' ) ).toBe( 'Celerity' );
	} );

	it( 'multiplier: name, x{total}, then note', () => {
		expect( displayTrait( CELERITY, 'multiplier' ) ).toBe( 'Celerity x3 (Fast)' );
	} );

	it( 'multiplier_dot: name, x{total}, dots, note', () => {
		expect( displayTrait( CELERITY, 'multiplier_dot' ) ).toBe( 'Celerity x3 ••• (Fast)' );
	} );

	it( 'dot: name, dots, note', () => {
		expect( displayTrait( CELERITY, 'dot' ) ).toBe( 'Celerity ••• (Fast)' );
	} );

	it( 'cost: name ({total}, {note})', () => {
		expect( displayTrait( CELERITY, 'cost' ) ).toBe( 'Celerity (3, Fast)' );
	} );

	it( 'note_only: name ({note})', () => {
		expect( displayTrait( CELERITY, 'note_only' ) ).toBe( 'Celerity (Fast)' );
	} );

	it( 'cost_only: name ({total}), note excluded', () => {
		expect( displayTrait( CELERITY, 'cost_only' ) ).toBe( 'Celerity (3)' );
	} );

	it( 'dot_separate: name+note repeated total times, dot-joined', () => {
		expect( displayTrait( CELERITY, 'dot_separate' ) ).toBe(
			'Celerity (Fast)•Celerity (Fast)•Celerity (Fast)'
		);
	} );

	it( 'simple_dots: dots only, name dropped', () => {
		expect( displayTrait( CELERITY, 'simple_dots' ) ).toBe( '•••' );
	} );

	it( 'simple_number: total only', () => {
		expect( displayTrait( CELERITY, 'simple_number' ) ).toBe( '3' );
	} );

	it( 'simple_note: note only', () => {
		expect( displayTrait( CELERITY, 'simple_note' ) ).toBe( 'Fast' );
	} );
} );

describe( 'displayTrait — edge cases', () => {
	it( 'multiplier hides x1', () => {
		expect( displayTrait( { name: 'Fortitude', total: 1 }, 'multiplier' ) ).toBe( 'Fortitude' );
	} );

	it( 'multiplier_dot does NOT hide x1', () => {
		expect( displayTrait( { name: 'Fortitude', total: 1 }, 'multiplier_dot' ) ).toBe( 'Fortitude x1 •' );
	} );

	it( 'total 0 renders no dots but the mode still runs', () => {
		expect( displayTrait( { name: 'Flaw', total: 0 }, 'dot' ) ).toBe( 'Flaw' );
		expect( displayTrait( { name: 'Flaw', total: 0 }, 'simple_dots' ) ).toBe( '' );
		expect( displayTrait( { name: 'Flaw', total: 0 }, 'simple_number' ) ).toBe( '0' );
	} );

	it( 'dot_separate emits the name once for total 0 or 1', () => {
		expect( displayTrait( { name: 'X', total: 0 }, 'dot_separate' ) ).toBe( 'X' );
		expect( displayTrait( { name: 'X', total: 1 }, 'dot_separate' ) ).toBe( 'X' );
		expect( displayTrait( { name: 'X', total: 2 }, 'dot_separate' ) ).toBe( 'X•X' );
	} );

	it( 'non-numeric total parses via leading-integer Val() semantics, defaulting to 0', () => {
		expect( displayTrait( { name: 'X', total: '3 (borrowed)' }, 'simple_number' ) ).toBe( '3' );
		expect( displayTrait( { name: 'X', total: 'borrowed' }, 'simple_number' ) ).toBe( '0' );
	} );

	it( 'empty note behaves like a missing note', () => {
		expect( displayTrait( { name: 'X', total: 3, note: '' }, 'dot' ) ).toBe( 'X •••' );
		expect( displayTrait( { name: 'X', total: 3, note: '' }, 'note_only' ) ).toBe( 'X' );
	} );

	it( 'missing note behaves the same as empty note', () => {
		expect( displayTrait( { name: 'X', total: 3 }, 'cost' ) ).toBe( 'X (3)' );
		expect( displayTrait( { name: 'X', total: 3 }, 'dot_separate' ) ).toBe( 'X•X•X' );
	} );

	it( 'a custom dot glyph is honored', () => {
		expect( displayTrait( CELERITY, 'dot', 'o' ) ).toBe( 'Celerity ooo (Fast)' );
	} );
} );
