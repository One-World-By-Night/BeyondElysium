/**
 * Editable permanent/temporary resource pool tracker (Willpower, Blood,
 * Renown, ...). Shows a row of state-colored dots per track alongside a
 * numeric value and +/- stepper buttons for adjusting it, up to a shared
 * maximum. Can be rendered read-only.
 */
import { __ } from '@wordpress/i18n';
import { computeDotStates, stepTrackValue, type DotState } from '../../lib/dotTrack';
import './DotTracker.css';

export interface DotTrackerValue {
	permanent: number;
	temporary: number;
}

export interface DotTrackerProps {
	permanent: number;
	temporary: number;
	max: number;
	onChange: ( next: DotTrackerValue ) => void;
	readOnly?: boolean;
}

const STATE_CLASS: Record<DotState, string> = {
	filled: 'be-dot-tracker__dot--filled',
	spent: 'be-dot-tracker__dot--spent',
	overflow: 'be-dot-tracker__dot--overflow',
	empty: 'be-dot-tracker__dot--empty',
};

/**
 * Renders permanent and temporary tracks for one resource pool sharing a
 * single maximum. Each track shows a row of dots reflecting its state
 * (filled, spent, overflow, empty) plus its numeric value, with +/-
 * buttons to adjust it. The dot rows are `aria-hidden`, decorative
 * summaries only - the numeric value and stepper buttons are the
 * operable, screen-reader-visible controls. `readOnly` disables both
 * steppers and marks the whole tracker `aria-disabled`.
 */
export function DotTracker( { permanent, temporary, max, onChange, readOnly }: DotTrackerProps ) {
	const states = computeDotStates( permanent, temporary, max );

	const step = ( track: 'permanent' | 'temporary', delta: 1 | -1 ) => {
		if ( readOnly ) {
			return;
		}
		if ( track === 'permanent' ) {
			onChange( { permanent: stepTrackValue( permanent, delta, max ), temporary } );
		} else {
			onChange( { permanent, temporary: stepTrackValue( temporary, delta, max ) } );
		}
	};

	return (
		<div className="be-dot-tracker" aria-disabled={ readOnly ? 'true' : undefined }>
			<div className="be-dot-tracker__row be-dot-tracker__row--permanent">
				<span className="be-dot-tracker__row-label" aria-hidden="true">{ __( 'P', 'beyond-elysium' ) }</span>
				<div className="be-dot-tracker__dots" aria-hidden="true">
					{ states.map( ( state, i ) => (
						<span key={ `perm-${ i }` } className={ `be-dot-tracker__dot ${ STATE_CLASS[ state ] }` } />
					) ) }
				</div>
				<div className="be-dot-tracker__stepper">
					<button
						type="button"
						className="be-dot-tracker__stepper-button"
						disabled={ readOnly || permanent <= 0 }
						aria-label={ __( 'Decrease permanent', 'beyond-elysium' ) }
						onClick={ () => step( 'permanent', -1 ) }
					>
						−
					</button>
					<span className="be-dot-tracker__value">{ permanent }</span>
					<button
						type="button"
						className="be-dot-tracker__stepper-button"
						disabled={ readOnly || permanent >= max }
						aria-label={ __( 'Increase permanent', 'beyond-elysium' ) }
						onClick={ () => step( 'permanent', 1 ) }
					>
						+
					</button>
				</div>
			</div>
			<div className="be-dot-tracker__row be-dot-tracker__row--temporary">
				<span className="be-dot-tracker__row-label" aria-hidden="true">{ __( 'T', 'beyond-elysium' ) }</span>
				<div className="be-dot-tracker__dots" aria-hidden="true">
					{ states.map( ( state, i ) => (
						<span key={ `temp-${ i }` } className={ `be-dot-tracker__dot be-dot-tracker__dot--temp ${ STATE_CLASS[ state ] }` } />
					) ) }
				</div>
				<div className="be-dot-tracker__stepper">
					<button
						type="button"
						className="be-dot-tracker__stepper-button"
						disabled={ readOnly || temporary <= 0 }
						aria-label={ __( 'Decrease temporary', 'beyond-elysium' ) }
						onClick={ () => step( 'temporary', -1 ) }
					>
						−
					</button>
					<span className="be-dot-tracker__value">{ temporary }</span>
					<button
						type="button"
						className="be-dot-tracker__stepper-button"
						disabled={ readOnly || temporary >= max }
						aria-label={ __( 'Increase temporary', 'beyond-elysium' ) }
						onClick={ () => step( 'temporary', 1 ) }
					>
						+
					</button>
				</div>
			</div>
		</div>
	);
}

export default DotTracker;
