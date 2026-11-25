/**
 * What the Staff permissions panel says about each area a member can be given, and how a grant or removal reads in the
 * log.
 */
import { __, sprintf } from '@wordpress/i18n';
import { formatStoredTime } from './storedTime';
import type { StaffGrantArea, StaffGrantLogEntry } from '../types/staffGrants';

/**
 * Where an area's screen lives: in the Storyteller Toolkit, or in wp-admin.
 */
export type StaffAreaPlace = 'toolkit' | 'wp-admin';

export interface StaffAreaInfo {
	area: StaffGrantArea;
	place: StaffAreaPlace;
}

/**
 * Every area a member can be given, in the order the panel lists them.
 */
export const STAFF_AREAS: StaffAreaInfo[] = [
	{ area: 'be_manage_schemas', place: 'toolkit' },
	{ area: 'be_manage_approval_rules', place: 'wp-admin' },
	{ area: 'be_manage_templates', place: 'wp-admin' },
	{ area: 'be_manage_chronicle_setup', place: 'wp-admin' },
	{ area: 'be_delete_characters', place: 'toolkit' },
];

/**
 * An area's name on screen.
 */
export function areaLabel( area: StaffGrantArea ): string {
	switch ( area ) {
		case 'be_manage_schemas':
			return __( 'Catalog', 'beyond-elysium' );
		case 'be_manage_approval_rules':
			return __( 'Approval Rules', 'beyond-elysium' );
		case 'be_manage_templates':
			return __( 'Templates', 'beyond-elysium' );
		case 'be_manage_chronicle_setup':
			return __( 'Chronicle Setup', 'beyond-elysium' );
		case 'be_delete_characters':
			return __( 'Delete characters', 'beyond-elysium' );
	}
}

/**
 * The line under an area's box saying where it opens.
 */
export function areaNote( place: StaffAreaPlace ): string {
	return 'wp-admin' === place
		? __(
				'Opens in wp-admin. Needs an Editor account on this site.',
				'beyond-elysium'
			)
		: __( 'In the Storyteller Toolkit', 'beyond-elysium' );
}

/**
 * A chronicle role's name on screen.
 */
export function roleLabel( role: string | null ): string {
	switch ( role ) {
		case 'hst':
			return __( 'HST', 'beyond-elysium' );
		case 'ast':
			return __( 'AST', 'beyond-elysium' );
		case 'narrator':
			return __( 'Narrator', 'beyond-elysium' );
		case 'boons':
			return __( 'Harpy', 'beyond-elysium' );
		case 'player':
			return __( 'Player', 'beyond-elysium' );
		default:
			return __( 'No role here', 'beyond-elysium' );
	}
}

/**
 * Whether a box can be ticked for someone: deleting characters is an AST's, since a Narrator has no Characters list. A
 * box already ticked can always be cleared.
 */
export function areaTickable(
	area: StaffGrantArea,
	role: string | null,
	held: StaffGrantArea[]
): boolean {
	if ( held.includes( area ) ) {
		return true;
	}
	return 'be_delete_characters' !== area || 'narrator' !== role;
}

/**
 * The areas with one added, or taken away when it is already there, in the panel's own order.
 */
export function toggledAreas(
	areas: StaffGrantArea[],
	area: StaffGrantArea
): StaffGrantArea[] {
	const next = areas.includes( area )
		? areas.filter( ( held ) => held !== area )
		: [ ...areas, area ];
	return STAFF_AREAS.map( ( info ) => info.area ).filter( ( known ) =>
		next.includes( known )
	);
}

/**
 * Whether two sets of areas hold the same ones.
 */
export function sameAreas( a: StaffGrantArea[], b: StaffGrantArea[] ): boolean {
	return a.length === b.length && a.every( ( area ) => b.includes( area ) );
}

/**
 * One log entry as a sentence, with its date in the site's language.
 */
export function logLine( entry: StaffGrantLogEntry ): string {
	const former = __( 'A former member', 'beyond-elysium' );
	const args = [
		entry.by_name ?? former,
		areaLabel( entry.area ),
		entry.user_name ?? former,
		formatStoredTime( entry.at ),
	] as const;
	return 'granted' === entry.action
		? sprintf(
				/* translators: 1: who gave it, 2: the area, 3: who received it, 4: the date */
				__( '%1$s gave %2$s to %3$s, %4$s.', 'beyond-elysium' ),
				...args
			)
		: sprintf(
				/* translators: 1: who took it away, 2: the area, 3: who lost it, 4: the date */
				__( '%1$s took away %2$s from %3$s, %4$s.', 'beyond-elysium' ),
				...args
			);
}
