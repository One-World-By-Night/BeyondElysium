/**
 * The book changes under an entry the chronicle changed, each with the choice to keep the chronicle's value or take the
 * book's.
 */
import { __, sprintf } from '@wordpress/i18n';
import { flagParts } from '../../lib/catalogEntries';
import type { CatalogEntryFlag } from '../../types/catalog';

export interface CatalogFlagsProps {
	flags: CatalogEntryFlag[];
	busy: boolean;
	onKeep: ( flag: CatalogEntryFlag ) => void;
	onTake: ( flag: CatalogEntryFlag ) => void;
}

/**
 * Lists an entry's flagged changes. Renders nothing when there are none.
 */
export function CatalogFlags( {
	flags,
	busy,
	onKeep,
	onTake,
}: CatalogFlagsProps ) {
	if ( flags.length === 0 ) {
		return null;
	}
	return (
		<div className="be-catalog__flags">
			<p className="be-catalog__flags-title">
				{ __( 'The book changed this', 'beyond-elysium' ) }
			</p>
			<ul>
				{ flags.map( ( flag, index ) => {
					const parts = flagParts( flag );
					return (
						<li key={ index }>
							<span>
								{ parts.removed
									? __(
											'The book no longer has this entry.',
											'beyond-elysium'
										)
									: sprintf(
											/* translators: 1: what changed, such as Price, 2: the chronicle's value, 3: the book's value now, 4: the book's value when the chronicle made its change */
											__(
												'%1$s: you set %2$s; the book now says %3$s (it said %4$s when you made your change).',
												'beyond-elysium'
											),
											parts.field,
											parts.yours,
											parts.now,
											parts.was
										) }
							</span>
							<span className="be-catalog__flag-actions">
								<button
									type="button"
									className="be-catalog__button"
									disabled={ busy }
									onClick={ () => onKeep( flag ) }
								>
									{ __( 'Keep mine', 'beyond-elysium' ) }
								</button>
								{ ! parts.removed && (
									<button
										type="button"
										className="be-catalog__button"
										disabled={ busy }
										onClick={ () => onTake( flag ) }
									>
										{ __(
											"Use the book's",
											'beyond-elysium'
										) }
									</button>
								) }
							</span>
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}
