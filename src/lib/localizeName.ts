/**
 * Picks a catalog item's display name for the current viewer's locale. This
 * is a pure presentation helper - it must never be used to choose the value
 * stored, matched, or sent to the server (i18n-pt-br-design.md, Decision
 * 106): `Cost_Engine`, the Grapevine import matcher, and `sheet_data` all
 * key on a catalog item's canonical (English) `name`, so swapping it per
 * locale anywhere but the final render would corrupt that matching the
 * first time two chronicles on different locales exchanged a character.
 */
import type { PowerLevel, TraitListItem } from '../types';

const PT_BR = 'pt_BR';

/** True when the current install's site locale (window.beyondElysium.locale) is Portuguese (Brazil). */
export function isPortugueseLocale(): boolean {
	return window.beyondElysium?.locale === PT_BR;
}

/**
 * Display name for a trait_list catalog item: `name_pt` when the site is
 * `pt_BR` and a translation exists, the canonical `name` otherwise - an
 * untranslated item (no `name_pt` yet) falls back to English rather than
 * showing blank.
 */
export function localizedItemName(
	item: Pick< TraitListItem, 'name' | 'name_pt' >
): string {
	if ( isPortugueseLocale() && item.name_pt ) {
		return item.name_pt;
	}
	return item.name;
}

/** Same fallback rule as localizedItemName(), for a tiered_power level's power_name/power_name_pt pair. */
export function localizedPowerName(
	level: Pick< PowerLevel, 'power_name' | 'power_name_pt' >
): string {
	if ( isPortugueseLocale() && level.power_name_pt ) {
		return level.power_name_pt;
	}
	return level.power_name;
}
