/**
 * Blocking confirmation dialog with a title, message, and confirm/cancel
 * buttons. Renders as a backdrop-covered alert dialog when open, and
 * renders nothing at all when closed. Used to confirm destructive or
 * hard-to-reverse actions before they happen.
 */
import { __ } from '@wordpress/i18n';
import './ConfirmDialog.css';

export interface ConfirmDialogProps {
	open: boolean;
	title: string;
	message: string;
	confirmLabel?: string;
	cancelLabel?: string;
	onConfirm: () => void;
	onCancel: () => void;
}

/**
 * Renders a modal confirm/cancel dialog with a title and message when
 * `open` is true. Clicking the backdrop or the cancel button calls
 * `onCancel`; clicking confirm calls `onConfirm`. Renders nothing at
 * all when `open` is false, rather than a hidden dialog.
 */
export function ConfirmDialog( { open, title, message, confirmLabel, cancelLabel, onConfirm, onCancel }: ConfirmDialogProps ) {
	if ( ! open ) {
		return null;
	}

	return (
		<div className="be-confirm-dialog__backdrop" role="presentation" onClick={ onCancel }>
			<div
				className="be-confirm-dialog"
				role="alertdialog"
				aria-modal="true"
				aria-labelledby="be-confirm-dialog-title"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<h2 id="be-confirm-dialog-title" className="be-confirm-dialog__title">
					{ title }
				</h2>
				<p className="be-confirm-dialog__message">{ message }</p>
				<div className="be-confirm-dialog__actions">
					<button type="button" className="be-confirm-dialog__cancel" onClick={ onCancel }>
						{ cancelLabel ?? __( 'Cancel', 'beyond-elysium' ) }
					</button>
					<button type="button" className="be-confirm-dialog__confirm" onClick={ onConfirm }>
						{ confirmLabel ?? __( 'Confirm', 'beyond-elysium' ) }
					</button>
				</div>
			</div>
		</div>
	);
}

export default ConfirmDialog;
