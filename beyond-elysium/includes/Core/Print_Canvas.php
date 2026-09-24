<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Routes the character-sheet print page through a bare template with no theme header or footer.
 */
class Print_Canvas {

	/**
	 * Hooks the blank-canvas template and the print style block onto the print page.
	 */
	public static function register(): void {
		add_filter( 'template_include', [ self::class, 'maybe_use_blank_canvas' ] );
		add_action( 'wp_head', [ self::class, 'maybe_print_hide_style' ] );
	}

	/**
	 * Swaps in the plugin's blank-canvas template on the character-sheet print page.
	 */
	public static function maybe_use_blank_canvas( string $template ): string {
		if ( is_page( Page_Provisioner::PRINT_SLUG ) ) {
			return BE_PLUGIN_DIR . 'includes/templates/blank-canvas.php';
		}
		return $template;
	}

	/**
	 * Outputs a style block on the print page that hides the admin bar and any dark-mode toggle.
	 */
	public static function maybe_print_hide_style(): void {
		if ( ! is_page( Page_Provisioner::PRINT_SLUG ) ) {
			return;
		}

		echo '<style>#wpadminbar,.wp-dark-mode-floating-switch,.wp-dark-mode-switch{display:none!important}html{margin-top:0!important}</style>';
	}
}
