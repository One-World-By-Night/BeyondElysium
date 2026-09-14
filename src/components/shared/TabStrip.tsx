/**
 * A row of tab buttons, matching the `role="tablist"`/`role="tab"`/`aria-selected`
 * pattern already established in `AdminAprSettings.tsx` - extracted here since
 * page-consolidation-design.md's two new tabbed pages are a second and third user
 * of the identical shape.
 */
import './TabStrip.css';

export interface Tab {
	key: string;
	label: string;
}

export interface TabStripProps {
	tabs: Tab[];
	active: string;
	onChange: ( key: string ) => void;
}

export function TabStrip( { tabs, active, onChange }: TabStripProps ) {
	return (
		<div className="be-tab-strip" role="tablist">
			{ tabs.map( ( tab ) => (
				<button
					key={ tab.key }
					type="button"
					role="tab"
					aria-selected={ active === tab.key }
					className={ active === tab.key ? 'is-active' : '' }
					onClick={ () => onChange( tab.key ) }
				>
					{ tab.label }
				</button>
			) ) }
		</div>
	);
}

export default TabStrip;
