/**
 * Draws display text with every dot as a CSS circle of one fixed size (1.0.0-review F-016, owner
 * ruling 2026-09-14: all dots the same size, the large one) - a trait's rating and a pool's
 * points alike. Glyphs were not enough: a theme's font lacking one of them made the browser borrow
 * it from another font, at another size. The run keeps its glyphs as its accessible name.
 */
import { Fragment } from '@wordpress/element';
import { DOT_STATES, splitDots } from '../../lib/dotRuns';
import './Dots.css';

export function WithDots( { text }: { text: string } ) {
	return (
		<>
			{ splitDots( text ).map( ( run, index ) =>
				run.dots ? (
					<span
						key={ index }
						className="be-dots"
						role="img"
						aria-label={ run.text }
					>
						{ Array.from( run.text ).map( ( glyph, position ) =>
							DOT_STATES[ glyph ] ? (
								<span
									key={ position }
									className={ `be-dot be-dot--${ DOT_STATES[ glyph ] }` }
								/>
							) : (
								<span
									key={ position }
									className="be-dots__gap"
								/>
							)
						) }
					</span>
				) : (
					<Fragment key={ index }>{ run.text }</Fragment>
				)
			) }
		</>
	);
}

export default WithDots;
