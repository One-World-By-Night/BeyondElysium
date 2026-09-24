/**
 * Generic modal dialog shell rendered via a portal into `document.body`.
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
 * Renders `children` and an optional `footer` inside a titled dialog, portaled to `document.body`.
 */
export function Modal( { title, onClose, children, footer }: ModalProps ) {
	const dialogRef = useRef< HTMLDivElement >( null );

	useEffect( () => {
		const onKeyDown = ( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				onClose();
			}
		};
		document.addEventListener( 'keydown', onKeyDown );
		return () => document.removeEventListener( 'keydown', onKeyDown );
	}, [ onClose ] );

	useEffect( () => {
		const previouslyFocused = dialogRef.current?.ownerDocument
			.activeElement as HTMLElement | null;
		dialogRef.current?.focus();
		return () => previouslyFocused?.focus?.();
	}, [] );

	return createPortal(
		<div
			className="be-modal__backdrop"
			role="presentation"
			onClick={ onClose }
		>
			{ /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-noninteractive-element-interactions */ }
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
					<button
						type="button"
						className="be-modal__close"
						onClick={ onClose }
						aria-label={ __( 'Close', 'beyond-elysium' ) }
					>
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
