/**
 * The steps of the character builder, read from a creature type's own sections and creation rules: a Concept step for
 * the identity, one step for each place a rule hands out points, the sections left over, then Story and Review.
 */
import { __ } from '@wordpress/i18n';
import type { CreationStep } from '../types';

export type BuilderStepKind =
	'concept' | 'rule' | 'everything' | 'story' | 'review';

export interface BuilderStep {
	/**
	 * Stable while the creature type's rules do not change, so a step stays open when identity re-derives the list.
	 */
	key: string;
	kind: BuilderStepKind;
	title: string;
	/**
	 * The block slugs the step edits, in sheet order.
	 */
	sections: string[];
}

export interface BuilderSection {
	block_slug: string;
}

export interface BuilderStepsInput {
	/**
	 * Every section of the sheet, in the order it reads.
	 */
	sections: BuilderSection[];
	/**
	 * Each block's section type, by slug.
	 */
	sectionTypes: Record< string, string | undefined >;
	/**
	 * The creature type's own label for each section, by slug.
	 */
	sectionLabels: Record< string, string >;
	rules: CreationStep[];
}

/**
 * A pool's name as part of a step key: lower case letters, digits and dashes, so the key survives being stored.
 */
function keyPart( name: string ): string {
	return name.toLowerCase().replace( /[^a-z0-9_-]+/g, '-' );
}

/**
 * The section a rule's `section.Name` target points into.
 */
export function slugOf( target: string ): string {
	return target.split( '.' )[ 0 ];
}

/**
 * Every block slug a rule reads or writes.
 */
export function ruleSlugs( rule: CreationStep ): string[] {
	switch ( rule.kind ) {
		case 'prioritized':
			return rule.sections;
		case 'budget':
			return [ rule.section ];
		case 'free':
			return Object.keys( rule.rates ).map( slugOf );
		case 'earned':
			return rule.sources.map( ( source ) => source.section );
		case 'limit':
		case 'grant':
			return [ slugOf( rule.section ) ];
		default:
			return [ slugOf( rule.target ) ];
	}
}

/**
 * The steps for one creature type.
 */
export function deriveSteps( input: BuilderStepsInput ): BuilderStep[] {
	const order = input.sections.map( ( section ) => section.block_slug );
	const present = new Set( order );
	const placed = new Set< string >();
	const steps: BuilderStep[] = [];

	const inOrder = ( slugs: string[] ): string[] => {
		const wanted = new Set( slugs );
		return order.filter(
			( slug ) => wanted.has( slug ) && ! placed.has( slug )
		);
	};
	const place = ( key: string, title: string, slugs: string[] ) => {
		const sections = inOrder( slugs ).filter( ( slug ) =>
			present.has( slug )
		);
		if ( sections.length === 0 ) {
			return;
		}
		sections.forEach( ( slug ) => placed.add( slug ) );
		steps.push( { key, kind: 'rule', title, sections } );
	};

	const identity = order.filter(
		( slug ) => input.sectionTypes[ slug ] === 'identity_field'
	);
	identity.forEach( ( slug ) => placed.add( slug ) );
	steps.push( {
		key: 'concept',
		kind: 'concept',
		title: __( 'Concept', 'beyond-elysium' ),
		sections: identity,
	} );

	const freePools = new Set(
		input.rules.flatMap( ( rule ) =>
			rule.kind === 'free' ? [ rule.pool ] : []
		)
	);

	input.rules.forEach( ( rule, index ) => {
		if ( rule.kind === 'prioritized' ) {
			place( `rule-${ index }`, rule.label, rule.sections );
		} else if ( rule.kind === 'budget' ) {
			place(
				`section-${ rule.section }`,
				input.sectionLabels[ rule.section ] ?? rule.label,
				[ rule.section ]
			);
		} else if ( rule.kind === 'free' ) {
			const feeding = input.rules.flatMap( ( other ) =>
				other.kind === 'earned' && other.pool === rule.pool
					? ruleSlugs( other )
					: []
			);
			place( `free-${ keyPart( rule.pool ) }`, rule.label, [
				...ruleSlugs( rule ),
				...feeding,
			] );
		} else if ( rule.kind === 'earned' && ! freePools.has( rule.pool ) ) {
			place(
				`earned-${ keyPart( rule.pool ) }`,
				rule.pool,
				ruleSlugs( rule )
			);
		}
	} );

	const rest = order.filter( ( slug ) => ! placed.has( slug ) );
	if ( rest.length > 0 ) {
		steps.push( {
			key: 'everything',
			kind: 'everything',
			title: __( 'Everything else', 'beyond-elysium' ),
			sections: rest,
		} );
	}

	steps.push(
		{
			key: 'story',
			kind: 'story',
			title: __( 'Story', 'beyond-elysium' ),
			sections: [],
		},
		{
			key: 'review',
			kind: 'review',
			title: __( 'Review', 'beyond-elysium' ),
			sections: [],
		}
	);
	return steps;
}

/**
 * The step a section is edited in.
 */
export function stepOfSection(
	steps: BuilderStep[],
	slug: string
): BuilderStep | undefined {
	return steps.find( ( step ) => step.sections.includes( slug ) );
}
