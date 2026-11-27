/**
 * The row of named steps on a wide screen, and a "step number of count · name" button that opens the same list on a
 * phone. Any step opens at any time; a mark says which steps' budgets are used or over.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { StepMark } from '../../lib/builderBar';
import type { BuilderStep } from '../../lib/builderSteps';

export interface BuilderStepListProps {
	steps: BuilderStep[];
	current: string;
	marks: Record< string, StepMark >;
	onOpen: ( key: string ) => void;
}

function markLabel( mark: StepMark ): string {
	if ( mark === 'done' ) {
		return __( 'Done', 'beyond-elysium' );
	}
	if ( mark === 'over' ) {
		return __( 'Over', 'beyond-elysium' );
	}
	return '';
}

export function BuilderStepList( {
	steps,
	current,
	marks,
	onOpen,
}: BuilderStepListProps ) {
	const [ open, setOpen ] = useState( false );
	const index = Math.max(
		0,
		steps.findIndex( ( step ) => step.key === current )
	);

	return (
		<nav
			className="be-builder-steps"
			aria-label={ __( 'Steps', 'beyond-elysium' ) }
		>
			<button
				type="button"
				className="be-builder-steps__toggle"
				aria-expanded={ open }
				onClick={ () => setOpen( ! open ) }
			>
				{ sprintf(
					/* translators: 1: the step number, 2: how many steps there are, 3: the step's name */
					__( 'Step %1$d of %2$d · %3$s', 'beyond-elysium' ),
					index + 1,
					steps.length,
					steps[ index ]?.title ?? ''
				) }
			</button>
			<ol
				className={ `be-builder-steps__list${
					open ? ' be-builder-steps__list--open' : ''
				}` }
			>
				{ steps.map( ( step ) => {
					const mark = marks[ step.key ] ?? 'none';
					return (
						<li key={ step.key }>
							<button
								type="button"
								className={ `be-builder-steps__step be-builder-steps__step--${ mark }${
									step.key === current
										? ' be-builder-steps__step--current'
										: ''
								}` }
								aria-current={
									step.key === current ? 'step' : undefined
								}
								onClick={ () => {
									setOpen( false );
									onOpen( step.key );
								} }
							>
								<span>{ step.title }</span>
								{ markLabel( mark ) && (
									<span className="be-builder-steps__mark">
										{ markLabel( mark ) }
									</span>
								) }
							</button>
						</li>
					);
				} ) }
			</ol>
		</nav>
	);
}

export default BuilderStepList;
