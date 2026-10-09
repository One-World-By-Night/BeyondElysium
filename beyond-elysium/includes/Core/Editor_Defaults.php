<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Prints one hidden `wp_editor()` instance so the site's real, filter-applied TinyMCE settings land in
 * `window.tinyMCEPreInit.mceInit`, where a dynamically-initialized editor can read them.
 */
class Editor_Defaults {

	const ID = 'be-html-editor-defaults';

	/**
	 * Hooks the hidden instance onto both the front-end and admin footers.
	 */
	public static function register(): void {
		add_action( 'wp_footer', [ self::class, 'print_hidden_instance' ] );
		add_action( 'admin_footer', [ self::class, 'print_hidden_instance' ] );
	}

	/**
	 * Prints the hidden editor once per page, for a logged-in viewer only.
	 */
	public static function print_hidden_instance(): void {
		if ( ! is_user_logged_in() || did_action( 'be_editor_defaults_printed' ) ) {
			return;
		}
		do_action( 'be_editor_defaults_printed' );

		echo '<div style="display:none" aria-hidden="true">';
		wp_editor( '', self::ID, [
			'textarea_name' => 'be_html_editor_defaults',
			'quicktags'     => false,
			'tinymce'       => true,
		] );
		echo '</div>';
	}
}
