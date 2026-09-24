/**
 * The purchase-list switches a chronicle's HST has.
 */
export type PurchaseArea = 'abilities' | 'backgrounds' | 'merits_flaws';

export const PURCHASE_AREAS: readonly PurchaseArea[] = [
	'abilities',
	'backgrounds',
	'merits_flaws',
];

export type PurchaseScope = Partial< Record< PurchaseArea, boolean > >;

/**
 * A chronicle's stored switches as plain booleans: an area is on only when it is stored as true.
 */
export function readPurchaseScope(
	stored: unknown
): Record< PurchaseArea, boolean > {
	const source =
		stored && typeof stored === 'object'
			? ( stored as Record< string, unknown > )
			: {};
	return {
		abilities: source.abilities === true,
		backgrounds: source.backgrounds === true,
		merits_flaws: source.merits_flaws === true,
	};
}

/**
 * The body of a save for one switch.
 */
export function purchaseScopeChange(
	area: PurchaseArea,
	on: boolean
): {
	purchase_scope: PurchaseScope;
} {
	return { purchase_scope: { [ area ]: on } };
}
