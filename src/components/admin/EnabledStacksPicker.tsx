/**
 * A checkbox list over whatever creature stacks exist, for the Chronicle Setup checklist.
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
	/**
	 * The chronicle whose own creature types (built from nothing, not just the book's) should be offered too.
	 */
	gameSlug: string;
}

/**
 * Renders a checkbox per real creature stack - the book's, and this chronicle's own, bar a Storyteller-only one, which
 * is always on - pre-checked to the chronicle's current `enabled_stacks` (every box checked when the setting is absent, except one declaring its own
 * `stack_definition.default_enabled: false` - "every one that exists, bar an opt-in type" is the default).
 */
export function EnabledStacksPicker( {
	enabled,
	onSave,
	saving,
	gameSlug,
}: EnabledStacksPickerProps ) {
	const [ stacks, setStacks ] = useState< CreatureStack[] >( [] );
	const [ checked, setChecked ] = useState< Set< string > >( new Set() );

	useEffect( () => {
		api.creatureStacks
			.list( {
				game_slug: gameSlug,
				per_page: 100,
				include_disabled: true,
			} )
			.then( ( loaded ) =>
				setStacks(
					loaded.filter(
						( stack ) => ! stack.stack_definition.storyteller_only
					)
				)
			);
	}, [ gameSlug ] );

	useEffect( () => {
		if ( enabled === null ) {
			setChecked(
				new Set(
					stacks
						.filter(
							( s ) =>
								s.stack_definition.default_enabled !== false
						)
						.map( ( s ) => s.slug )
				)
			);
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
					{ __(
						'At least one creature type must stay enabled.',
						'beyond-elysium'
					) }
				</p>
			) }
			<p className="be-enabled-stacks-picker__build-link">
				<a
					href={ `admin.php?page=beyond-elysium-system-config&tab=creature-stacks&game_slug=${ encodeURIComponent( gameSlug ) }` }
				>
					{ __(
						'Need a genuinely new creature type, not just one already here? Build one on Creature Stacks.',
						'beyond-elysium'
					) }
				</a>
			</p>
			<button
				type="button"
				className="button button-primary"
				disabled={ ! canSave || saving }
				onClick={ () => onSave( Array.from( checked ) ) }
			>
				{ saving
					? __( 'Saving…', 'beyond-elysium' )
					: __( 'Save', 'beyond-elysium' ) }
			</button>
		</div>
	);
}

export default EnabledStacksPicker;
