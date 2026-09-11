<?php

namespace BeyondElysium\Models;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Static data-access model for per-character cosmetic sheet overrides.
 *
 * Sheet_Style is a Database\Manager CRUD model backed by the
 * character_sheet_styles table. Each character may have at most one row,
 * holding an optional font, accent/background/text color, background image,
 * and per-section decorative graphics. CharacterSheet.css remains the default
 * appearance for every character; a row here only ever layers cosmetic
 * overrides on top, so a missing row simply means no customization.
 *
 * @see BE_PROCESS/DECISIONLOG.md Decision 041
 */
class Sheet_Style {

	/**
	 * Look up a character's sheet style override by character_id. Returns
	 * the row with its section_graphics field decoded, or null when the
	 * character has no override row.
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
	 * Create or replace a character's sheet style override. Always a full
	 * upsert of every field - never a partial patch - since each character
	 * has at most one override row.
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
	 * Delete a character's sheet style override row, if one exists. Used to
	 * cascade a character deletion so no orphaned override remains behind
	 * after the character itself is gone.
	 *
	 * @param int $character_id
	 * @return bool
	 */
	public static function delete_for_character( int $character_id ): bool {
		return (bool) Manager::delete( 'character_sheet_styles', [ 'character_id' => $character_id ] );
	}

	/**
	 * Decode a row's section_graphics JSON field into an array in place.
	 * Passes null rows through unchanged, and normalizes an unparseable or
	 * absent value to an empty array.
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
