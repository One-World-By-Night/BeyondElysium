import { describe, expect, it } from 'vitest';
import {
	buildSecretNamesByType,
	resolveSecretEntityName,
} from './useSecretEntityNames';
import type { Character } from '../types/character';
import type { Plot } from '../types/plot';
import type { WorldObject } from '../types/world';

const characters = [
	{ id: 1, name: 'Isolde Marchetti', is_npc: false },
	{ id: 2, name: 'Radu Bathory', is_npc: true },
] as unknown as Character[];

const plots = [ { id: 10, title: 'The Tremere Gambit' } ] as unknown as Plot[];

const worldObjects = [
	{ id: 20, name: 'Rat Mask', object_type: 'item' },
	{ id: 21, name: 'The Chantry', object_type: 'location' },
] as unknown as WorldObject[];

describe( 'resolveSecretEntityName', () => {
	it( 'resolves a player character by id', () => {
		expect(
			resolveSecretEntityName(
				'character',
				1,
				characters,
				plots,
				worldObjects
			)
		).toBe( 'Isolde Marchetti' );
	} );

	it( 'resolves an NPC by id', () => {
		expect(
			resolveSecretEntityName( 'npc', 2, characters, plots, worldObjects )
		).toBe( 'Radu Bathory' );
	} );

	it( 'resolves a plot by id', () => {
		expect(
			resolveSecretEntityName(
				'plot',
				10,
				characters,
				plots,
				worldObjects
			)
		).toBe( 'The Tremere Gambit' );
	} );

	it( 'resolves an item by id, never a same-id location', () => {
		expect(
			resolveSecretEntityName(
				'item',
				20,
				characters,
				plots,
				worldObjects
			)
		).toBe( 'Rat Mask' );
		expect(
			resolveSecretEntityName(
				'item',
				21,
				characters,
				plots,
				worldObjects
			)
		).toBeNull();
	} );

	it( 'resolves a location by id', () => {
		expect(
			resolveSecretEntityName(
				'location',
				21,
				characters,
				plots,
				worldObjects
			)
		).toBe( 'The Chantry' );
	} );

	it( 'returns null for an id that has not loaded yet', () => {
		expect(
			resolveSecretEntityName(
				'character',
				999,
				characters,
				plots,
				worldObjects
			)
		).toBeNull();
	} );
} );

describe( 'buildSecretNamesByType', () => {
	it( 'splits characters into the character and npc maps', () => {
		const byType = buildSecretNamesByType(
			characters,
			plots,
			worldObjects
		);
		expect( byType.character.get( 'Isolde Marchetti' ) ).toBe( 1 );
		expect( byType.character.has( 'Radu Bathory' ) ).toBe( false );
		expect( byType.npc.get( 'Radu Bathory' ) ).toBe( 2 );
		expect( byType.npc.has( 'Isolde Marchetti' ) ).toBe( false );
	} );

	it( 'splits world objects into the item and location maps', () => {
		const byType = buildSecretNamesByType(
			characters,
			plots,
			worldObjects
		);
		expect( byType.item.get( 'Rat Mask' ) ).toBe( 20 );
		expect( byType.location.get( 'The Chantry' ) ).toBe( 21 );
	} );
} );
