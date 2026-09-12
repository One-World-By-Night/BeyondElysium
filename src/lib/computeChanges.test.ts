import { computeChanges, type SheetData } from './computeChanges';
import type { SchemaBlock } from '../types';

function block( slug: string, sectionType: SchemaBlock[ 'section_type' ] ): SchemaBlock {
	return {
		id: 1,
		slug,
		name: slug,
		section_type: sectionType,
		definition: sectionType === 'trait_list' ? { items: [] } : ( {} as SchemaBlock[ 'definition' ] ),
		is_system: 1,
		version: 1,
		created_by: 1,
		created_at: '',
		updated_at: '',
	};
}

describe( 'computeChanges — trait_list', () => {
	const blocks = { disciplines: block( 'disciplines', 'trait_list' ) };

	it( 'produces nothing for a no-op edit', () => {
		const sheet: SheetData = { disciplines: [ { name: 'Celerity', count: 2 } ] };
		expect( computeChanges( sheet, sheet, blocks ) ).toEqual( [] );
	} );

	it( 'produces nothing when the list is only reordered', () => {
		const original: SheetData = {
			disciplines: [
				{ name: 'Celerity', count: 2 },
				{ name: 'Fortitude', count: 1 },
			],
		};
		const reordered: SheetData = {
			disciplines: [
				{ name: 'Fortitude', count: 1 },
				{ name: 'Celerity', count: 2 },
			],
		};
		expect( computeChanges( original, reordered, blocks ) ).toEqual( [] );
	} );

	it( 'produces one modify_trait for a count change', () => {
		const original: SheetData = { disciplines: [ { name: 'Celerity', count: 2 } ] };
		const current: SheetData = { disciplines: [ { name: 'Celerity', count: 3 } ] };

		const changes = computeChanges( original, current, blocks );

		expect( changes ).toEqual( [
			{
				change_type: 'modify_trait',
				category: 'disciplines',
				change_data: {
					block_slug: 'disciplines',
					trait: { name: 'Celerity', count: 3 },
					previous: { name: 'Celerity', count: 2, specialization: undefined, note: undefined, chosen_cost: undefined },
				},
			},
		] );
	} );

	it( 'produces nothing when a trait is removed and then the removal is undone', () => {
		const original: SheetData = { disciplines: [ { name: 'Celerity', count: 2 } ] };
		// Remove (mark) then undo, exactly what the editor's "Undo" affordance does -
		// nets back to the original row untouched.
		const current: SheetData = { disciplines: [ { name: 'Celerity', count: 2, _removed: false } ] };

		expect( computeChanges( original, current, blocks ) ).toEqual( [] );
	} );

	it( 'produces both an add and a remove when a different trait is added and another removed', () => {
		const original: SheetData = { disciplines: [ { name: 'Celerity', count: 1 } ] };
		const current: SheetData = {
			disciplines: [
				{ name: 'Celerity', count: 1, _removed: true },
				{ name: 'Fortitude', count: 1 },
			],
		};

		const changes = computeChanges( original, current, blocks );

		expect( changes ).toHaveLength( 2 );
		expect( changes ).toContainEqual( {
			change_type: 'remove_trait',
			category: 'disciplines',
			change_data: { block_slug: 'disciplines', trait: { name: 'Celerity' } },
		} );
		expect( changes ).toContainEqual( {
			change_type: 'add_trait',
			category: 'disciplines',
			change_data: { block_slug: 'disciplines', trait: { name: 'Fortitude', count: 1 } },
		} );
	} );

	it( 'appends a duplicate on an atomic list instead of treating it as a modification', () => {
		const atomicBlocks = { merits: { ...block( 'merits', 'trait_list' ), definition: { items: [], atomic: true } } };
		const original: SheetData = { merits: [ { name: 'Contacts', count: 1 } ] };
		const current: SheetData = {
			merits: [
				{ name: 'Contacts', count: 1 },
				{ name: 'Contacts', count: 1, specialization: 'Police' },
			],
		};

		const changes = computeChanges( original, current, atomicBlocks );

		expect( changes ).toEqual( [
			{
				change_type: 'add_trait',
				category: 'merits',
				change_data: {
					block_slug: 'merits',
					trait: { name: 'Contacts', count: 1, specialization: 'Police' },
				},
			},
		] );
	} );
} );

