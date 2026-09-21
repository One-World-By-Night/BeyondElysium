/**
 * A panel a player can fold away, with its state remembered between visits.
 *
 * Built on native `<details>`/`<summary>` rather than a custom toggle, matching
 * `ApprovalQueue`'s own existing disclosure - the native element carries keyboard
 * operation, focus handling and screen-reader semantics for free, none of which a
 * hand-rolled div-and-onClick would get right without work.
 *
 * The heading stays visible when folded, count and all: a collapsed
 * "Pending Changes (7)" must still say 7, or folding it hides the very thing a player
 * needs to know before submitting.
 */
import type { CSSProperties, ReactNode } from 'react';
import { useCollapsed } from '../../lib/panelCollapse';
import './CollapsiblePanel.css';

export interface CollapsiblePanelProps {
	/** Stable across renders and releases - it is the storage key for this panel's state. */
	id: string;
	/** Shown in the summary row; stays visible when collapsed. */
	heading: ReactNode;
	children: ReactNode;
	/** Extra class on the wrapping `<details>`, so callers keep their own panel styling. */
	className?: string;
	/** Inline style on the wrapping `<details>` - the editor grid sets `gridColumn` here. */
	style?: CSSProperties;
	/**
	 * Changing this to a new truthy value re-opens the panel even if the player folded it -
	 * the "something new arrived" rule. Only ever forces open, never shut.
	 */
	forceOpenKey?: string | number;
	/** How the panel starts until this viewer folds or unfolds it themselves. */
	defaultCollapsed?: boolean;
}

export default function CollapsiblePanel( {
	id,
	heading,
	children,
	className,
	style,
	forceOpenKey,
	defaultCollapsed = false,
}: CollapsiblePanelProps ) {
	const [ collapsed, setCollapsed ] = useCollapsed(
		id,
		forceOpenKey,
		defaultCollapsed
	);

	return (
		<details
			className={
				'be-collapsible' + ( className ? ' ' + className : '' )
			}
			style={ style }
			open={ ! collapsed }
			onToggle={ ( e ) =>
				setCollapsed( ! ( e.currentTarget as HTMLDetailsElement ).open )
			}
		>
			<summary
				className="be-collapsible__summary"
				onClick={ ( e ) => {
					/*
					 * Headings carry their own controls - a help `?`, a link, a count badge.
					 * Clicking one must do that control's job without folding the panel it is
					 * explaining. Cancelling the summary's default action is what suppresses
					 * the toggle; the control still receives its own click, and a keyboard
					 * Enter behaves identically because it arrives as a click on the control.
					 * A click on the bare summary matches nothing here and toggles as usual.
					 */
					if (
						( e.target as HTMLElement ).closest(
							'button, a, input, select, textarea'
						)
					) {
						e.preventDefault();
					}
				} }
			>
				{ heading }
			</summary>
			<div className="be-collapsible__body">{ children }</div>
		</details>
	);
}
