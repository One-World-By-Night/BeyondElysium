<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for per-character cosmetic sheet overrides.
 */
class Sheet_Style {

	/**
	 * Look up a character's sheet style override by character_id.
	 *
	 * @param int $character_id
	 * @return object|null
	 */
	public static function for_character( int $character_id ) {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'character_sheet_styles' ) . ' WHERE character_id = %d',
			$character_id
		);
		return self::decode( $row );
	}

	/**
	 * Create or replace a character's sheet style override.
	 *
	 * @param int   $character_id
	 * @param array $data font_family, accent_color, background_color, text_color,
	 *                     background_image_id, section_graphics.
	 * @return bool
	 */
	public static function save( int $character_id, array $data ): bool {
		global $wpdb;
		$table = Manager::table( 'character_sheet_styles' );

		$fields = [
			'font_family'         => $data['font_family'] ?? null,
			'accent_color'        => $data['accent_color'] ?? null,
			'background_color'    => $data['background_color'] ?? null,
			'text_color'          => $data['text_color'] ?? null,
			'background_image_id' => ! empty( $data['background_image_id'] ) ? (int) $data['background_image_id'] : null,
			'section_graphics'    => wp_json_encode( $data['section_graphics'] ?? [] ),
		];

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE character_id = %d", $character_id )
		);

		if ( $existing_id ) {
			$fields['updated_at'] = current_time( 'mysql' );
			return (bool) Manager::update( 'character_sheet_styles', $fields, [ 'id' => $existing_id ] );
		}

		$fields['character_id'] = $character_id;
		$fields['created_by']   = get_current_user_id();
		$fields['created_at']   = current_time( 'mysql' );
		$fields['updated_at']   = current_time( 'mysql' );

		return (bool) Manager::insert( 'character_sheet_styles', $fields );
	}

	/**
	 * Delete a character's sheet style override row, if one exists.
	 *
	 * @param int $character_id
	 * @return bool
	 */
	public static function delete_for_character( int $character_id ): bool {
		return (bool) Manager::delete( 'character_sheet_styles', [ 'character_id' => $character_id ] );
	}

	/**
	 * Decode a row's section_graphics JSON field into an array in place.
	 *
	 * @param object|null $row
	 * @return object|null
	 */
	private static function decode( $row ) {
		if ( $row && isset( $row->section_graphics ) && is_string( $row->section_graphics ) ) {
			$row->section_graphics = json_decode( $row->section_graphics, true ) ?? [];
		}
		return $row;
	}
}
