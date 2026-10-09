<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * The record of a chronicle's email: each message the plugin sent or failed to send, each one it put in a daily digest,
 * and each one it chose not to send, with the reason. A row names who it was for and what it was about, never what it
 * said.
 */
class Mail_Log {

	public const SENT    = 'sent';
	public const FAILED  = 'failed';
	public const SKIPPED = 'skipped';
	public const QUEUED  = 'queued';

	public const RESULTS = [ self::SENT, self::FAILED, self::SKIPPED, self::QUEUED ];

	/**
	 * How long a row is kept.
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Why a message was not sent, or was held for a digest, or failed.
	 */
	public const REASON_OPTED_OUT       = 'opted_out';
	public const REASON_CHRONICLE_OFF   = 'chronicle_off';
	public const REASON_DEMO            = 'demo';
	public const REASON_PREFERENCE_OFF  = 'preference_off';
	public const REASON_NO_EMAIL        = 'no_email';
	public const REASON_DAILY_DIGEST    = 'daily_digest';
	public const REASON_HOST_REFUSED    = 'host_refused';

	/**
	 * The kinds of email, by the key a row stores.
	 *
	 * @return array<string,string> Key => label.
	 */
	public static function kinds(): array {
		return [
			'plot_post'           => __( 'Plot post', 'beyond-elysium' ),
			'visible'             => __( 'New thing to see', 'beyond-elysium' ),
			'release'             => __( 'Release batch', 'beyond-elysium' ),
			'digest'              => __( 'Daily digest', 'beyond-elysium' ),
			'change_outcome'      => __( 'Change reviewed', 'beyond-elysium' ),
			'transfer_offered'    => __( 'Transfer to review', 'beyond-elysium' ),
			'join_requested'      => __( 'Request to join', 'beyond-elysium' ),
			'join_answered'       => __( 'Join request answered', 'beyond-elysium' ),
			'secret_told'         => __( 'Secret told', 'beyond-elysium' ),
			'submission_received' => __( 'Sheet to review', 'beyond-elysium' ),
			'submission_answered' => __( 'Sheet answered', 'beyond-elysium' ),
			'invite'              => __( 'Player invitation', 'beyond-elysium' ),
		];
	}

	/**
	 * @return array<string,string> Result => label.
	 */
	public static function results(): array {
		return [
			self::SENT    => __( 'Sent', 'beyond-elysium' ),
			self::FAILED  => __( 'Failed', 'beyond-elysium' ),
			self::SKIPPED => __( 'Not sent', 'beyond-elysium' ),
			self::QUEUED  => __( 'In the daily digest', 'beyond-elysium' ),
		];
	}

	/**
	 * @param string $reason A REASON_ constant.
	 * @return string The reason in words, or an empty string for an unknown reason.
	 */
	public static function reason_label( string $reason ): string {
		$labels = [
			self::REASON_OPTED_OUT      => __( 'They turned off email from Beyond Elysium', 'beyond-elysium' ),
			self::REASON_CHRONICLE_OFF  => __( 'Email is switched off for this chronicle', 'beyond-elysium' ),
			self::REASON_DEMO           => __( 'A demo chronicle never sends email', 'beyond-elysium' ),
			self::REASON_PREFERENCE_OFF => __( 'They chose not to get plot emails', 'beyond-elysium' ),
			self::REASON_NO_EMAIL       => __( 'No email address on file', 'beyond-elysium' ),
			self::REASON_DAILY_DIGEST   => __( 'Held for their daily digest', 'beyond-elysium' ),
			self::REASON_HOST_REFUSED   => __( 'The mail system refused it', 'beyond-elysium' ),
		];
		return $labels[ $reason ] ?? '';
	}

	/**
	 * Writes one row. A kind or result this log doesn't know writes nothing.
	 *
	 * @param array{game_id?:int,wp_user_id?:int,recipient_name?:string,recipient_email?:string,kind?:string,subject?:string,result?:string,reason?:string,error?:string,entity_type?:string,entity_id?:int,created_at?:string} $row
	 * @return int|false The new row's id.
	 */
	public static function record( array $row ) {
		global $wpdb;

		$kind   = (string) ( $row['kind'] ?? '' );
		$result = (string) ( $row['result'] ?? '' );
		if ( ! array_key_exists( $kind, self::kinds() ) || ! in_array( $result, self::RESULTS, true ) ) {
			return false;
		}

		$previous = $wpdb->suppress_errors( true );
		$id       = Manager::insert( 'mail_log', [
			'game_id'         => (int) ( $row['game_id'] ?? 0 ),
			'wp_user_id'      => (int) ( $row['wp_user_id'] ?? 0 ),
			'recipient_name'  => self::clip( (string) ( $row['recipient_name'] ?? '' ), 255 ),
			'recipient_email' => self::clip( (string) ( $row['recipient_email'] ?? '' ), 255 ),
			'kind'            => $kind,
			'subject'         => self::clip( (string) ( $row['subject'] ?? '' ), 500 ),
			'result'          => $result,
			'reason'          => self::clip( (string) ( $row['reason'] ?? '' ), 40 ),
			'error'           => self::clip( (string) ( $row['error'] ?? '' ), 500 ),
			'entity_type'     => self::clip( (string) ( $row['entity_type'] ?? '' ), 20 ),
			'entity_id'       => (int) ( $row['entity_id'] ?? 0 ),
			'created_at'      => (string) ( $row['created_at'] ?? current_time( 'mysql' ) ),
		] );
		$wpdb->suppress_errors( $previous );

		return $id;
	}

