<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for Storyteller-authored secrets attached to a plot, item, location, or NPC.
 */
class Secret {

	/** @var string[] Entity types a secret may attach to today. */
	const ENTITY_TYPES = [ 'plot', 'item', 'location', 'npc' ];

	/**
	 * Valid stored `audience` values.
	 */
	const AUDIENCE_VALUES = [ 'everyone', 'storytellers', 'restricted' ];

	const DEFAULT_AUDIENCE = 'storytellers';

	/**
	 * Look up a single secret by its primary key, or null when no row with that id exists.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		return self::decode( Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'secrets' ) . ' WHERE id = %d',
			$id
		) );
	}

	/**
	 * Every secret attached to one entity, newest first.
	 *
	 * @param int    $game_id
	 * @param string $entity_type
	 * @param int    $entity_id
	 * @return object[]
	 */
	public static function for_entity( int $game_id, string $entity_type, int $entity_id ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'secrets' ) . ' WHERE game_id = %d AND entity_type = %s AND entity_id = %d ORDER BY created_at DESC',
			$game_id,
			$entity_type,
			$entity_id
		);
		return array_map( static fn( $row ): object => self::decode( $row ) ?? $row, $rows ?: [] );
	}

	/**
	 * Every secret in a chronicle, across every entity it's attached to.
	 *
	 * @param int $game_id
	 * @return object[]
	 */
	public static function for_game( int $game_id ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'secrets' ) . ' WHERE game_id = %d ORDER BY created_at ASC',
			$game_id
		);
		return array_map( static fn( $row ): object => self::decode( $row ) ?? $row, $rows ?: [] );
	}

	/**
	 * Creates a secret.
	 *
	 * @param array $data
	 * @return int|false Insert ID, or false on any validation failure or unencodable JSON.
	 */
	public static function create( array $data ) {
		$entity_type = $data['entity_type'] ?? '';
		if ( ! in_array( $entity_type, self::ENTITY_TYPES, true ) ) {
			return false;
		}
		$audience = $data['audience'] ?? self::DEFAULT_AUDIENCE;
		if ( ! in_array( $audience, self::AUDIENCE_VALUES, true ) ) {
			return false;
		}

		$audience_rules = self::encode_json_field( $data['audience_rules'] ?? null );
		if ( $audience_rules === false ) {
			return false;
		}

		return Manager::insert( 'secrets', [
			'game_id'        => (int) $data['game_id'],
			'entity_type'    => $entity_type,
			'entity_id'      => (int) $data['entity_id'],
			'title'          => (string) ( $data['title'] ?? '' ),
			'content'        => $data['content'] ?? null,
			'audience'       => $audience,
			'audience_rules' => $audience_rules,
			'created_by'     => $data['created_by'] ?? get_current_user_id(),
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		] );
	}

	/**
	 * Updates a secret's title, content, audience, and/or audience_rules. entity_type/entity_id/game_id are not editable.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$allowed = [ 'title', 'content', 'audience', 'audience_rules' ];
		$update  = [];
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( isset( $update['audience'] ) && ! in_array( $update['audience'], self::AUDIENCE_VALUES, true ) ) {
			return false;
		}
		if ( array_key_exists( 'audience_rules', $update ) ) {
			$update['audience_rules'] = self::encode_json_field( $update['audience_rules'] );
			if ( $update['audience_rules'] === false ) {
				return false;
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql' );
		return Manager::update( 'secrets', $update, [ 'id' => $id ] ) !== false;
	}

	/**
	 * Deletes a secret, cascading to its reveals.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$savepoint = Transaction::begin( 'be_secret_delete' );

		Secret_Reveal::delete_for_secret( $id );
		$result = Manager::delete( 'secrets', [ 'id' => $id ] );

		if ( $result === false ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Transaction::commit( $savepoint );
		return true;
	}

	/**
	 * Encode a value for a JSON column, matching `Plot::encode_json_field()`'s exact contract: null stays null, an
	 * array/object is JSON-encoded, anything else is cast to string as-is (never expected in real use, kept only so this
	 * never silently drops data).
	 *
	 * @param mixed $value
	 * @return string|false|null False only when `wp_json_encode()` itself fails.
	 */
	private static function encode_json_field( $value ) {
		if ( $value === null ) {
			return null;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}

	/**
	 * Decodes the audience_rules JSON column on a row object in place.
	 *
	 * @param object|null $row
	 * @return object|null
	 */
	private static function decode( $row ) {
		if ( $row && property_exists( $row, 'audience_rules' ) && is_string( $row->audience_rules ) ) {
			$decoded = json_decode( $row->audience_rules, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( sprintf(
					'Beyond Elysium: secret id %d has corrupt audience_rules JSON; treating as null.',
					(int) ( $row->id ?? 0 )
				) );
				$decoded = null;
			}
			$row->audience_rules = $decoded;
		}
		return $row;
	}
}
