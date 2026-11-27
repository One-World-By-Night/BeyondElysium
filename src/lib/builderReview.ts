/**
 * What the builder's Review step says: every pick by step, how the build stands, what must be fixed before the
 * character can be created, what is only a warning, what a Storyteller will look at and how the character starts.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import type { CreationTally } from '../types/character';
import {
	describeIdentityFieldHolding,
	describeResourcePoolHolding,
	describeTieredPowerHolding,
	describeTraitListHolding,
} from './describeHolding';
import { unbuyableLine } from './raisedByPool';
import { limitFlagText } from './tallyText';
import type { BuilderStep } from './builderSteps';

export interface ReviewSection {
	slug: string;
	title: string;
	lines: string[];
}

export interface ReviewGroup {
	key: string;
	title: string;
	sections: ReviewSection[];
}

export interface ReviewModel {
	groups: ReviewGroup[];
	/**
	 * The first line of the background, when there is one.
	 */
	background: string;
	mustFix: string[];
	warnings: string[];
	needs: Array< { name: string; section: string; reason: string | null } >;
	next: string;
	canCreate: boolean;
}

export interface ReviewInput {
	steps: BuilderStep[];
	sheet: Record< string, unknown >;
	blocks: Record< string, { section_type: string } >;
	titles: Record< string, string >;
	tally: CreationTally | null;
	name: string;
	stackSlug: string;
	biography: string;
	/**
	 * A Storyteller sets a pool past the book's maximum; a player is told they can't.
	 */
	isManager?: boolean;
}

/**
 * What one section holds, a line each.
 */
export function sectionLines( type: string, held: unknown ): string[] {
	if ( type === 'trait_list' || type === 'tiered_power' ) {
		return ( Array.isArray( held ) ? held : [] )
			.filter(
				( row ): row is { name: string } =>
					!! row &&
					typeof row === 'object' &&
					typeof row.name === 'string'
			)
			.map( ( row ) =>
				type === 'trait_list'
					? describeTraitListHolding( row )
					: describeTieredPowerHolding( row as never )
			);
	}
	if ( held && typeof held === 'object' && ! Array.isArray( held ) ) {
		const entries = Object.entries( held as Record< string, unknown > );
		if ( type === 'resource_pool' ) {
			return entries.map( ( [ pool, value ] ) =>
				describeResourcePoolHolding(
					pool,
					value as { permanent?: number } | number
				)
			);
		}
		if ( type === 'identity_field' ) {
			return entries
				.filter(
					( [ , value ] ) =>
						value !== '' && value !== null && value !== undefined
				)
				.map( ( [ field, value ] ) =>
					describeIdentityFieldHolding( field, value )
				);
		}
	}
	return [];
}

/**
 * How the character starts, in one line.
 */
export function startsLine(
	status: 'active' | 'pending',
	reasons: string[],
	picks: number
): string {
	if ( reasons.includes( 'join' ) ) {
		return __(
			'This is a request to join; a Storyteller approves it before you can open the sheet.',
			'beyond-elysium'
		);
	}
	if ( status === 'active' ) {
		return __( 'Starts active.', 'beyond-elysium' );
	}
	const chronicle = reasons.includes( 'chronicle' );
	const rules = reasons.includes( 'rules' ) && picks > 0;
	if ( chronicle && rules ) {
		return sprintf(
			/* translators: %d: how many picks the chronicle's rules hand to a Storyteller */
			_n(
				'Starts pending: this chronicle reviews new characters, and %d pick needs a Storyteller.',
				'Starts pending: this chronicle reviews new characters, and %d picks need a Storyteller.',
				picks,
				'beyond-elysium'
			),
			picks
		);
	}
	if ( rules ) {
		return sprintf(
			/* translators: %d: how many picks the chronicle's rules hand to a Storyteller */
			_n(
				'Starts pending: %d pick needs a Storyteller.',
				'Starts pending: %d picks need a Storyteller.',
				picks,
				'beyond-elysium'
			),
			picks
		);
	}
	return __(
		'Starts pending: this chronicle reviews new characters.',
		'beyond-elysium'
	);
}

/**
 * The first line of a rich-text background, without its markup.
 */
export function firstLine( html: string ): string {
	const text = html
		.replace( /<\/(p|div|li|h[1-6])>|<br\s*\/?>/gi, '\n' )
		.replace( /<[^>]*>/g, '' )
		.replace( /&nbsp;/g, ' ' )
		.replace( /&amp;/g, '&' );
	return (
		text
			.split( '\n' )
			.map( ( line ) => line.trim() )
			.find( ( line ) => line !== '' ) ?? ''
	);
}

export function reviewModel( input: ReviewInput ): ReviewModel {
	const { tally } = input;

	const groups: ReviewGroup[] = [];
	for ( const step of input.steps ) {
		if ( step.sections.length === 0 ) {
			continue;
		}
		const sections: ReviewSection[] = [];
		for ( const slug of step.sections ) {
			const lines = sectionLines(
				input.blocks[ slug ]?.section_type ?? '',
				input.sheet[ slug ]
			);
			if ( lines.length > 0 ) {
				sections.push( {
					slug,
					title: input.titles[ slug ] ?? slug,
					lines,
				} );
			}
		}
		if ( sections.length > 0 ) {
			groups.push( { key: step.key, title: step.title, sections } );
		}
	}

	const mustFix: string[] = [];
	if ( input.name.trim() === '' ) {
		mustFix.push( __( 'Give the character a name.', 'beyond-elysium' ) );
	}
	if ( input.stackSlug === '' ) {
		mustFix.push( __( 'Choose a creature type.', 'beyond-elysium' ) );
	}
	if ( tally && tally.xp.left < 0 ) {
		mustFix.push(
			sprintf(
				/* translators: 1: the experience the build needs, 2: the experience a new character starts with */
				__(
					'This build needs %1$d XP and a new character here starts with %2$d.',
					'beyond-elysium'
				),
				tally.xp.needed,
				tally.xp.starting
			)
		);
	}
	if ( tally && ! input.isManager ) {
		for ( const item of tally.unbuyable ?? [] ) {
			mustFix.push( unbuyableLine( item ) );
		}
	}

	const needs = ( tally?.needs_storyteller ?? [] ).map( ( pick ) => ( {
		name: pick.name,
		section: pick.section_label,
		reason: pick.reason,
	} ) );
	const starts = tally?.starts ?? { status: 'active' as const, reasons: [] };

	return {
		groups,
		background: firstLine( input.biography ),
		mustFix,
		warnings: ( tally?.limits ?? [] ).map( limitFlagText ),
		needs,
		next: startsLine( starts.status, starts.reasons, needs.length ),
		canCreate: mustFix.length === 0,
	};
}
