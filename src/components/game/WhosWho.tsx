/**
 * "Who's Who" (1.1.0 §3.7 item 3): the NPC directory a plain player sees - one card per
 * NPC whose profile_audience reaches them, showing only its public projection (display
 * name, description, portrait) - never sheet_data, player, XP, notes, or status. A
 * Storyteller sees every NPC in the chronicle regardless of audience.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import HelpButton from '../shared/HelpButton';
import type { NpcProfile } from '../../types/character';
import './WhosWho.css';

export interface WhosWhoProps {
	gameSlug: string;
}

export function WhosWho( { gameSlug }: WhosWhoProps ) {
	const [ npcs, setNpcs ] = useState< NpcProfile[] | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		setNpcs( null );
		setError( null );
		api.npcs( gameSlug )
			.list()
			.then( setNpcs )
			.catch( () =>
				setError( __( "Failed to load Who's Who.", 'beyond-elysium' ) )
			);
	}, [ gameSlug ] );

	return (
		<div className="be-whos-who">
			<div className="be-help-heading">
				<h2>{ __( "Who's Who", 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="whos-who" />
			</div>

			{ error && (
				<div className="be-whos-who__error" role="alert">
					{ error }
				</div>
			) }

			{ ! error && npcs === null && (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) }

			{ ! error && npcs !== null && npcs.length === 0 && (
				<p className="be-whos-who__empty">
					{ __(
						'No NPCs have a public profile in this chronicle yet.',
						'beyond-elysium'
					) }
				</p>
			) }

			{ ! error && npcs !== null && npcs.length > 0 && (
				<div className="be-whos-who__grid">
					{ npcs.map( ( npc ) => (
						<div className="be-whos-who__card" key={ npc.id }>
							{ npc.image_url && (
								<img
									className="be-whos-who__portrait"
									src={ npc.image_url }
									alt=""
								/>
							) }
							<h3>{ npc.name }</h3>
							{ npc.public_description && (
								// eslint-disable-next-line react/no-danger
								<div
									className="be-whos-who__description"
									dangerouslySetInnerHTML={ {
										__html: npc.public_description,
									} }
								/>
							) }
							{ ( npc.titles.length > 0 ||
								npc.factions.length > 0 ) && (
								<dl className="be-whos-who__meta">
									{ npc.titles.length > 0 && (
										<div>
											<dt>
												{ __(
													'Titles',
													'beyond-elysium'
												) }
											</dt>
											<dd>{ npc.titles.join( ', ' ) }</dd>
										</div>
									) }
									{ npc.factions.length > 0 && (
										<div>
											<dt>
												{ __(
													'Factions',
													'beyond-elysium'
												) }
											</dt>
											<dd>
												{ npc.factions.join( ', ' ) }
											</dd>
										</div>
									) }
								</dl>
							) }
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}

export default WhosWho;
