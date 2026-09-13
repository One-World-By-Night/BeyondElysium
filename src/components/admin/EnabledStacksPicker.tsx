/**
 * The one genuinely new control the Chronicle Setup checklist needs
 * (GS-2/GS-5, guided-chronicle-setup-design.md §6.1): a checkbox list over
 * whatever creature stacks exist. Engine-pure - no creature-specific code,
 * renders whatever `Creature_Stack::all()` returns, so a twelfth stack
 * (a future `hunter`, per §3.7) needs no change here.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { CreatureStack } from '../../types';
import './EnabledStacksPicker.css';

export interface EnabledStacksPickerProps {
	enabled: string[] | null;
	onSave: ( slugs: string[] ) => void;
	saving?: boolean;
}

/**
 * Renders a checkbox per real creature stack, pre-checked to the chronicle's
 * current `enabled_stacks` (every box checked when the setting is absent -
 * "all eleven" is the default, not a guess). Refuses to save zero
 * selections (§9's named risk: an empty array must never be stored as
 * "none").
 */
export function EnabledStacksPicker( { enabled, onSave, saving }: EnabledStacksPickerProps ) {
	const [ stacks, setStacks ] = useState<CreatureStack[]>( [] );
	const [ checked, setChecked ] = useState<Set<string>>( new Set() );

	useEffect( () => {
		api.creatureStacks.list().then( setStacks );
	}, [] );

	useEffect( () => {
		if ( enabled === null ) {
			setChecked( new Set( stacks.map( ( s ) => s.slug ) ) );
		} else {
			setChecked( new Set( enabled ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ enabled, stacks.length ] );

	function toggle( slug: string ) {
		setChecked( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( slug ) ) {
				next.delete( slug );
			} else {
				next.add( slug );
			}
			return next;
		} );
	}

	const canSave = checked.size > 0;

	return (
		<div className="be-enabled-stacks-picker">
			<ul className="be-enabled-stacks-picker__list">
				{ stacks.map( ( stack ) => (
					<li key={ stack.slug }>
						<label>
							<input
								type="checkbox"
								checked={ checked.has( stack.slug ) }
								onChange={ () => toggle( stack.slug ) }
							/>
							{ stack.name }
						</label>
					</li>
				) ) }
			</ul>
			{ ! canSave && (
				<p className="be-enabled-stacks-picker__warning">
					{ __( 'At least one creature type must stay enabled.', 'beyond-elysium' ) }
				</p>
			) }
			<button
				type="button"
				className="button button-primary"
				disabled={ ! canSave || saving }
				onClick={ () => onSave( Array.from( checked ) ) }
			>
				{ saving ? __( 'Saving…', 'beyond-elysium' ) : __( 'Save', 'beyond-elysium' ) }
			</button>
		</div>
	);
}

export default EnabledStacksPicker;
