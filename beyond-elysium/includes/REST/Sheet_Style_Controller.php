<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Sheet_Style;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a character's sheet style override: font, accent/
 * background/text colors, a background image, and per-section graphics.
 * Reading a style requires be_view_characters; writing one requires
 * be_customize_sheet. Exactly one style row exists per character.
 */
class Sheet_Style_Controller extends Base_Controller {

	protected $rest_base = 'sheet-style';

	/** Font family values accepted for a character's sheet style; rendered directly as CSS. */
	const ALLOWED_FONTS = [
		'', 'Georgia, serif', "'Times New Roman', serif", "'Garamond', serif",
		"'Trajan Pro', 'Cinzel', serif", "'Cormorant Garamond', serif",
		'Arial, sans-serif', "'Helvetica Neue', sans-serif", "'Segoe UI', sans-serif",
	];

	/**
	 * Registers the REST routes for retrieving, updating, and deleting a
	 * character's sheet style override. All three routes are scoped to a
	 * game slug and character id.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/characters/(?P<character_id>\d+)/sheet-style', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_customize_sheet' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_customize_sheet' ),
			],
		] );
	}

	/**
	 * Returns a character's sheet style override with the background image
	 * and section graphic attachment ids resolved to real URLs. Returns an
	 * empty object, not a 404, when the character has no override saved.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$character = $this->resolve_character( (int) $request['character_id'], $request['game_slug'] );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		$style = Sheet_Style::for_character( (int) $request['character_id'] );

		// No saved override is a valid state; an empty object signals the default sheet.
		if ( ! $style ) {
			return $this->success( new \stdClass() );
		}

		// Resolves the stored background image attachment id to a real URL.
		$style->background_image_url = $style->background_image_id
			? wp_get_attachment_image_url( (int) $style->background_image_id, 'large' )
			: null;

		$graphic_urls = [];
		foreach ( (array) $style->section_graphics as $block_slug => $attachment_id ) {
			$url = wp_get_attachment_image_url( (int) $attachment_id, 'medium' );
			if ( $url ) {
				$graphic_urls[ $block_slug ] = $url;
			}
		}
		$style->section_graphic_urls = $graphic_urls;

		return $this->success( $style );
	}

	/**
	 * Validates and saves a character's sheet style override: font must be
	 * one of the allowed choices, colors must be valid hex values, and
	 * background_image_id/section_graphics must reference real media
	 * attachments. Persists the validated data and returns the saved style.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$character = $this->resolve_character( (int) $request['character_id'], $request['game_slug'] );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		$font = (string) ( $request->get_param( 'font_family' ) ?? '' );
		if ( ! in_array( $font, self::ALLOWED_FONTS, true ) ) {
			return $this->error( 'invalid_param', __( 'font_family must be one of the offered choices.', 'beyond-elysium' ), 400 );
		}

		foreach ( [ 'accent_color', 'background_color', 'text_color' ] as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null && $value !== '' && ! self::is_valid_hex_color( (string) $value ) ) {
				return $this->error( 'invalid_param', sprintf( __( '%s must be a hex color like #1a1a1a.', 'beyond-elysium' ), $field ), 400 );
			}
		}

		$background_image_id = $request->get_param( 'background_image_id' );
		if ( ! empty( $background_image_id ) && get_post_type( (int) $background_image_id ) !== 'attachment' ) {
			return $this->error( 'invalid_param', __( 'background_image_id must be a real media attachment.', 'beyond-elysium' ), 400 );
		}

		$section_graphics = (array) ( $request->get_param( 'section_graphics' ) ?? [] );
		foreach ( $section_graphics as $block_slug => $attachment_id ) {
			if ( ! is_string( $block_slug ) || ! is_numeric( $attachment_id )
				|| get_post_type( (int) $attachment_id ) !== 'attachment'
			) {
				return $this->error( 'invalid_param', __( 'section_graphics must map block slugs to real media attachment ids.', 'beyond-elysium' ), 400 );
			}
		}

		$data = [
			'font_family'         => $font,
			'accent_color'        => $request->get_param( 'accent_color' ),
			'background_color'    => $request->get_param( 'background_color' ),
			'text_color'          => $request->get_param( 'text_color' ),
			'background_image_id' => $request->get_param( 'background_image_id' ),
			'section_graphics'    => array_map( 'intval', $section_graphics ),
		];

		$ok = Sheet_Style::save( (int) $request['character_id'], $data );
		if ( ! $ok ) {
			return $this->error( 'save_failed', __( 'Failed to save sheet style.', 'beyond-elysium' ), 500 );
		}

		$style = Sheet_Style::for_character( (int) $request['character_id'] );
		return $this->success( $style );
	}

	/**
	 * Deletes a character's sheet style override entirely, reverting the
	 * character to the default sheet appearance. Confirms the character
	 * exists before deleting.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$character = $this->resolve_character( (int) $request['character_id'], $request['game_slug'] );
		if ( is_wp_error( $character ) ) {
			return $character;
		}

		Sheet_Style::delete_for_character( (int) $request['character_id'] );
		return $this->success( null, 204 );
	}

	/**
	 * Checks whether a string is a 6-digit hex color value prefixed with
	 * a hash character, such as #1a1a1a. Used to validate color fields
	 * before they are saved and rendered as CSS.
	 *
	 * @param string $value
	 * @return bool
	 */
	private static function is_valid_hex_color( string $value ): bool {
		return (bool) preg_match( '/^#[0-9a-fA-F]{6}$/', $value );
	}

	/**
	 * Looks up a character by id and confirms both the game and the
	 * character exist and that the character belongs to that game,
	 * returning a WP_Error with a 404 status otherwise.
	 *
	 * @param int    $character_id
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	private function resolve_character( int $character_id, string $game_slug ) {
		$game = Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$character = Character::find( $character_id );
		if ( ! $character || $character->owner_slug !== $game_slug ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		return $character;
	}
}
