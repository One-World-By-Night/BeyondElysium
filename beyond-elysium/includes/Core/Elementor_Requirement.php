<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces Elementor as a required dependency and offers a one-click way to satisfy it.
 */
class Elementor_Requirement {

	/**
	 * Elementor's main plugin file, relative to the plugins directory.
	 */
	const PLUGIN_FILE = 'elementor/elementor.php';

	/**
	 * Elementor's wordpress.org slug, used for the install link and the header.
	 */
	const SLUG = 'elementor';

	/**
	 * Hooks render() onto admin_notices.
	 */
	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	/**
	 * Reports whether Elementor is present and running.
	 */
	public static function is_satisfied(): bool {
		return did_action( 'elementor/loaded' ) > 0 || self::is_active();
	}

	/**
	 * Reports whether Elementor is installed on this site at all, regardless of whether it is currently activated.
	 */
	public static function is_installed(): bool {
		return file_exists( WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE );
	}

	/**
	 * Reports whether Elementor is an active plugin.
	 */
	private static function is_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::PLUGIN_FILE );
	}

	/**
	 * Renders an admin notice when Elementor is missing, with an action link that either installs or activates it
	 * depending on which step the site actually needs.
	 */
	public static function render(): void {
		if ( self::is_satisfied() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$action = self::is_installed() ? self::activate_link() : self::install_link();

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p>%3$s</div>',
			esc_html__( 'Beyond Elysium requires Elementor.', 'beyond-elysium' ),
			esc_html__(
				'Elementor provides the page builder this plugin registers its character sheet, roster, editor, and Storyteller widgets into. Without it those widgets cannot be placed on a page.',
				'beyond-elysium'
			),
			$action
		);
	}

	/**
	 * Builds the "Install Elementor" action paragraph, or a plain instruction for a user who cannot install plugins
	 * themselves.
	 */
	private static function install_link(): string {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return '<p>' . esc_html__( 'Ask a site administrator to install and activate Elementor.', 'beyond-elysium' ) . '</p>';
		}

		$url = wp_nonce_url(
			self_admin_url( 'update.php?action=install-plugin&plugin=' . self::SLUG ),
			'install-plugin_' . self::SLUG
		);

		return sprintf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'Install Elementor', 'beyond-elysium' )
		);
	}

	/**
	 * Builds the "Activate Elementor" action paragraph for a site that already has Elementor installed but switched off.
	 */
	private static function activate_link(): string {
		$url = wp_nonce_url(
			self_admin_url( 'plugins.php?action=activate&plugin=' . self::PLUGIN_FILE ),
			'activate-plugin_' . self::PLUGIN_FILE
		);

		return sprintf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'Activate Elementor', 'beyond-elysium' )
		);
	}
}
