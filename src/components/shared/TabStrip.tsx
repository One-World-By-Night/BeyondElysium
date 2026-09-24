/**
 * A compact section picker: a <select> of every tab plus an explicit Go button.
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
