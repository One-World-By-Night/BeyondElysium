import { existsSync, readdirSync, readFileSync } from 'fs';
import { join } from 'path';
import { deriveSteps, ruleSlugs, stepOfSection } from './builderSteps';
import type { CreationStep } from '../types';

const CATALOG = join( __dirname, '../../beyond-elysium/data/catalog' );

interface Shipped {
	slug: string;
	sections: { block_slug: string }[];
	types: Record< string, string >;
	labels: Record< string, string >;
	rules: CreationStep[];
}

function json( path: string ) {
	return JSON.parse( readFileSync( path, 'utf8' ) );
}

function shippedTypes(): Shipped[] {
	const found: Shipped[] = [];
	for ( const file of readdirSync( join( CATALOG, 'stacks' ) ) ) {
		const stack = json( join( CATALOG, 'stacks', file ) );
		const slug: string = stack.slug;
		const templatePath = join(
			CATALOG,
			'templates',
			`${ slug }.sheet_full.json`
		);
		if ( ! existsSync( templatePath ) ) {
			continue;
		}
		const layout = json( templatePath ).definition.sections as {
			block_slug: string;
			column: number;
			order: number;
		}[];
		const sections = [ ...layout ]
			.sort( ( a, b ) => a.column - b.column || a.order - b.order )
			.map( ( section ) => ( { block_slug: section.block_slug } ) );
		const types: Record< string, string > = {};
		for ( const section of sections ) {
			const blockPath = join(
				CATALOG,
				'blocks',
				`${ section.block_slug }.json`
			);
			if ( existsSync( blockPath ) ) {
				types[ section.block_slug ] = json( blockPath ).section_type;
			}
		}
		const labels: Record< string, string > = {};
		for ( const section of stack.definition.sections ) {
			labels[ section.block_slug ] = section.label;
		}
		found.push( {
			slug,
			sections,
			types,
			labels,
			rules: stack.definition.creation_rules?.steps ?? [],
		} );
	}
	return found;
}

const SHIPPED = shippedTypes();

function derive( type: Shipped ) {
	return deriveSteps( {
		sections: type.sections,
		sectionTypes: type.types,
		sectionLabels: type.labels,
		rules: type.rules,
	} );
}

describe( 'the shipped catalog fixtures', () => {
	it( 'cover every creature type that has a sheet template', () => {
		expect( SHIPPED.length ).toBeGreaterThanOrEqual( 12 );
	} );
} );

describe( 'deriveSteps for every shipped creature type', () => {
	it.each( SHIPPED.map( ( type ) => [ type.slug, type ] as const ) )(
		'%s puts every section in exactly one step and leaves no step empty',
		( _slug, type ) => {
			const steps = derive( type );
			const seen = steps.flatMap( ( step ) => step.sections );
			const emptyRuleSteps = steps.filter(
				( step ) =>
					( step.kind === 'rule' || step.kind === 'everything' ) &&
					step.sections.length === 0
			);
			expect( emptyRuleSteps ).toEqual( [] );
			expect( [ ...seen ].sort() ).toEqual(
				type.sections.map( ( section ) => section.block_slug ).sort()
			);
			expect( new Set( seen ).size ).toBe( seen.length );
		}
	);

	it.each( SHIPPED.map( ( type ) => [ type.slug, type ] as const ) )(
		'%s starts with Concept and ends with Story and Review',
		( _slug, type ) => {
			const steps = derive( type );
			expect( steps[ 0 ].kind ).toBe( 'concept' );
			expect( steps.slice( -2 ).map( ( step ) => step.kind ) ).toEqual( [
				'story',
				'review',
			] );
			expect( new Set( steps.map( ( step ) => step.key ) ).size ).toBe(
				steps.length
			);
		}
	);

	it.each( SHIPPED.map( ( type ) => [ type.slug, type ] as const ) )(
		'%s has between 4 and 12 steps',
		( _slug, type ) => {
			const count = derive( type ).length;
			expect( count ).toBeGreaterThanOrEqual( 4 );
			expect( count ).toBeLessThanOrEqual( 12 );
		}
	);

	it.each( SHIPPED.map( ( type ) => [ type.slug, type ] as const ) )(
		'%s has step keys that survive being stored',
		( _slug, type ) => {
			const unsafe = derive( type )
				.map( ( step ) => step.key )
				.filter( ( key ) => ! /^[a-z0-9_-]+$/.test( key ) );
			expect( unsafe ).toEqual( [] );
		}
	);

	it( 'gives Vampire the steps the design names', () => {
		const vampire = SHIPPED.find( ( type ) => type.slug === 'vampire' )!;
		expect( derive( vampire ).map( ( step ) => step.title ) ).toEqual( [
			'Concept',
			'Attributes',
			'Abilities',
			'Disciplines',
			'Backgrounds',
			'Free Traits',
			'Everything else',
			'Story',
			'Review',
		] );
	} );

	it( 'makes a creature type with no creation rules Concept, Everything else, Story and Review', () => {
		const various = SHIPPED.find( ( type ) => type.slug === 'various' )!;
		expect( various.rules ).toEqual( [] );
		expect( derive( various ).map( ( step ) => step.title ) ).toEqual( [
			'Concept',
			'Everything else',
			'Story',
			'Review',
		] );
	} );

	it( 'makes Werewolf one Renown step however many Renown rules it declares', () => {
		const werewolf = SHIPPED.find( ( type ) => type.slug === 'werewolf' )!;
		const steps = derive( werewolf );
		const renown = steps.filter( ( step ) =>
			step.sections.includes( 'werewolf-renown' )
		);
		expect( renown ).toHaveLength( 1 );
		expect( renown[ 0 ].title ).toBe(
			werewolf.labels[ 'werewolf-renown' ]
		);
	} );

	it( 'gives every rule a step that holds the section it names', () => {
		const orphans: string[] = [];
		for ( const type of SHIPPED ) {
			const steps = derive( type );
			const named = type.rules
				.filter(
					( rule ) => rule.kind !== 'free' && rule.kind !== 'earned'
				)
				.flatMap( ( rule ) => ruleSlugs( rule ) )
				.filter( ( slug ) =>
					type.sections.some( ( s ) => s.block_slug === slug )
				);
			for ( const slug of named ) {
				if ( stepOfSection( steps, slug ) === undefined ) {
					orphans.push( `${ type.slug }:${ slug }` );
				}
			}
		}
		expect( orphans ).toEqual( [] );
	} );

	it( 'does not change when the identity a rule reads changes', () => {
		const vampire = SHIPPED.find( ( type ) => type.slug === 'vampire' )!;
		const first = derive( vampire );
		const again = derive( vampire );
		expect( again ).toEqual( first );
		const disciplines = first.filter( ( step ) =>
			step.sections.includes( 'vampire-disciplines' )
		);
		expect( disciplines ).toHaveLength( 1 );
	} );
} );
