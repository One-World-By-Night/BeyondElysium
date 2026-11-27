/**
 * What the budget bar says on one step of the builder: the rule lines of the step's own sections, the free pools that
 * pay for them and the experience left.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { CreationStep } from '../types';
import type { CreationTally, CreationTallyLimitFlag } from '../types/character';
import { ruleSlugs, slugOf, type BuilderStep } from './builderSteps';

export type BarState = 'ok' | 'short' | 'over' | 'info';

export interface BarLine {
	key: string;
	text: string;
	state: BarState;
}

/**
 * The mark a step carries in the step list.
 */
export type StepMark = 'none' | 'open' | 'done' | 'over';

export interface BarModel {
	lines: BarLine[];
	/**
	 * Said once under the Attributes line: how the build reads the largest category.
	 */
	note: string | null;
	mark: StepMark;
}

/**
 * The pools whose rules cover at least one of a step's sections.
 */
function coveringPools(
	step: BuilderStep,
	tally: CreationTally,
	rules: CreationStep[]
): string[] {
	const pools = new Set< string >();
	for ( const rule of rules ) {
		if ( rule.kind !== 'free' && rule.kind !== 'earned' ) {
			continue;
		}
		if (
			ruleSlugs( rule ).some( ( slug ) => step.sections.includes( slug ) )
		) {
			pools.add( rule.pool );
		}
	}
	return [ ...pools ].filter( ( name ) => tally.pools[ name ] !== undefined );
}

/**
 * Whether the experience line is worth showing: there is some to start with, or the build needs some.
 */
export function showsXp( xp: { starting: number; needed: number } ): boolean {
	return xp.starting > 0 || xp.needed > 0;
}

/**
 * The limit flags that point into a step's sections.
 */
export function limitsForStep(
	step: BuilderStep,
	tally: CreationTally | null
): CreationTallyLimitFlag[] {
	return ( tally?.limits ?? [] ).filter( ( flag ) =>
		step.sections.includes( slugOf( flag.target ) )
	);
}

function overText( base: string, over: number, source: string ): string {
	return `${ base } · ${ sprintf(
		/* translators: 1: how many are over, 2: where the rest comes from (a pool's name, or XP) */
		__( '%1$d over: from %2$s', 'beyond-elysium' ),
		over,
		source
	) }`;
}

function ratio( label: string, used: number, allowed: number ): string {
	return sprintf(
		/* translators: 1: a budget's name, 2: how many are used, 3: how many are allowed */
		__( '%1$s: %2$d of %3$d', 'beyond-elysium' ),
		label,
		used,
		allowed
	);
}

/**
 * The bar for one step.
 */
export function barModel(
	step: BuilderStep,
	tally: CreationTally | null,
	rules: CreationStep[]
): BarModel {
	if ( ! tally ) {
		return { lines: [], note: null, mark: 'none' };
	}

	const covering = coveringPools( step, tally, rules );
	const poolWithRoom = covering.find(
		( name ) => tally.pools[ name ].left > 0
	);
	const source = poolWithRoom
		? tally.pools[ poolWithRoom ].label
		: __( 'XP', 'beyond-elysium' );

	const lines: BarLine[] = [];
	let note: string | null = null;
	let budgets = 0;
	let satisfied = 0;

	tally.steps.forEach( ( report, index ) => {
		if ( ! report.applies ) {
			return;
		}
		if ( report.kind === 'prioritized' ) {
			const rows = report.sections.filter( ( row ) =>
				step.sections.includes( row.section )
			);
			if ( rows.length === 0 ) {
				return;
			}
			rows.forEach( ( row ) => {
				budgets++;
				const exact = row.used === row.allowed;
				if ( exact ) {
					satisfied++;
				}
				const base = ratio( row.label, row.used, row.allowed );
				lines.push( {
					key: `p-${ index }-${ row.section }`,
					text: row.over
						? overText( base, row.used - row.allowed, source )
						: base,
					state: row.over ? 'over' : exact ? 'ok' : 'short',
				} );
			} );
			const amounts = report.sections.map( ( row ) => row.allowed );
			if ( amounts.length === 3 ) {
				note = sprintf(
					/* translators: 1: dots for the largest category, 2: for the next, 3: for the last */
					__(
						'Your largest category counts as %1$d, the next as %2$d, the last as %3$d.',
						'beyond-elysium'
					),
					amounts[ 0 ],
					amounts[ 1 ],
					amounts[ 2 ]
				);
			}
		} else if ( report.kind === 'budget' ) {
			if ( ! step.sections.includes( report.section ) ) {
				return;
			}
			budgets++;
			const full = report.used >= report.allowed;
			if ( full && ! report.over ) {
				satisfied++;
			}
			const base = ratio( report.label, report.used, report.allowed );
			lines.push( {
				key: `b-${ index }`,
				text: report.over
					? overText( base, report.used - report.allowed, source )
					: base,
				state: report.over ? 'over' : full ? 'ok' : 'short',
			} );
			report.quotas.forEach( ( quota, q ) => {
				lines.push( {
					key: `q-${ index }-${ q }`,
					text: ratio( quota.label, quota.met, quota.min ),
					state: quota.ok ? 'ok' : 'short',
				} );
			} );
		}
	} );

	for ( const name of covering ) {
		const pool = tally.pools[ name ];
		lines.push( {
			key: `pool-${ name }`,
			text: sprintf(
				/* translators: 1: a pool's name (Free Traits), 2: points left, 3: points the pool holds in total */
				__( '%1$s: %2$d of %3$d left', 'beyond-elysium' ),
				pool.label,
				pool.left,
				pool.own + pool.earned
			),
			state: pool.left < 0 ? 'over' : 'info',
		} );
	}

	const over = lines.some( ( line ) => line.state === 'over' );
	const flagged = limitsForStep( step, tally ).length > 0;

	if ( showsXp( tally.xp ) ) {
		lines.push( {
			key: 'xp',
			text: sprintf(
				/* translators: 1: experience left, 2: experience a new character starts with */
				__( 'XP %1$d of %2$d left', 'beyond-elysium' ),
				tally.xp.left,
				tally.xp.starting
			),
			state: tally.xp.left < 0 ? 'over' : 'info',
		} );
	}

	let mark: StepMark = 'none';
	if ( over || flagged ) {
		mark = 'over';
	} else if ( budgets > 0 ) {
		mark = satisfied === budgets ? 'done' : 'open';
	}
	return { lines, note, mark };
}
