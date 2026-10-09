/**
 * The pure parts of the Email Log screen: what a row's result says in words, where its subject matter lives, and which
 * filters a link into the screen carries.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	characterSheetUrl,
	storytellerTabUrl,
	STORYTELLER_TABS,
} from './pluginPages';
import type { MailLogEntry } from '../types/mailLog';

/**
 * A row's result in a sentence: "Sent", "Not sent: They turned off email from Beyond Elysium", "Failed: <what the mail
 * system said>", "In the daily digest".
 */
export function describeResult( entry: MailLogEntry ): string {
	if ( entry.result === 'skipped' && entry.reason_label ) {
		return sprintf(
			/* translators: 1: "Not sent", 2: why, such as "They turned off email from Beyond Elysium" */
			__( '%1$s: %2$s', 'beyond-elysium' ),
			entry.result_label,
			entry.reason_label
		);
	}
	if ( entry.result === 'failed' && entry.error ) {
		return sprintf(
			/* translators: 1: "Failed", 2: what the mail system said */
			__( '%1$s: %2$s', 'beyond-elysium' ),
			entry.result_label,
			entry.error
		);
	}
	return entry.result_label;
}

/**
 * Where the thing an email was about can be opened, or null when it has no page of its own.
 */
export function entityHref(
	entry: Pick< MailLogEntry, 'entity_type' | 'entity_id' >,
	gameSlug: string
): string | null {
	if ( entry.entity_id <= 0 ) {
		return null;
	}
	const slug = `&game_slug=${ encodeURIComponent( gameSlug ) }`;
	if ( entry.entity_type === 'plot' ) {
		return `${ storytellerTabUrl(
			STORYTELLER_TABS.plots
		) }${ slug }&open_plot=${ entry.entity_id }`;
	}
	if ( entry.entity_type === 'character' ) {
		return characterSheetUrl( entry.entity_id, gameSlug );
	}
	return null;
}

/**
 * The Email Log screen, showing only the emails about one thing: the link from a plot to who was emailed about it.
 */
export function emailLogHref(
	entityType: string,
	entityId: number,
	gameSlug: string
): string {
	return `${ storytellerTabUrl(
		STORYTELLER_TABS.emailLog
	) }&game_slug=${ encodeURIComponent(
		gameSlug
	) }&entity_type=${ encodeURIComponent( entityType ) }&entity_id=${ entityId }`;
}

export interface EntityFilter {
	entity_type: string;
	entity_id: number;
}

/**
 * The thing a link into the screen asks about: `?entity_type=plot&entity_id=252`.
 */
export function entityFilterFromSearch( search: string ): EntityFilter | null {
	const params = new URLSearchParams( search );
	const type = params.get( 'entity_type' ) ?? '';
	const id = Number( params.get( 'entity_id' ) );
	if ( ! /^[a-z_]+$/.test( type ) || ! Number.isInteger( id ) || id <= 0 ) {
		return null;
	}
	return { entity_type: type, entity_id: id };
}

/**
 * How a row's time reads: the stored date and time to the minute.
 */
export function shortTime( createdAt: string ): string {
	return createdAt.slice( 0, 16 );
}
