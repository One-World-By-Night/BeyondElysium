/**
 * What the app shell's navigation shows: the places on a phone's bottom bar, what More holds, and the addresses its
 * links point at.
 */

import { __ } from '@wordpress/i18n';
import { isCharacterView } from './characterViews';
import {
	playerTabUrl,
	storytellerTabUrl,
	PLAYER_TABS,
	STORYTELLER_TABS,
} from './pluginPages';

/**
 * The chronicle roles that reach the Storyteller Toolkit.
 */
const STAFF_ROLES = [ 'hst', 'ast', 'narrator', 'boons' ];

/**
 * The page widgets that draw the app shell's frame, footer included.
 */
const APP_WIDGETS = [ 'my-chronicle', 'storyteller-toolkit-page' ];

export interface NavSection {
	key: string;
	label: string;
}

/**
 * Whether this page is on Beyond Elysium's own app shell.
 */
export function isAppLayout(): boolean {
	return (
		!! window.beyondElysium?.appLayout &&
		document.body.classList.contains( 'be-app' )
	);
}

/**
 * The bottom bar's places: each preferred place whose section the page offers, in the preferred order and with its
 * own short label, then the page's other sections in their own order, until there are `count`.
 */
export function barPlaces(
	sections: NavSection[],
	preferred: NavSection[],
	count: number
): NavSection[] {
	const offered = new Set( sections.map( ( section ) => section.key ) );
	const places = preferred
		.filter( ( place ) => offered.has( place.key ) )
		.slice( 0, count );
	for ( const section of sections ) {
		if ( places.length >= count ) {
			break;
		}
		if ( ! places.some( ( place ) => place.key === section.key ) ) {
			places.push( section );
		}
	}
	return places;
}

/**
 * The sections More lists: every section the bar does not hold, in the page's order.
 */
export function moreSections(
	sections: NavSection[],
	places: NavSection[]
): NavSection[] {
	return sections.filter(
		( section ) => ! places.some( ( place ) => place.key === section.key )
	);
}

/**
 * This page's address with another section open, keeping everything else in it.
 */
export function sectionHref( here: string, key: string ): string {
	const url = new URL( here );
	url.searchParams.set( 'tab', key );
	return url.toString();
}

/**
 * The address that opens another of the player's characters: on the same view when a character view is open (Play,
 * Sheet, Story, History or Edit), otherwise on its Sheet.
 */
export function characterHref(
	here: string,
	characterId: number,
	openTab: string
): string {
	const url = new URL( here );
	url.searchParams.set( 'character_id', String( characterId ) );
	url.searchParams.set(
		'tab',
		isCharacterView( openTab ) ? openTab : 'sheet'
	);
	return url.toString();
}

/**
 * A sign-in or sign-out address that comes back to `here`.
 */
export function returningTo( base: string, here: string ): string {
	const url = new URL( base, here );
	url.searchParams.set( 'redirect_to', here );
	return url.toString();
}

/**
 * Another page's address with this chronicle chosen.
 */
export function withChronicle( href: string, gameSlug: string ): string {
	if ( ! gameSlug ) {
		return href;
	}
	const url = new URL( href, window.location.href );
	url.searchParams.set( 'game_slug', gameSlug );
	return url.toString();
}

/**
 * Whether the page script adds the Powered by footer under a widget: never under an admin widget, which has its own,
 * nor under a page widget whose frame draws it on the app shell.
 */
export function footerUnderWidget(
	widgetName: string,
	appLayout: boolean
): boolean {
	if ( widgetName.startsWith( 'admin-' ) ) {
		return false;
	}
	return ! ( appLayout && APP_WIDGETS.includes( widgetName ) );
}

/**
 * Whether a chronicle role reaches the Storyteller Toolkit.
 */
export function isStaffRole( role: string | undefined ): boolean {
	return STAFF_ROLES.includes( role ?? '' );
}

/**
 * The header's link across to the other page, with this chronicle chosen: My Chronicle from the Toolkit always, and
 * the Toolkit from My Chronicle for a staff role only.
 */
export function otherPage(
	page: 'player' | 'storyteller',
	role: string | undefined,
	gameSlug: string
): { href: string; label: string } | null {
	if ( 'storyteller' === page ) {
		return {
			href: withChronicle(
				playerTabUrl( PLAYER_TABS.dashboard ),
				gameSlug
			),
			label: __( 'My Chronicle', 'beyond-elysium' ),
		};
	}
	if ( ! isStaffRole( role ) ) {
		return null;
	}
	return {
		href: withChronicle(
			storytellerTabUrl( STORYTELLER_TABS.dashboard ),
			gameSlug
		),
		label: __( 'Storyteller Toolkit', 'beyond-elysium' ),
	};
}
