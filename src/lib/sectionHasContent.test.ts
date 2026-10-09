import { describe, expect, it } from 'vitest';
import { sectionHasContent } from './sectionHasContent';
import type {
	BlockDefinition,
	IdentityFieldDefinition,
	ResourcePoolDefinition,
} from '../types';

const identity = {
	fields: [ { name: 'Clan' }, { name: 'Sect' } ],
} as unknown as IdentityFieldDefinition;

const pools = {
	pools: [ { name: 'Willpower' } ],
} as unknown as ResourcePoolDefinition;

const anyDefinition = {} as BlockDefinition;

describe( 'sectionHasContent', () => {
	it( 'hides a trait list or tiered power nothing is held in', () => {
		expect( sectionHasContent( 'trait_list', anyDefinition, [] ) ).toBe(
			false
		);
		expect(
			sectionHasContent( 'trait_list', anyDefinition, undefined )
		).toBe( false );
		expect( sectionHasContent( 'tiered_power', anyDefinition, [] ) ).toBe(
			false
		);
	} );

	it( 'shows a trait list or tiered power with a held entry', () => {
		expect(
			sectionHasContent( 'trait_list', anyDefinition, [
				{ name: 'Quick' },
			] )
		).toBe( true );
		expect(
			sectionHasContent( 'tiered_power', anyDefinition, [
				{ name: 'Celerity', level: 1 },
			] )
		).toBe( true );
	} );

	it( 'hides an identity block whose every field is empty', () => {
		expect(
			sectionHasContent( 'identity_field', identity, undefined )
		).toBe( false );
		expect(
			sectionHasContent( 'identity_field', identity, {
				Clan: '',
				Sect: [],
			} )
		).toBe( false );
	} );

	it( 'shows an identity block with one field filled in', () => {
		expect(
			sectionHasContent( 'identity_field', identity, { Clan: 'Tremere' } )
		).toBe( true );
		expect(
			sectionHasContent( 'identity_field', identity, {
				Sect: [ 'Camarilla' ],
			} )
		).toBe( true );
	} );

	it( 'always shows a resource pool block that declares a pool', () => {
		expect( sectionHasContent( 'resource_pool', pools, undefined ) ).toBe(
			true
		);
	} );

	it( 'hides a resource pool block that declares none', () => {
		expect(
			sectionHasContent(
				'resource_pool',
				{ pools: [] } as unknown as ResourcePoolDefinition,
				{}
			)
		).toBe( false );
	} );
} );
