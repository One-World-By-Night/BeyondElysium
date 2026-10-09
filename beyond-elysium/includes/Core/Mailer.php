<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Mail_Log;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the plugin's email through wp_mail() and records every message - and every one it decided not to send - in the
 * chronicle's mail log. A message that covers several chronicles is recorded once under each.
 *
 * Every `$meta` array takes: `game_id` (or `game_ids`), `kind`, and optionally `wp_user_id`, `name`, `email` (for
 * someone with no account), `entity_type`, `entity_id` and `subject`.
 */
class Mailer {

	/**
	 * Sends one email and records what the mail system said.
	 *
	 * @param string              $to
	 * @param string              $subject
	 * @param string              $body
	 * @param array<string,mixed> $meta
	 * @return bool Whether the mail system accepted it.
	 */
	public static function send( string $to, string $subject, string $body, array $meta ): bool {
		$error   = '';
		$capture = static function ( $failure ) use ( &$error ): void {
			if ( $failure instanceof \WP_Error ) {
				$error = $failure->get_error_message();
			}
		};

		add_action( 'wp_mail_failed', $capture );
		try {
			$sent = (bool) wp_mail( $to, $subject, $body );
		} finally {
			remove_action( 'wp_mail_failed', $capture );
		}

		self::record( $meta, [
			'recipient_email' => $to,
			'subject'         => $subject,
			'result'          => $sent ? Mail_Log::SENT : Mail_Log::FAILED,
			'reason'          => $sent ? '' : Mail_Log::REASON_HOST_REFUSED,
			'error'           => $sent ? '' : $error,
		] );

		return $sent;
	}

	/**
	 * Records an email that was not sent, and why.
	 *
	 * @param array<string,mixed> $meta
	 * @param string              $reason A Mail_Log REASON_ constant.
	 */
	public static function skipped( array $meta, string $reason ): void {
		self::record( $meta, [ 'result' => Mail_Log::SKIPPED, 'reason' => $reason ] );
	}

	/**
	 * Records an email held for the recipient's daily digest.
	 *
	 * @param array<string,mixed> $meta
	 */
	public static function queued( array $meta ): void {
		self::record( $meta, [ 'result' => Mail_Log::QUEUED, 'reason' => Mail_Log::REASON_DAILY_DIGEST ] );
	}

	/**
	 * Writes the row (one per chronicle the message covers), filling the recipient's name and address from their account
	 * when the caller didn't give them. A failure to write the log is reported to the PHP error log and nothing else.
	 *
	 * @param array<string,mixed> $meta
	 * @param array<string,mixed> $fields
	 */
	private static function record( array $meta, array $fields ): void {
		try {
			$wp_user_id = (int) ( $meta['wp_user_id'] ?? 0 );
			$user       = $wp_user_id > 0 ? get_userdata( $wp_user_id ) : false;

			$row = array_merge(
				[
					'wp_user_id'      => $wp_user_id,
					'recipient_name'  => (string) ( $meta['name'] ?? ( $user ? $user->display_name : '' ) ),
					'recipient_email' => (string) ( $meta['email'] ?? ( $user ? $user->user_email : '' ) ),
					'kind'            => (string) ( $meta['kind'] ?? '' ),
					'subject'         => (string) ( $meta['subject'] ?? '' ),
					'entity_type'     => (string) ( $meta['entity_type'] ?? '' ),
					'entity_id'       => (int) ( $meta['entity_id'] ?? 0 ),
				],
				$fields
			);

			$game_ids = array_values( array_unique( array_map( 'intval', (array) ( $meta['game_ids'] ?? [ $meta['game_id'] ?? 0 ] ) ) ) );
			foreach ( $game_ids as $game_id ) {
				Mail_Log::record( array_merge( $row, [ 'game_id' => $game_id ] ) );
			}
		} catch ( \Throwable $failure ) {
			error_log( 'Beyond Elysium: could not write the mail log: ' . $failure->getMessage() );
		}
	}
}
