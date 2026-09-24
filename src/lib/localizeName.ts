/**
 * Picks a catalog item's display name for the current viewer's locale.
 */
import type {
	IdentityField,
	PowerLevel,
	ResourcePool,
	TraitListItem,
} from '../types';

const PT_BR = 'pt_BR';

/**
 * True when the current install's site locale (window.beyondElysium.locale) is Portuguese (Brazil).
 */
export function isPortugueseLocale(): boolean {
	return window.beyondElysium?.locale === PT_BR;
}

/**
 * Display name for a trait_list catalog item: `name_pt` when the site is `pt_BR` and a translation exists, the
 * canonical `name`.
 */
export function localizedItemName(
	item: Pick< TraitListItem, 'name' | 'name_pt' >
): string {
	if ( isPortugueseLocale() && item.name_pt ) {
		return item.name_pt;
	}
	return item.name;
}

/**
 * Same fallback rule as localizedItemName(), for a tiered_power level's power_name/power_name_pt pair.
 */
export function localizedPowerName(
	level: Pick< PowerLevel, 'power_name' | 'power_name_pt' >
): string {
	if ( isPortugueseLocale() && level.power_name_pt ) {
		return level.power_name_pt;
	}
	return level.power_name;
}

/**
 * Same fallback rule as localizedItemName(), for an identity_field's own name/label_pt pair.
 */
export function localizedFieldLabel(
	field: Pick< IdentityField, 'name' | 'label_pt' >
): string {
	if ( isPortugueseLocale() && field.label_pt ) {
		return field.label_pt;
	}
	return field.name;
}

/**
 * Same fallback rule as localizedItemName(), for a resource_pool's own name/label_pt pair.
 */
export function localizedPoolLabel(
	pool: Pick< ResourcePool, 'name' | 'label_pt' >
): string {
	if ( isPortugueseLocale() && pool.label_pt ) {
		return pool.label_pt;
	}
	return pool.name;
}

/**
 * An identity_field's held value, translated through its own `options_pt` map.
 */
export function localizedIdentityValue(
	field: Pick< IdentityField, 'options_pt' >,
	value: string | number | string[] | null | undefined
): string | number | string[] | null | undefined {
	if ( ! isPortugueseLocale() || ! field.options_pt ) {
		return value;
	}
	const optionsPt = field.options_pt;
	if ( Array.isArray( value ) ) {
		return value.map( ( v ) => optionsPt[ v ] ?? v );
	}
	if ( typeof value === 'string' ) {
		return optionsPt[ value ] ?? value;
	}
	return value;
}
