/**
 * A Storyteller's own view of the plots one NPC is connected to, split into Open and Resolved, each with its
 * own latest entry's date.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import CollapsiblePanel from '../shared/CollapsiblePanel';
import { storytellerTabUrl, STORYTELLER_TABS } from '../../lib/pluginPages';
import type { CharacterHook } from '../../api/client';
import './NpcHooksPanel.css';

export interface NpcHooksPanelProps {
	gameSlug: string;
	characterId: number;
}

function hookLink( gameSlug: string, hook: CharacterHook ) {
	return `${ storytellerTabUrl(
		STORYTELLER_TABS.plots
	) }&open_plot=${ hook.plot_id }&game_slug=${ gameSlug }`;
}

function HookList( {
	gameSlug,
	hooks,
}: {
	gameSlug: string;
	hooks: CharacterHook[];
} ) {
	if ( hooks.length === 0 ) {
		return (
			<p className="description">{ __( 'None.', 'beyond-elysium' ) }</p>
		);
	}
	return (
		<ul className="be-npc-hooks-panel__list">
			{ hooks.map( ( hook ) => (
				<li key={ hook.plot_id }>
					<a href={ hookLink( gameSlug, hook ) }>{ hook.title }</a>
					{ hook.latest_entry_date && (
						<span className="be-npc-hooks-panel__date">
							{ hook.latest_entry_date }
						</span>
					) }
				</li>
			) ) }
		</ul>
	);
}

export function NpcHooksPanel( { gameSlug, characterId }: NpcHooksPanelProps ) {
	const [ open, setOpen ] = useState< CharacterHook[] >( [] );
	const [ resolved, setResolved ] = useState< CharacterHook[] >( [] );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		api.characters( gameSlug )
			.hooks( characterId )
			.then( ( data ) => {
				setOpen( data.open );
				setResolved( data.resolved );
			} )
			.catch( () =>
				setError( __( 'Failed to load hooks.', 'beyond-elysium' ) )
			);
	}, [ gameSlug, characterId ] );

	return (
		<CollapsiblePanel
			id={ `npc-hooks-panel:${ characterId }` }
			className="be-npc-hooks-panel"
			heading={ <h4>{ __( 'Hooks', 'beyond-elysium' ) }</h4> }
		>
			{ error && <p role="alert">{ error }</p> }
			<h5>{ __( 'Open', 'beyond-elysium' ) }</h5>
			<HookList gameSlug={ gameSlug } hooks={ open } />
			<h5>{ __( 'Resolved', 'beyond-elysium' ) }</h5>
			<HookList gameSlug={ gameSlug } hooks={ resolved } />
		</CollapsiblePanel>
	);
}

export default NpcHooksPanel;
