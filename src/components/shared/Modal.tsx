/**
 * Generic modal dialog shell rendered via a portal into `document.body`.
 * Provides a titled header with a close button, a body area for
 * children, and an optional footer. Closes on Escape or a backdrop
 * click, and manages focus on open and close.
 */
import { createPortal, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import './Modal.css';

export interface ModalProps {
	title: string;
	onClose: () => void;
	children: ReactNode;
	footer?: ReactNode;
}

/**
 * Renders `children` and an optional `footer` inside a titled dialog,
 * portaled to `document.body` so it stacks above the surrounding layout
 * regardless of where it was opened from. Calls `onClose` on Escape or a
 * backdrop click; a click inside the dialog itself does not close it.
 */
export function Modal( { title, onClose, children, footer }: ModalProps ) {
	const dialogRef = useRef<HTMLDivElement>( null );

	useEffect( () => {
		const onKeyDown = ( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				onClose();
			}
		};
		document.addEventListener( 'keydown', onKeyDown );
		return () => document.removeEventListener( 'keydown', onKeyDown );
	}, [ onClose ] );

	// Moves focus into the dialog on open and restores it to the previously focused element on close.
	useEffect( () => {
		const previouslyFocused = document.activeElement as HTMLElement | null;
		dialogRef.current?.focus();
		return () => previouslyFocused?.focus?.();
	}, [] );

	return createPortal(
		<div className="be-modal__backdrop" role="presentation" onClick={ onClose }>
			<div
				className="be-modal"
				role="dialog"
				aria-modal="true"
				aria-label={ title }
				tabIndex={ -1 }
				ref={ dialogRef }
				onClick={ ( e ) => e.stopPropagation() }
			>
				<div className="be-modal__header">
					<h3 className="be-modal__title">{ title }</h3>
					<button type="button" className="be-modal__close" onClick={ onClose } aria-label={ __( 'Close', 'beyond-elysium' ) }>
						×
					</button>
				</div>
				<div className="be-modal__body">{ children }</div>
				{ footer && <div className="be-modal__footer">{ footer }</div> }
			</div>
		</div>,
		document.body
	);
}

export default Modal;
