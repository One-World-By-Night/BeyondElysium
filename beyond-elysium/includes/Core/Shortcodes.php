<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Plain WordPress shortcodes, for a page that isn't built with Elementor.
 * Each shortcode renders the exact same `[data-be-widget]` mount-point
 * markup an Elementor widget would (`Elementor\Widgets\House_Rules`, for
 * example) - `Plugin::enqueue_frontend()` already loads the hydration
 * bundle unconditionally on every front-end page, so a shortcode needs no
 * enqueue of its own, only the markup.
 */
class Shortcodes {

	public static function register(): void {
		add_shortcode( 'be_house_rules', [ self::class, 'render_house_rules' ] );
	}

	/**
	 * `[be_house_rules game="chronicle-slug"]` - the same live House Rules
	 * view the `House_Rules` Elementor widget renders (Decision 094).
	 *
	 * @param array<string,string>|string $atts
	 */
	public static function render_house_rules( $atts ): string {
		$atts = shortcode_atts( [ 'game' => '' ], $atts, 'be_house_rules' );

		$config = [ 'gameSlug' => $atts['game'] ];

		return sprintf(
			'<div data-be-widget="%s" data-be-config="%s"></div>',
			esc_attr( 'house-rules' ),
			esc_attr( (string) wp_json_encode( $config ) )
		);
	}
}
