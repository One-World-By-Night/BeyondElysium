<?php

namespace BeyondElysium\Services;

use BeyondElysium\Services\Display\Change_Description;

defined( 'ABSPATH' ) || exit;

/**
 * One waiting change as the line a request to the Archivist Toolkit carries: what it is called, which section it is in,
 * what changes, what it costs, BE's reason and the player's note. The words are English whatever the site's language,
 * because the Toolkit's readers read English and the names are the catalog's stored ones.
 */
class Coordinator_Line {

	/**
	 * @param object $change  A `character_changes` row.
	 * @param string $section The name of the sheet section the change is in, or ''.
	 * @return array{label:string,section:string,detail:string,xp:int,reason:?string,player_note:?string}
	 */
	public static function of( object $change, string $section ): array {
		$data = is_array( $change->change_data ) ? $change->change_data : ( json_decode( (string) $change->change_data, true ) ?: [] );
		$type = (string) $change->change_type;

		[ $label, $detail ] = self::label_and_detail( $type, $data, $change );

		$reason = trim( (string) ( $change->reason ?? '' ) );
		$note   = trim( (string) ( $change->notes ?? '' ) );

		return [
			'label'       => $label,
			'section'     => $section,
			'detail'      => $detail,
			'xp'          => (int) round( (float) ( $change->xp_cost ?? 0 ) ),
			'reason'      => $reason === '' ? null : $reason,
			'player_note' => $note === '' ? null : $note,
		];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array{0:string,1:string}
	 */
	private static function label_and_detail( string $type, array $data, object $change ): array {
		$trait = (array) ( $data['trait'] ?? [] );
		$name  = (string) ( $trait['power_name'] ?? $trait['name'] ?? '' );

		switch ( $type ) {
			case 'add_trait':
				if ( isset( $trait['level'] ) ) {
					return [ $name, 'level ' . $trait['level'] ];
				}
				$count = (int) ( $trait['count'] ?? 1 );
				return [ $name, $count > 1 ? 'x' . $count : '' ];

			case 'modify_trait':
				$previous = (array) ( $data['previous'] ?? [] );
				if ( isset( $trait['level'] ) ) {
					return [ $name, isset( $previous['level'] ) ? 'from level ' . $previous['level'] . ' to level ' . $trait['level'] : 'to level ' . $trait['level'] ];
				}
				if ( isset( $trait['count'] ) ) {
					return [ $name, isset( $previous['count'] ) ? 'from x' . $previous['count'] . ' to x' . $trait['count'] : 'to x' . $trait['count'] ];
				}
				return [ $name, 'changed' ];

			case 'remove_trait':
				return [ $name, 'removed' ];

			case 'modify_resource':
				$values = (array) ( $data['values'] ?? [] );
				$pool   = (string) ( array_key_first( $values ) ?? '' );
				$value  = $values[ $pool ] ?? null;
				$rating = is_array( $value ) ? ( $value['permanent'] ?? null ) : $value;
				return [ $pool, $rating === null ? 'changed' : 'to ' . $rating ];

			case 'modify_identity':
				$fields = (array) ( $data['fields'] ?? [] );
				$field  = (string) ( array_key_first( $fields ) ?? '' );
				$value  = $fields[ $field ] ?? '';
				$value  = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
				return [ $field, 'to ' . $value ];
		}

		return [ Change_Description::describe( $type, $data ), '' ];
	}
}
