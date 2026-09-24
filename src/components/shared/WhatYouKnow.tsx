/**
 * "What you know" - the read-only list of secrets on one entity that the current viewer's own characters actually
 * reach, via the same `GET /secrets` route the Storyteller's own `SecretsPanel` reads (already audience-filtered
 * server-side for a non-manager).
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { Secret, SecretEntityType } from '../../types/secret';

export interface WhatYouKnowProps {
	gameSlug: string;
	entityType: SecretEntityType;
	entityId: number;
}

export function WhatYouKnow( {
	gameSlug,
	entityType,
	entityId,
}: WhatYouKnowProps ) {
	const [ items, setItems ] = useState< Secret[] >( [] );

	useEffect( () => {
		api.secrets( gameSlug )
			.list( entityType, entityId )
			.then( setItems )
			.catch( () => setItems( [] ) );
	}, [ gameSlug, entityType, entityId ] );

	if ( items.length === 0 ) {
		return null;
	}

	return (
		<div className="be-what-you-know">
			<h4>{ __( 'What You Know', 'beyond-elysium' ) }</h4>
			{ items.map( ( secret ) => (
				<div className="be-what-you-know__item" key={ secret.id }>
					<strong>{ secret.title }</strong>
					{ secret.content && (
						<div
							// eslint-disable-next-line react/no-danger
							dangerouslySetInnerHTML={ {
								__html: secret.content,
							} }
						/>
					) }
				</div>
			) ) }
		</div>
	);
}

export default WhatYouKnow;
