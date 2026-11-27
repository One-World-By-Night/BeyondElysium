/**
 * The builder's last step: every pick by step, how the build stands, what must be fixed first, what is only a
 * warning, what a Storyteller will look at and how the character starts.
 */
import { __, sprintf } from '@wordpress/i18n';
import { showsXp } from '../../lib/builderBar';
import type { ReviewModel } from '../../lib/builderReview';
import type { CreationTally } from '../../types/character';

export interface BuilderReviewProps {
	model: ReviewModel;
	tally: CreationTally | null;
	creating: boolean;
	error: string | null;
	onCreate: () => void;
}

export function BuilderReview( {
	model,
	tally,
	creating,
	error,
	onCreate,
}: BuilderReviewProps ) {
	return (
		<div className="be-builder-review">
			{ model.mustFix.length > 0 && (
				<section
					className="be-builder-review__block be-builder-review__block--fix"
					role="alert"
				>
					<h3>{ __( 'Must fix', 'beyond-elysium' ) }</h3>
					<ul>
						{ model.mustFix.map( ( line ) => (
							<li key={ line }>{ line }</li>
						) ) }
					</ul>
				</section>
			) }

			{ model.warnings.length > 0 && (
				<section className="be-builder-review__block be-builder-review__block--warn">
					<h3>{ __( 'Warnings', 'beyond-elysium' ) }</h3>
					<ul>
						{ model.warnings.map( ( line ) => (
							<li key={ line }>{ line }</li>
						) ) }
					</ul>
				</section>
			) }

			{ model.needs.length > 0 && (
				<section className="be-builder-review__block">
					<h3>{ __( 'Needs a Storyteller', 'beyond-elysium' ) }</h3>
					<ul>
						{ model.needs.map( ( pick, i ) => (
							<li key={ `${ pick.name }-${ i }` }>
								<strong>{ pick.name }</strong>
								{ pick.reason ? `: ${ pick.reason }` : '' }
							</li>
						) ) }
					</ul>
				</section>
			) }

			{ tally && (
				<section className="be-builder-review__block">
					<h3>{ __( 'The build', 'beyond-elysium' ) }</h3>
					<ul>
						{ Object.values( tally.pools ).map( ( pool ) => (
							<li key={ pool.label }>
								{ sprintf(
									/* translators: 1: a pool's name, 2: points left, 3: points the pool holds in total */
									__(
										'%1$s: %2$d of %3$d left',
										'beyond-elysium'
									),
									pool.label,
									pool.left,
									pool.own + pool.earned
								) }
							</li>
						) ) }
						{ showsXp( tally.xp ) && (
							<li>
								{ sprintf(
									/* translators: 1: experience left, 2: experience a new character starts with */
									__(
										'XP %1$d of %2$d left',
										'beyond-elysium'
									),
									tally.xp.left,
									tally.xp.starting
								) }
							</li>
						) }
					</ul>
				</section>
			) }

			{ model.groups.map( ( group ) => (
				<section key={ group.key } className="be-builder-review__block">
					<h3>{ group.title }</h3>
					{ group.sections.map( ( section ) => (
						<div key={ section.slug }>
							<h4>{ section.title }</h4>
							<p>{ section.lines.join( ', ' ) }</p>
						</div>
					) ) }
				</section>
			) ) }

			{ model.background && (
				<section className="be-builder-review__block">
					<h3>{ __( 'Story', 'beyond-elysium' ) }</h3>
					<p>{ model.background }</p>
				</section>
			) }

			<section className="be-builder-review__block">
				<h3>{ __( 'What happens next', 'beyond-elysium' ) }</h3>
				<p>{ model.next }</p>
			</section>

			{ error && (
				<div className="be-builder__error" role="alert">
					{ error }
				</div>
			) }

			<button
				type="button"
				className="be-builder__create"
				disabled={ creating || ! model.canCreate }
				onClick={ onCreate }
			>
				{ creating
					? __( 'Creating…', 'beyond-elysium' )
					: __( 'Create character', 'beyond-elysium' ) }
			</button>
		</div>
	);
}

export default BuilderReview;
