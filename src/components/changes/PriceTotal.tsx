/**
 * What a typed price comes to: "× 3 dots = 6 XP", or for a flaw that it is recorded and deducts nothing.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { priceTotal } from '../../lib/queuePrice';
import type { PriceBasis } from '../../types/character';

export interface PriceTotalProps {
	value: string;
	basis: PriceBasis | null | undefined;
	className?: string;
}

/**
 * The total beside a price box, or nothing until the box holds a usable price.
 */
export function PriceTotal( {
	value,
	basis,
	className = 'be-approval-queue__price-total',
}: PriceTotalProps ) {
	const total = priceTotal( value, basis );
	if ( total === null || ! basis ) {
		return null;
	}
	return (
		<span className={ className }>
			{ basis.per === 'dot' &&
				sprintf(
					/* translators: %d: how many dots the price is multiplied by */
					_n(
						'× %d dot',
						'× %d dots',
						basis.units,
						'beyond-elysium'
					),
					basis.units
				) }{ ' ' }
			{ basis.negative
				? sprintf(
						/* translators: %d: the total price in XP, recorded on a flaw's row */
						__(
							'= %d XP, recorded - a flaw deducts nothing',
							'beyond-elysium'
						),
						total
					)
				: sprintf(
						/* translators: %d: the total price in XP */
						__( '= %d XP', 'beyond-elysium' ),
						total
					) }
		</span>
	);
}

export default PriceTotal;
