/**
 * A panel a player can fold away, with its state remembered between visits.
 */
import type { CSSProperties, ReactNode } from 'react';
import { useCollapsed } from '../../lib/panelCollapse';
import './CollapsiblePanel.css';

export interface CollapsiblePanelProps {
	/**
	 * Stable across renders and releases.
	 */
	id: string;
	/**
	 * Shown in the summary row.
	 */
	heading: ReactNode;
	children: ReactNode;
	/**
	 * Extra class on the wrapping `<details>`.
	 */
	className?: string;
	/**
	 * Inline style on the wrapping `<details>`.
	 */
	style?: CSSProperties;
	/**
	 * Changing this to a new truthy value re-opens the panel even if the player folded it.
	 */
	forceOpenKey?: string | number;
	/**
	 * How the panel starts until this viewer folds or unfolds it themselves.
	 */
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