	/**
	 * One chronicle's rows, newest first.
	 *
	 * @param int                  $game_id
	 * @param array<string,mixed>  $filters search, kind, result, since (seconds), entity_type, entity_id, wp_user_id.
	 * @param int                  $limit
	 * @param int                  $offset
	 * @return array<int,object>
	 */
	public static function for_game( int $game_id, array $filters = [], int $limit = 20, int $offset = 0 ): array {
		[ $where, $values ] = self::where( $game_id, $filters );

		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'mail_log' ) . ' WHERE ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
			...array_merge( $values, [ $limit, $offset ] )
		);
		return array_map( [ self::class, 'decode' ], $rows );
	}

	/**
	 * How many rows match the same filters.
	 *
	 * @param int                 $game_id
	 * @param array<string,mixed> $filters
	 * @return int
	 */
	public static function count_for_game( int $game_id, array $filters = [] ): int {
		[ $where, $values ] = self::where( $game_id, $filters );

		return (int) Manager::get_var( 'SELECT COUNT(*) FROM ' . Manager::table( 'mail_log' ) . ' WHERE ' . $where, ...$values );
	}

	/**
	 * Deletes every row older than the retention period.
	 *
	 * @return int|false Rows deleted.
	 */
	public static function prune(): int|false {
		global $wpdb;

		$cutoff = wp_date( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		return $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Manager::table( 'mail_log' ) . ' WHERE created_at < %s', $cutoff ) );
	}

	/**
	 * What a row's entity is called, for the screen: a plot's title, a secret's title, a character's name.
	 *
	 * @param string $type
	 * @param int    $id
	 * @return string Empty when the entity is gone or has no name worth showing.
	 */
	public static function entity_label( string $type, int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}
		switch ( $type ) {
			case 'plot':
				$plot = Plot::find( $id );
				return $plot ? (string) $plot->title : '';
			case 'secret':
				$secret = Secret::find( $id );
				return $secret ? (string) $secret->title : '';
			case 'release_batch':
				$batch = Release_Batch::find( $id );
				return $batch ? (string) ( $batch->name ?? '' ) : '';
			case 'submission':
				$submission = Submission::find( $id );
				return $submission ? (string) ( $submission->character_name ?? '' ) : '';
			case 'transfer':
				$transfer = Transfer::find( $id );
				return $transfer ? (string) ( $transfer->character_name ?? '' ) : '';
			case 'character':
				$character = Character::find( $id );
				return $character ? (string) $character->name : '';
			default:
				return '';
		}
	}

	/**
	 * @param int                 $game_id
	 * @param array<string,mixed> $filters
	 * @return array{0:string,1:array<int,mixed>} The WHERE clause and its values.
	 */
	private static function where( int $game_id, array $filters ): array {
		global $wpdb;

		$where  = [ 'game_id = %d' ];
		$values = [ $game_id ];

		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( $search !== '' ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( recipient_name LIKE %s OR recipient_email LIKE %s OR subject LIKE %s )';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}
		$kind = (string) ( $filters['kind'] ?? '' );
		if ( $kind !== '' && array_key_exists( $kind, self::kinds() ) ) {
			$where[]  = 'kind = %s';
			$values[] = $kind;
		}
		$result = (string) ( $filters['result'] ?? '' );
		if ( $result !== '' && in_array( $result, self::RESULTS, true ) ) {
			$where[]  = 'result = %s';
			$values[] = $result;
		}
		$since = (int) ( $filters['since'] ?? 0 );
		if ( $since > 0 ) {
			$where[]  = 'created_at >= %s';
			$values[] = wp_date( 'Y-m-d H:i:s', time() - $since );
		}
		$entity_type = (string) ( $filters['entity_type'] ?? '' );
		$entity_id   = (int) ( $filters['entity_id'] ?? 0 );
		if ( $entity_type !== '' && $entity_id > 0 ) {
			$where[]  = 'entity_type = %s AND entity_id = %d';
			$values[] = $entity_type;
			$values[] = $entity_id;
		}
		$wp_user_id = (int) ( $filters['wp_user_id'] ?? 0 );
		if ( $wp_user_id > 0 ) {
			$where[]  = 'wp_user_id = %d';
			$values[] = $wp_user_id;
		}

		return [ implode( ' AND ', $where ), $values ];
	}

	/**
	 * Casts a row's numbers to integers.
	 *
	 * @param object $row
	 * @return object
	 */
	private static function decode( object $row ): object {
		foreach ( [ 'id', 'game_id', 'wp_user_id', 'entity_id' ] as $field ) {
			if ( isset( $row->$field ) ) {
				$row->$field = (int) $row->$field;
			}
		}
		return $row;
	}

	private static function clip( string $text, int $length ): string {
		return mb_substr( trim( $text ), 0, $length );
	}
}