describe( 'computeChanges — tiered_power', () => {
	const blocks = { disciplines: block( 'disciplines', 'tiered_power' ) };

	it( 'produces a modify_trait for a level change', () => {
		const original: SheetData = { disciplines: [ { name: 'Celerity', level: 2 } ] };
		const current: SheetData = { disciplines: [ { name: 'Celerity', level: 4 } ] };

		expect( computeChanges( original, current, blocks ) ).toEqual( [
			{
				change_type: 'modify_trait',
				category: 'disciplines',
				change_data: {
					block_slug: 'disciplines',
					trait: { name: 'Celerity', level: 4 },
					previous: { name: 'Celerity', level: 2 },
				},
			},
		] );
	} );

	it( 'produces a remove_trait for a dropped power', () => {
		const original: SheetData = { disciplines: [ { name: 'Celerity', level: 2 } ] };
		const current: SheetData = { disciplines: [ { name: 'Celerity', level: 2, _removed: true } ] };

		expect( computeChanges( original, current, blocks ) ).toEqual( [
			{
				change_type: 'remove_trait',
				category: 'disciplines',
				change_data: { block_slug: 'disciplines', trait: { name: 'Celerity' } },
			},
		] );
	} );

	// The same path name exists under several sorcery traditions at once, and one
	// character can hold paths from more than one - so `tradition` lives on the held
	// entry, and setting it with the level untouched is a real change on its own.
	it( 'treats a tradition change with an unchanged level as a real modify_trait', () => {
		const original: SheetData = { disciplines: [ { name: 'Ash Path', level: 5 } ] };
		const current: SheetData = { disciplines: [ { name: 'Ash Path', level: 5, tradition: 'Mortis' } ] };

		expect( computeChanges( original, current, blocks ) ).toEqual( [
			{
				change_type: 'modify_trait',
				category: 'disciplines',
				change_data: {
					block_slug: 'disciplines',
					trait: { name: 'Ash Path', level: 5, tradition: 'Mortis' },
					previous: { name: 'Ash Path', level: 5 },
				},
			},
		] );
	} );

	it( 'sends an explicit empty tradition when one is cleared, since array_merge cannot unset', () => {
		const original: SheetData = { disciplines: [ { name: 'Ash Path', level: 5, tradition: 'Mortis' } ] };
		const current: SheetData = { disciplines: [ { name: 'Ash Path', level: 5 } ] };

		const [ change ] = computeChanges( original, current, blocks );
		expect( change.change_data.trait ).toEqual( { name: 'Ash Path', level: 5, tradition: '' } );
	} );

	it( 'omits tradition entirely when a power has none', () => {
		const original: SheetData = { disciplines: [ { name: 'Celerity', level: 1 } ] };
		const current: SheetData = { disciplines: [ { name: 'Celerity', level: 2 } ] };

		const [ change ] = computeChanges( original, current, blocks );
		expect( change.change_data.trait ).not.toHaveProperty( 'tradition' );
	} );

	// Elder-and-above picks (Decision 037) are matched by power_name within the tier,
	// not by numbered level - Cost_Engine::price_tiered_power_change() prices these
	// via power_name instead of level (0.99.2-workflow.md "Cost_Engine cannot price
	// an Elder-tier purchase").
	it( 'produces an add_trait carrying power_name for a new Elder-and-above pick', () => {
		const current: SheetData = { disciplines: [ { name: 'Celerity', power_name: 'Precision' } ] };

		expect( computeChanges( {}, current, blocks ) ).toEqual( [
			{
				change_type: 'add_trait',
				category: 'disciplines',
				change_data: { block_slug: 'disciplines', trait: { name: 'Celerity', power_name: 'Precision' } },
			},
		] );
	} );

	// A family can hold several distinct Elder-and-above picks at once
	// (0.99.2-workflow.md: "you can have multiple powers at those levels"), identified by
	// (name, power_name) together - swapping Precision for Projectile is therefore two
	// independent facts changing, not one row's power_name changing in place.
	it( 'treats swapping one Elder pick for another as a remove plus an add, not a modify', () => {
		const original: SheetData = { disciplines: [ { name: 'Celerity', power_name: 'Precision' } ] };
		const current: SheetData = { disciplines: [ { name: 'Celerity', power_name: 'Projectile' } ] };

		const changes = computeChanges( original, current, blocks );
		expect( changes ).toHaveLength( 2 );
		expect( changes ).toContainEqual( {
			change_type: 'remove_trait',
			category: 'disciplines',
			change_data: { block_slug: 'disciplines', trait: { name: 'Celerity', power_name: 'Precision' } },
		} );
		expect( changes ).toContainEqual( {
			change_type: 'add_trait',
			category: 'disciplines',
			change_data: { block_slug: 'disciplines', trait: { name: 'Celerity', power_name: 'Projectile' } },
		} );
	} );

	it( 'holding two Elder picks at once and removing one leaves the other alone', () => {
		const original: SheetData = { disciplines: [
			{ name: 'Celerity', power_name: 'Precision' },
			{ name: 'Celerity', power_name: 'Projectile' },
		] };
		const current: SheetData = { disciplines: [ { name: 'Celerity', power_name: 'Projectile' } ] };

		expect( computeChanges( original, current, blocks ) ).toEqual( [
			{
				change_type: 'remove_trait',
				category: 'disciplines',
				change_data: { block_slug: 'disciplines', trait: { name: 'Celerity', power_name: 'Precision' } },
			},
		] );
	} );

	it( 'no-ops when two Elder picks are both held unchanged, even though they share a name', () => {
		const sheet: SheetData = { disciplines: [
			{ name: 'Celerity', power_name: 'Precision' },
			{ name: 'Celerity', power_name: 'Projectile' },
		] };

		expect( computeChanges( sheet, sheet, blocks ) ).toEqual( [] );
	} );

	it( 'omits power_name entirely for an ordinary numbered pick', () => {
		const original: SheetData = { disciplines: [ { name: 'Celerity', level: 1 } ] };
		const current: SheetData = { disciplines: [ { name: 'Celerity', level: 2 } ] };

		const [ change ] = computeChanges( original, current, blocks );
		expect( change.change_data.trait ).not.toHaveProperty( 'power_name' );
	} );
} );

