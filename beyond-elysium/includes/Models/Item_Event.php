<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for an item's own history.
 */
class Item_Event {

	/** @var string[] */
	const EVENT_TYPES = [ 'given', 'taken', 'traded', 'stolen', 'lost', 'used', 'copied', 'proposed', 'adjusted' ];

	/**
	 * Every event for a world object, oldest first.
	 *
	 * @param int $world_object_id
	 * @return object[]
	 */
	public static function for_object( int $world_object_id ): array {
		return Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'item_events' ) . ' WHERE world_object_id = %d ORDER BY created_at ASC, id ASC',
			$world_object_id
		);
	}

	/**
	 * Deletes every event recorded for one world object.
	 *
	 * @param int $world_object_id
	 * @return bool
	 */
	public static function delete_for_object( int $world_object_id ): bool {
		return Manager::delete( 'item_events', [ 'world_object_id' => $world_object_id ] ) !== false;
	}

	/**
	 * Records one event.
	 *
	 * @param array $data
	 * @return int|false
	 */
	public static function record( array $data ) {
		$event = $data['event'] ?? '';
		if ( ! in_array( $event, self::EVENT_TYPES, true ) ) {
			return false;
		}

		return Manager::insert( 'item_events', [
			'game_id'            => (int) $data['game_id'],
			'world_object_id'    => (int) $data['world_object_id'],
			'event'              => $event,
			'character_id'       => ! empty( $data['character_id'] ) ? (int) $data['character_id'] : null,
			'from_character_id'  => ! empty( $data['from_character_id'] ) ? (int) $data['from_character_id'] : null,
			'note'               => $data['note'] ?? null,
			'recorded_by'        => $data['recorded_by'] ?? get_current_user_id(),
			'created_at'         => current_time( 'mysql' ),
		] );
	}

	/**
	 * The shape a client is given for one event.
	 *
	 * @param object $event A row from `for_object()`.
	 * @return array<string,mixed>
	 */
	public static function public_shape( object $event ): array {
		return [
			'id'                => (int) $event->id,
			'event'             => $event->event,
			'character_id'      => $event->character_id !== null ? (int) $event->character_id : null,
			'from_character_id' => $event->from_character_id !== null ? (int) $event->from_character_id : null,
			'note'              => $event->note,
			'recorded_by'       => (int) $event->recorded_by,
			'created_at'        => $event->created_at,
		];
	}
}
