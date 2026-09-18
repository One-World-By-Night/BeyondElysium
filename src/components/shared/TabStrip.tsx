/**
 * A compact section picker: a <select> of every tab plus an explicit Go button.
 * Replaces the row of tab buttons this component used to render - on a screen with
 * more than a handful of tabs (My Chronicle's 11, on a phone), the button row wrapped
 * into a dense, hard-to-scan grid, with the last row sometimes clipped behind other
 * floating chrome (owner report, 2026-09-18, live on owbn-boston.net). Matches the
 * select+Go shape CharacterSheet.tsx's own action picker already established.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
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
	const [ pending, setPending ] = useState( active );

	// Follows the active tab when it changes from outside this component (a parent
	// switching it directly, not through this control's own Go click).
	useEffect( () => {
		setPending( active );
	}, [ active ] );

	return (
		<div className="be-tab-strip">
			<select
				className="be-tab-strip__select"
				aria-label={ __( 'Section', 'beyond-elysium' ) }
				value={ pending }
				onChange={ ( e ) => setPending( e.target.value ) }
			>
				{ tabs.map( ( tab ) => (
					<option key={ tab.key } value={ tab.key }>
						{ tab.label }
					</option>
				) ) }
			</select>
			<button
				type="button"
				className="be-tab-strip__go"
				disabled={ pending === active }
				onClick={ () => onChange( pending ) }
			>
				{ __( 'Go', 'beyond-elysium' ) }
			</button>
		</div>
	);
}

export default TabStrip;
