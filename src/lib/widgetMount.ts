/**
 * Marks a widget's mount point: dark-mode plugins skip it, and translation plugins leave its text alone, since the
 * widget translates its own.
 */
export function markWidgetMount( el: HTMLElement ): void {
	el.classList.add( 'wp-dark-mode-ignore' );
	el.setAttribute( 'data-no-translation', '' );
}
