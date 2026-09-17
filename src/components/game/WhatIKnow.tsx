/**
 * "What I Know" (1.1.0 §3.11) - My Chronicle's own tab listing every secret revealed to one
 * of the current player's characters, across every plot, item, location, or NPC it's
 * attached to. `entity_name` is null when the viewer can't independently see that entity -
 * the secret itself is still shown, just without naming what it's about.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import HelpButton from '../shared/HelpButton';
import type { MySecretRow } from '../../types/secret';
import './WhatIKnow.css';

export interface WhatIKnowProps {
	gameSlug: string;
}

const ENTITY_LABELS: Record< string, string > = {
	plot: __( 'Plot', 'beyond-elysium' ),
	item: __( 'Item', 'beyond-elysium' ),
	location: __( 'Location', 'beyond-elysium' ),
	npc: __( 'NPC', 'beyond-elysium' ),
};

export function WhatIKnow( { gameSlug }: WhatIKnowProps ) {
	const [ items, setItems ] = useState< MySecretRow[] | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		setItems( null );
		setError( null );
		api.secrets( gameSlug )
			.mine()
			.then( setItems )
			.catch( () =>
				setError(
					__( 'Failed to load what you know.', 'beyond-elysium' )
				)
			);
	}, [ gameSlug ] );

	return (
		<div className="be-what-i-know">
			<div className="be-help-heading">
				<h2>{ __( 'What I Know', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="what-i-know" />
			</div>

			{ error && (
				<div className="be-what-i-know__error" role="alert">
					{ error }
				</div>
			) }

			{ ! error && items === null && (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) }

			{ ! error && items !== null && items.length === 0 && (
				<p>
					{ __(
						"You don't know any secrets yet.",
						'beyond-elysium'
					) }
				</p>
			) }

			{ ! error && items !== null && items.length > 0 && (
				<div className="be-what-i-know__list">
					{ items.map( ( row ) => (
						<div className="be-what-i-know__item" key={ row.id }>
							<div className="be-what-i-know__item-header">
								<strong>{ row.title }</strong>
								<span className="be-st-badge">
									{ ENTITY_LABELS[ row.entity_type ] ??
										row.entity_type }
									{ row.entity_name
										? `: ${ row.entity_name }`
										: '' }
								</span>
							</div>
							{ row.content && (
								<div
									// eslint-disable-next-line react/no-danger
									dangerouslySetInnerHTML={ {
										__html: row.content,
									} }
								/>
							) }
							<p className="be-what-i-know__learned">
								{ sprintf(
									/* translators: %s: when the secret was learned */
									__( 'Learned %s', 'beyond-elysium' ),
									row.learned_at
								) }
							</p>
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}

export default WhatIKnow;
