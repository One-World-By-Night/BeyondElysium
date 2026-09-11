/**
 * ErrorBoundary contains a rendering crash within one widget's own React tree.
 * Wraps a widget's children; on a thrown error it swaps in a fallback message
 * naming the widget instead of leaving that section of the page blank.
 * Every other widget mounted on the same page keeps rendering normally.
 */
import { Component } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import './ErrorBoundary.css';

export interface ErrorBoundaryProps {
	/** Shown in the fallback message, e.g. the widget name. */
	label: string;
	children: ReactNode;
}

interface ErrorBoundaryState {
	error: Error | null;
}

/**
 * Catches a thrown rendering error anywhere in its child tree and swaps in a
 * fallback message instead of an unhandled crash. Implemented as a class
 * component, since React has no hook equivalent for `componentDidCatch`/
 * `getDerivedStateFromError`.
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
	state: ErrorBoundaryState = { error: null };

	static getDerivedStateFromError( error: Error ): ErrorBoundaryState {
		return { error };
	}

	componentDidCatch( error: Error, info: { componentStack?: string | null } ): void {
		// eslint-disable-next-line no-console
		console.error( `[BE] "${ this.props.label }" crashed:`, error, info.componentStack );
	}

	render(): ReactNode {
		if ( this.state.error ) {
			return (
				<div className="be-error-boundary" role="alert">
					<p>{ sprintf( __( 'Something went wrong loading "%1$s".', 'beyond-elysium' ), this.props.label ) }</p>
					<p className="be-error-boundary__detail">{ this.state.error.message }</p>
				</div>
			);
		}

		return this.props.children;
	}
}

export default ErrorBoundary;
