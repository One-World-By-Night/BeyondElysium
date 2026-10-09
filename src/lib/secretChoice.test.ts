import { describe, expect, it } from 'vitest';
import {
	isLogPending,
	secretChoiceValid,
	SECRET_CHARACTER_ENTITY_TYPES,
	SECRET_ENTITY_TYPES,
} from './secretChoice';

describe( 'isLogPending', () => {
	it( 'is true for a log_knowledge change', () => {
		expect( isLogPending( { change_type: 'log_knowledge' } ) ).toBe( true );
	} );

	it( 'is false for every other change type', () => {
		expect( isLogPending( { change_type: 'pass_secret' } ) ).toBe( false );
		expect( isLogPending( { change_type: 'add_trait' } ) ).toBe( false );
	} );
} );

describe( 'SECRET_ENTITY_TYPES', () => {
	it( 'offers a player character, not only an NPC', () => {
		expect( SECRET_ENTITY_TYPES ).toContain( 'character' );
		expect( SECRET_ENTITY_TYPES ).toContain( 'npc' );
	} );

	it( 'names the two entity types a reviewer picks by searching characters', () => {
		expect( SECRET_CHARACTER_ENTITY_TYPES ).toEqual( [
			'character',
			'npc',
		] );
	} );
} );

describe( 'secretChoiceValid', () => {
	it( 'is false with no draft at all', () => {
		expect( secretChoiceValid( undefined ) ).toBe( false );
	} );

	it( 'existing mode needs a secret id', () => {
		expect( secretChoiceValid( { mode: 'existing' } ) ).toBe( false );
		expect( secretChoiceValid( { mode: 'existing', secretId: 12 } ) ).toBe(
			true
		);
	} );

	it( 'new mode needs a non-empty title, naming an entity is optional', () => {
		expect( secretChoiceValid( { mode: 'new' } ) ).toBe( false );
		expect(
			secretChoiceValid( {
				mode: 'new',
				entityType: 'plot',
				entityId: '4',
			} )
		).toBe( false );
		expect(
			secretChoiceValid( {
				mode: 'new',
				entityType: 'plot',
				entityId: '4',
				title: '   ',
			} )
		).toBe( false );
		expect(
			secretChoiceValid( {
				mode: 'new',
				entityType: 'plot',
				entityId: '4',
				title: 'The real plot',
			} )
		).toBe( true );
		expect(
			secretChoiceValid( { mode: 'new', title: 'Unattached secret' } )
		).toBe( true );
	} );

	it( 'rejects a half-picked entity: a type with no id, or an id with no type', () => {
		expect(
			secretChoiceValid( {
				mode: 'new',
				entityType: 'plot',
				title: 'Half-picked',
			} )
		).toBe( false );
		expect(
			secretChoiceValid( {
				mode: 'new',
				entityId: '4',
				title: 'Half-picked',
			} )
		).toBe( false );
	} );
} );