describe( 'computeChanges — resource_pool', () => {
	const blocks = { pools: block( 'pools', 'resource_pool' ) };

	it( 'produces one modify_resource per pool that moved', () => {
		const original: SheetData = {
			pools: { Blood: { permanent: 10, temporary: 10 }, Willpower: { permanent: 5, temporary: 5 } },
		};
		const current: SheetData = {
			pools: { Blood: { permanent: 10, temporary: 8 }, Willpower: { permanent: 5, temporary: 5 } },
		};

		expect( computeChanges( original, current, blocks ) ).toEqual( [
			{
				change_type: 'modify_resource',
				category: 'pools',
				change_data: { block_slug: 'pools', values: { Blood: { permanent: 10, temporary: 8 } } },
			},
		] );
	} );
} );

describe( 'computeChanges — identity_field', () => {
	const blocks = { identity: block( 'identity', 'identity_field' ) };

	it( 'produces one modify_identity per changed field', () => {
		const original: SheetData = { identity: { Clan: 'Brujah', Sect: 'Camarilla' } };
		const current: SheetData = { identity: { Clan: 'Toreador', Sect: 'Camarilla' } };

		expect( computeChanges( original, current, blocks ) ).toEqual( [
			{
				change_type: 'modify_identity',
				category: 'identity',
				change_data: { block_slug: 'identity', fields: { Clan: 'Toreador' } },
			},
		] );
	} );

	it( 'compares a multiselect field by value, not reference', () => {
		const original: SheetData = { identity: { chosen_in_clan: [ 'Brujah', 'Toreador' ] } };
		const current: SheetData = { identity: { chosen_in_clan: [ 'Brujah', 'Toreador' ] } };

		expect( computeChanges( original, current, blocks ) ).toEqual( [] );
	} );
} );
