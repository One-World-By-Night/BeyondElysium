/**
 * The Approval Queue's pricing rules for a purchase that has no catalog price.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import type { CostUnits, PriceUnit } from '../types/character';

/**
 * The most a Storyteller may price one unit of a custom purchase.
 */
export const MAX_CUSTOM_PRICE = 500;

/**
 * Whether a change is waiting for a Storyteller to name its cost.
 */
export function isCostPending( change: { change_data?: unknown } ): boolean {
	const data = change.change_data as Record< string, unknown > | undefined;
	return data?.cost_pending === true;
}

/**
 * The price a reviewer typed: a whole number of XP from 0 to the ceiling, where 0 is a real answer, or null when the
 * box is blank or holds anything else.
 */
export function parsePrice( text: string | undefined ): number | null {
	const trimmed = ( text ?? '' ).trim();
	if ( ! /^\d+$/.test( trimmed ) ) {
		return null;
	}
	const price = Number( trimmed );
	return price <= MAX_CUSTOM_PRICE ? price : null;
}

/**
 * What the price box is called: a trait list is priced per dot, a power as one pick.
 */
export function priceUnitLabel( per: PriceUnit ): string {
	return per === 'dot'
		? __( 'XP per dot', 'beyond-elysium' )
		: __( 'XP', 'beyond-elysium' );
}

/**
 * The total the typed price comes to, or null until there is a usable price and a basis for it.
 */
export function priceTotal(
	text: string | undefined,
	basis: CostUnits | null | undefined
): number | null {
	const price = parsePrice( text );
	if ( price === null || ! basis ) {
		return null;
	}
	return price * basis.units;
}

/**
 * Whether Approve is allowed for a change.
 */
export function canApprove(
	change: { id: number; change_data?: unknown },
	prices: Record< number, string >
): boolean {
	return (
		! isCostPending( change ) || parsePrice( prices[ change.id ] ) !== null
	);
}

/**
 * The notice for changes a batch approval left alone.
 */
export function costNeededMessage( count: number ): string {
	return sprintf(
		/* translators: %d: number of changes that need a price before they can be approved */
		_n(
			'%d change needs a price before it can be approved. Open it and enter one.',
			'%d changes need a price before they can be approved. Open each one and enter one.',
			count,
			'beyond-elysium'
		),
		count
	);
}

/**
 * What the player's preview says.
 */
export function previewPriceLabel( preview: {
	priced?: boolean;
} ): string | null {
	return preview.priced === false
		? __( 'Price set by a Storyteller on approval', 'beyond-elysium' )
		: null;
}
