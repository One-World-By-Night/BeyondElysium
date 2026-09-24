/**
 * The purchase-list switches a chronicle's HST has (1.3.4): each creature type buys from its own
 * Abilities, Backgrounds, Merits and Flaws, and an area switched on lets every creature type in the
 * chronicle buy from all of them. The switches are independent and all or nothing per area, and the
 * server owns what they mean; this only reads what is stored and builds a save.
 */
export type PurchaseArea = 'abilities' | 'backgrounds' | 'merits_flaws';

export const PURCHASE_AREAS: readonly PurchaseArea[] = [
	'abilities',
	'backgrounds',
	'merits_flaws',
];

export type PurchaseScope = Partial< Record< PurchaseArea, boolean > >;

/** A chronicle's stored switches as plain booleans: an area is on only when it is stored as true. */
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
 * The body of a save for one switch. It carries only that area: the server keeps every other switch
 * as it was, so two people saving different areas never undo each other.
 */
export function purchaseScopeChange(
	area: PurchaseArea,
	on: boolean
): {
	purchase_scope: PurchaseScope;
} {
	return { purchase_scope: { [ area ]: on } };
}
