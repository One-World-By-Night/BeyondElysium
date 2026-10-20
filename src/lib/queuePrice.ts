/**
 * The Approval Queue's pricing rules for a purchase, and what a batch approval says about the changes it left alone.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import type { BatchApproveResponse, PriceScope } from '../types/character';

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
 * What the price box is called: a trait list is priced per dot, a power as one pick, and a price the book already
 * has is set for the whole change.
 */
export function priceUnitLabel( per: PriceScope ): string {
	if ( per === 'dot' ) {
		return __( 'XP per dot', 'beyond-elysium' );
	}
	if ( per === 'change' ) {
		return __( 'XP for this change', 'beyond-elysium' );
	}
	return __( 'XP', 'beyond-elysium' );
}

/**
 * The total the typed price comes to, or null until there is a usable price and a basis for it.
 */
export function priceTotal(
	text: string | undefined,
	basis: { units: number; per?: PriceScope } | null | undefined
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
 * How many changes in a set are still waiting for a price.
 */
export function membersNeedingPrice(
	group: ReadonlyArray< { change_data?: unknown } >
): number {
	return group.filter( ( change ) => isCostPending( change ) ).length;
}

/**
 * What a batch approval says about the changes it left alone, one sentence for each kind.
 */
export function batchNotices( result: BatchApproveResponse ): string[] {
	const notices: string[] = [];
	if ( result.skipped.length > 0 ) {
		notices.push(
			sprintf(
				// translators: %d: number of changes not approved.
				__(
					'%d change(s) were not approved - they were already reviewed or edited after the queue loaded. The queue has been reloaded.',
					'beyond-elysium'
				),
				result.skipped.length
			)
		);
	}
	// Waiting for a price is a different thing from skipped: the reviewer has to open each one.
	if ( ( result.needs_cost ?? [] ).length > 0 ) {
		notices.push( costNeededMessage( ( result.needs_cost ?? [] ).length ) );
	}
	if ( ( result.needs_secret_choice ?? [] ).length > 0 ) {
		const count = ( result.needs_secret_choice ?? [] ).length;
		notices.push(
			sprintf(
				/* translators: %d: number of changes that need a secret chosen before they can be approved */
				_n(
					'%d change needs you to choose which secret it belongs to. Open it and choose one.',
					'%d changes need you to choose which secret each belongs to. Open each one and choose.',
					count,
					'beyond-elysium'
				),
				count
			)
		);
	}
	if ( ( result.held ?? [] ).length > 0 ) {
		const count = ( result.held ?? [] ).length;
		notices.push(
			sprintf(
				/* translators: %d: number of changes held back because another change in their set could not be approved */
				_n(
					'%d change was held back with the rest of its set.',
					'%d changes were held back with the rest of their set.',
					count,
					'beyond-elysium'
				),
				count
			)
		);
	}
	return notices;
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
 * What the player's preview says in place of a cost - the change is refused outright, or honestly unpriced and
 * waiting for a Storyteller. Null means show the real cost.
 */
export function previewPriceLabel( preview: {
	priced?: boolean;
	invalid?: boolean;
	error?: { code: string; message: string };
} ): string | null {
	if ( preview.invalid ) {
		return (
			preview.error?.message ??
			__(
				'This change cannot be submitted as written.',
				'beyond-elysium'
			)
		);
	}
	return preview.priced === false
		? __( 'Price set by a Storyteller on approval', 'beyond-elysium' )
		: null;
}
