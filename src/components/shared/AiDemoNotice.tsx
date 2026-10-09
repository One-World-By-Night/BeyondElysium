/**
 * The explanation a demo chronicle shows in place of an AI drafting control.
 */
import { __ } from '@wordpress/i18n';
import { aiDemoMessage } from '../../lib/aiDemo';
import Modal from './Modal';
import './AiDemoNotice.css';

/**
 * The explanation as a line of text, for a control that already sits inside a dialog.
 */
export function AiDemoNotice() {
	return (
		<p className="be-ai-demo-notice" role="status">
			{ aiDemoMessage() }
		</p>
	);
}

/**
 * The explanation in a dialog of its own, with a single Close button.
 */
export function AiDemoModal( {
	title,
	onClose,
}: {
	title: string;
	onClose: () => void;
} ) {
	return (
		<Modal
			title={ title }
			onClose={ onClose }
			footer={
				<button type="button" onClick={ onClose }>
					{ __( 'Close', 'beyond-elysium' ) }
				</button>
			}
		>
			<AiDemoNotice />
		</Modal>
	);
}

export default AiDemoNotice;
