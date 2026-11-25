/**
 * The words a reader sees for a character's status, a change's status, an approval level, a plot's status, a
 * creature type and a group's kind, in place of the stored values.
 */
import { __ } from '@wordpress/i18n';

/**
 * A character's status as a reader sees it; an unknown one reads as stored.
 */
export function characterStatusLabel( status: string ): string {
	switch ( status ) {
		case 'active':
			return __( 'Active', 'beyond-elysium' );
		case 'inactive':
			return __( 'Inactive', 'beyond-elysium' );
		case 'retired':
			return __( 'Retired', 'beyond-elysium' );
		case 'dead':
			return __( 'Dead', 'beyond-elysium' );
		case 'pending':
			return __( 'Pending', 'beyond-elysium' );
		default:
			return status;
	}
}

/**
 * A stored change's status as a reader sees it; an unknown one reads as stored.
 */
export function changeStatusLabel( status: string ): string {
	switch ( status ) {
		case 'pending':
			return __( 'Pending', 'beyond-elysium' );
		case 'approved':
			return __( 'Approved', 'beyond-elysium' );
		case 'rejected':
			return __( 'Refused', 'beyond-elysium' );
		case 'forwarded':
			return __( 'Forwarded', 'beyond-elysium' );
		default:
			return status;
	}
}

/**
 * What an approval level means to a reader; an unknown one reads as stored.
 */
export function approvalLevelLabel( level: string ): string {
	switch ( level ) {
		case 'auto':
			return __( 'Automatic', 'beyond-elysium' );
		case 'st':
			return __( 'Needs a Storyteller', 'beyond-elysium' );
		default:
			return level;
	}
}

/**
 * A plot's badge: an active plot shows where it stands, a resolved or archived one shows that; an unknown status
 * reads as stored.
 */
export function plotBadgeLabel(
	status: string,
	derivedStatus: string
): string {
	switch ( status ) {
		case 'active':
			switch ( derivedStatus ) {
				case 'pending':
					return __( 'Not started', 'beyond-elysium' );
				case 'finished':
					return __( 'Finished', 'beyond-elysium' );
				default:
					return __( 'Under way', 'beyond-elysium' );
			}
		case 'resolved':
			return __( 'Resolved', 'beyond-elysium' );
		case 'archived':
			return __( 'Archived', 'beyond-elysium' );
		default:
			return status;
	}
}

/**
 * A creature type's own name from the chronicle's list of types; a slug with no type listed reads as stored.
 */
export function creatureTypeName(
	slug: string,
	stacks: ReadonlyArray< { slug: string; name: string } >
): string {
	return stacks.find( ( stack ) => stack.slug === slug )?.name ?? slug;
}

/**
 * A group's kind as a reader sees it; a kind a chronicle typed itself reads as typed.
 */
export function factionTypeLabel( type: string ): string {
	switch ( type ) {
		case 'sect':
			return __( 'Sect', 'beyond-elysium' );
		case 'clan':
			return __( 'Clan', 'beyond-elysium' );
		case 'coterie':
			return __( 'Coterie', 'beyond-elysium' );
		case 'pack':
			return __( 'Pack', 'beyond-elysium' );
		case 'chantry':
			return __( 'Chantry', 'beyond-elysium' );
		case 'court':
			return __( 'Court', 'beyond-elysium' );
		case 'cabal':
			return __( 'Cabal', 'beyond-elysium' );
		case 'sept':
			return __( 'Sept', 'beyond-elysium' );
		case 'motley':
			return __( 'Motley', 'beyond-elysium' );
		case 'house':
			return __( 'House', 'beyond-elysium' );
		case 'other':
			return __( 'Other', 'beyond-elysium' );
		default:
			return type;
	}
}
