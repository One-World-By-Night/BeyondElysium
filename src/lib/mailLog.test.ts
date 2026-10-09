/**
 * `describeResult()` puts a log row's outcome in words. `entityHref()` links a row to what it was about.
 * `entityFilterFromSearch()` reads the filter a link into the screen carries.
 */
import {
	describeResult,
	emailLogHref,
	entityFilterFromSearch,
	entityHref,
	shortTime,
} from './mailLog';
import type { MailLogEntry } from '../types/mailLog';

function entry( overrides: Partial< MailLogEntry > = {} ): MailLogEntry {
	return {
		id: 1,
		created_at: '2026-10-07 23:03:40',
		wp_user_id: 10,
		recipient_name: 'Adam Sartori',
		recipient_email: 'adam@example.test',
		kind: 'plot_post',
		kind_label: 'Plot post',
		subject: '[Beyond Elysium] New post on Raine [301] Plot',
		result: 'sent',
		result_label: 'Sent',
		reason: '',
		reason_label: '',
		error: '',
		entity_type: 'plot',
		entity_id: 252,
		entity_label: 'Raine [301] Plot',
		...overrides,
	};
}

describe( 'describeResult', () => {
	it( 'says only "Sent" for a message the mail system took', () => {
		expect( describeResult( entry() ) ).toBe( 'Sent' );
	} );

	it( 'gives the reason for a message that was not sent', () => {
		expect(
			describeResult(
				entry( {
					result: 'skipped',
					result_label: 'Not sent',
					reason: 'opted_out',
					reason_label: 'They turned off email from Beyond Elysium',
				} )
			)
		).toBe( 'Not sent: They turned off email from Beyond Elysium' );
	} );

	it( 'gives what the mail system said for a failure', () => {
		expect(
			describeResult(
				entry( {
					result: 'failed',
					result_label: 'Failed',
					error: 'Could not connect to the mail host',
				} )
			)
		).toBe( 'Failed: Could not connect to the mail host' );
	} );

	it( 'says a failure with no message plainly', () => {
		expect(
			describeResult(
				entry( { result: 'failed', result_label: 'Failed' } )
			)
		).toBe( 'Failed' );
	} );

	it( 'names a message held for the daily digest', () => {
		expect(
			describeResult(
				entry( {
					result: 'queued',
					result_label: 'In the daily digest',
					reason: 'daily_digest',
					reason_label: 'Held for their daily digest',
				} )
			)
		).toBe( 'In the daily digest' );
	} );
} );

describe( 'entityHref', () => {
	it( 'opens a plot in the Storyteller Toolkit', () => {
		expect( entityHref( entry(), 'kony' ) ).toContain(
			'tab=plots&game_slug=kony&open_plot=252'
		);
	} );

	it( "opens a character's sheet", () => {
		expect(
			entityHref(
				entry( { entity_type: 'character', entity_id: 301 } ),
				'kony'
			)
		).toContain( 'tab=sheet&character_id=301&game_slug=kony' );
	} );

	it( 'has no link for a thing without a page of its own', () => {
		expect(
			entityHref(
				entry( { entity_type: 'release_batch', entity_id: 4 } ),
				'kony'
			)
		).toBeNull();
	} );

	it( 'has no link when the row was not about any one thing', () => {
		expect(
			entityHref( entry( { entity_type: '', entity_id: 0 } ), 'kony' )
		).toBeNull();
	} );
} );

describe( 'emailLogHref', () => {
	it( 'opens the Email Log tab on one plot', () => {
		expect( emailLogHref( 'plot', 252, 'kony' ) ).toContain(
			'tab=email-log&game_slug=kony&entity_type=plot&entity_id=252'
		);
	} );

	it( 'round-trips through the filter the screen reads', () => {
		const url = new URL(
			emailLogHref( 'plot', 252, 'kony' ),
			'https://example.test'
		);

		expect( entityFilterFromSearch( url.search ) ).toEqual( {
			entity_type: 'plot',
			entity_id: 252,
		} );
	} );
} );

describe( 'entityFilterFromSearch', () => {
	it( 'reads the thing a link asks about', () => {
		expect(
			entityFilterFromSearch(
				'?tab=email-log&entity_type=plot&entity_id=252'
			)
		).toEqual( { entity_type: 'plot', entity_id: 252 } );
	} );

	it( 'ignores a link that names no thing', () => {
		expect( entityFilterFromSearch( '?tab=email-log' ) ).toBeNull();
	} );

	it( 'ignores an id that is not a positive whole number', () => {
		expect(
			entityFilterFromSearch( '?entity_type=plot&entity_id=0' )
		).toBeNull();
		expect(
			entityFilterFromSearch( '?entity_type=plot&entity_id=abc' )
		).toBeNull();
	} );

	it( 'ignores a type with anything but lowercase letters and underscores', () => {
		expect(
			entityFilterFromSearch( '?entity_type=plot%27--&entity_id=5' )
		).toBeNull();
	} );
} );

describe( 'shortTime', () => {
	it( 'cuts a stored time to the minute', () => {
		expect( shortTime( '2026-10-07 23:03:40' ) ).toBe( '2026-10-07 23:03' );
	} );
} );
