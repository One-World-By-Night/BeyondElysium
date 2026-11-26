/**
 * Says where an autosaving group of fields stands: saving, saved, or couldn't save and trying again.
 */
import { __ } from '@wordpress/i18n';
import type { AutosaveState } from '../../lib/autosave';

export interface SaveStatusProps {
	state: AutosaveState;
	message?: string;
	onRetry: () => void;
}

export function SaveStatus( { state, message, onRetry }: SaveStatusProps ) {
	return (
		<p className="be-story__status" role="status" aria-live="polite">
			{ state === 'saving' && __( 'Saving…', 'beyond-elysium' ) }
			{ state === 'saved' && __( 'Saved', 'beyond-elysium' ) }
			{ state === 'failed' && (
				<>
					<span className="be-story__failed">
						{ __(
							"Couldn't save. Trying again…",
							'beyond-elysium'
						) }
					</span>{ ' ' }
					{ message && (
						<span className="be-story__why">{ message }</span>
					) }{ ' ' }
					<button
						type="button"
						className="be-story__retry"
						onClick={ onRetry }
					>
						{ __( 'Retry', 'beyond-elysium' ) }
					</button>
				</>
			) }
		</p>
	);
}

export default SaveStatus;
