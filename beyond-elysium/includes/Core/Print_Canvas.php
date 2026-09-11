<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Routes the character-sheet print page through a bare template with no
 * theme header or footer, so nothing from the active theme's chrome
 * (title box, dark-mode toggle, etc.) appears when a sheet is printed or
 * exported. Applies only to the single page at
 * Page_Provisioner::PRINT_SLUG.
 */
class Print_Canvas {

	/**
	 * Hooks maybe_use_blank_canvas() onto template_include and
	 * maybe_print_hide_style() onto wp_head, so the print-canvas page gets
	 * its bare template and hides any remaining chrome.
	 */
	public static function register(): void {
		add_filter( 'template_include', [ self::class, 'maybe_use_blank_canvas' ] );
		add_action( 'wp_head', [ self::class, 'maybe_print_hide_style' ] );
	}

	/**
	 * Swaps in the plugin's blank-canvas template when the current page
	 * is the character-sheet print page, so it renders with no theme
	 * header or footer. Returns the given template unchanged for every
	 * other page.
	 */
	public static function maybe_use_blank_canvas( string $template ): string {
		if ( is_page( Page_Provisioner::PRINT_SLUG ) ) {
			return BE_PLUGIN_DIR . 'includes/templates/blank-canvas.php';
		}
		return $template;
	}

	/**
	 * Outputs a style block on the character-sheet print page that hides
	 * the admin bar and any dark-mode toggle another plugin injects
	 * directly into wp_head/wp_footer. Applied unconditionally, not only
	 * under @media print, since this page exists only to be printed or
	 * exported.
	 */
	public static function maybe_print_hide_style(): void {
		if ( ! is_page( Page_Provisioner::PRINT_SLUG ) ) {
			return;
		}

		echo '<style>#wpadminbar,.wp-dark-mode-floating-switch,.wp-dark-mode-switch{display:none!important}html{margin-top:0!important}</style>';
	}
}
