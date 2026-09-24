<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Beyond Elysium's wp-admin menu pages and renders their mount points.
 */
class Admin_Menu {

	/**
	 * Hooks the menu pages onto admin_menu.
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_pages' ] );
	}

	/**
	 * Adds the top-level Beyond Elysium menu and its group submenu pages.
	 */
	public static function add_pages(): void {
		add_menu_page(
			__( 'Beyond Elysium', 'beyond-elysium' ),
			__( 'Beyond Elysium', 'beyond-elysium' ),
			'be_view_characters',
			'beyond-elysium',
			[ self::class, 'render_dashboard' ],
			'dashicons-groups',
			30
		);

		// The Dashboard row, registered under the parent's own slug.
		add_submenu_page(
			'beyond-elysium',
			__( 'Beyond Elysium', 'beyond-elysium' ),
			__( 'Dashboard', 'beyond-elysium' ),
			'be_view_characters',
			'beyond-elysium',
			[ self::class, 'render_dashboard' ]
		);

		add_submenu_page(
			'beyond-elysium',
			__( 'Characters', 'beyond-elysium' ),
			__( 'Characters', 'beyond-elysium' ),
			'be_manage_characters',
			'beyond-elysium-characters',
			[ self::class, 'render_characters' ]
		);

		add_submenu_page(
			'beyond-elysium',
			__( 'Plots', 'beyond-elysium' ),
			__( 'Plots', 'beyond-elysium' ),
			'be_manage_plots',
			'beyond-elysium-plots',
			[ self::class, 'render_plots' ]
		);

		// Mounts the world-object manager widget, gated on be_manage_world_objects.
		add_submenu_page(
			'beyond-elysium',
			__( 'Items & Locations', 'beyond-elysium' ),
			__( 'Items & Locations', 'beyond-elysium' ),
			'be_manage_world_objects',
			'beyond-elysium-world-objects',
			[ self::class, 'render_world_objects' ]
		);

		// Mounts the game-nights widget, gated on be_manage_sessions.
		add_submenu_page(
			'beyond-elysium',
			__( 'Game Nights', 'beyond-elysium' ),
			__( 'Game Nights', 'beyond-elysium' ),
			'be_manage_sessions',
			'beyond-elysium-game-nights',
			[ self::class, 'render_game_nights' ]
		);

		// Query Tool and Reports, gated on the broader of the two tabs' capabilities.
		add_submenu_page(
			'beyond-elysium',
			__( 'Query Tool', 'beyond-elysium' ),
			__( 'Query Tool', 'beyond-elysium' ),
			'be_run_queries',
			'beyond-elysium-query-hub',
			[ self::class, 'render_query_hub' ]
		);

		// One page with two tabs (character import and game-file import), gated on be_import.
		add_submenu_page(
			'beyond-elysium',
			__( 'Import', 'beyond-elysium' ),
			__( 'Import', 'beyond-elysium' ),
			'be_import',
			'beyond-elysium-import',
			[ self::class, 'render_import' ]
		);

		// Chronicle Setup, Chronicle Access, Action & Rumor Settings and AI Assist.
		add_submenu_page(
			'beyond-elysium',
			__( 'Chronicle Setup', 'beyond-elysium' ),
			__( 'Chronicle Setup', 'beyond-elysium' ),
			'be_manage_chronicle_setup',
			'beyond-elysium-chronicle-setup-hub',
			[ self::class, 'render_chronicle_setup_hub' ]
		);

		// Games, Schema Blocks, Creature Stacks, Templates and Approval Rules.
		add_submenu_page(
			'beyond-elysium',
			__( 'System Config', 'beyond-elysium' ),
			__( 'System Config', 'beyond-elysium' ),
			'be_manage_schemas',
			'beyond-elysium-system-config',
			[ self::class, 'render_system_config_hub' ]
		);

		// Renders the Storyteller/admin/REST guides, gated on be_view_characters.
		add_submenu_page(
			'beyond-elysium',
			__( 'Docs', 'beyond-elysium' ),
			__( 'Docs', 'beyond-elysium' ),
			'be_view_characters',
			'beyond-elysium-docs',
			[ self::class, 'render_docs' ]
		);
	}

	/**
	 * Renders the top-level "Beyond Elysium" landing page.
	 */
	public static function render_dashboard(): void {
		self::render_mount( 'admin-dashboard' );
	}

	/**
	 * Renders the Characters admin page.
	 */
	public static function render_characters(): void {
		self::render_mount( 'admin-characters' );
	}

	/**
	 * Renders the Plots admin page.
	 */
	public static function render_plots(): void {
		self::render_mount( 'admin-plots' );
	}

	/**
	 * Renders the Items & Locations admin page.
	 */
	public static function render_world_objects(): void {
		self::render_mount( 'admin-world-objects' );
	}

	/**
	 * Renders the Game Nights admin page.
	 */
	public static function render_game_nights(): void {
		self::render_mount( 'admin-game-nights' );
	}

	/**
	 * Renders the Query Tool hub admin page.
	 */
	public static function render_query_hub(): void {
		self::render_mount( 'admin-query-hub' );
	}

	/**
	 * Renders the Import admin page.
	 */
	public static function render_import(): void {
		self::render_mount( 'admin-import' );
	}

	/**
	 * Renders the Chronicle Setup hub admin page.
	 */
	public static function render_chronicle_setup_hub(): void {
		// Re-runs page provisioning on request.
		if ( isset( $_GET['provision_pages'] ) && current_user_can( 'be_manage_games' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			\BeyondElysium\Core\Page_Provisioner::maybe_provision();
		}
		self::render_mount( 'admin-chronicle-setup-hub' );
	}

	/**
	 * Renders the System Config hub admin page.
	 */
	public static function render_system_config_hub(): void {
		self::render_mount( 'admin-system-config-hub' );
	}

	/**
	 * Renders the Docs admin page.
	 */
	public static function render_docs(): void {
		self::render_mount( 'admin-docs' );
	}

	/**
	 * Outputs the widget mount markup for one admin page: a wrapped div carrying the given widget name as a data
	 * attribute, followed by the memorial footer.
	 */
	private static function render_mount( string $widget ): void {
		printf(
			'<div class="wrap"><h1></h1><div data-be-widget="%s" data-be-config="{}"></div></div>',
			esc_attr( $widget )
		);
		self::render_memorial_footer();
	}

	/**
	 * Outputs the memorial footer paragraph centered beneath the content on every admin page rendered through
	 * render_mount().
	 */
	private static function render_memorial_footer(): void {
		echo '<p style="text-align:center;color:#787c82;font-size:13px;margin:2em 0 1em;">'
			. 'In Memory of Arielle &ldquo;XP Day&rdquo; M.'
			. '</p>';
	}

	/**
	 * Enqueues the admin script and style bundle on this plugin's own wp-admin pages only, identified by $hook_suffix.
	 *
	 * @param string $hook_suffix
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( strpos( $hook_suffix, 'beyond-elysium' ) === false ) {
			return;
		}

		$asset_file = BE_PLUGIN_DIR . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'beyond-elysium-admin',
			BE_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Registers translations under the 'beyond-elysium-admin' script handle.
		wp_set_script_translations( 'beyond-elysium-admin', 'beyond-elysium', BE_PLUGIN_DIR . 'languages' );

		wp_enqueue_style(
			'beyond-elysium-admin',
			BE_PLUGIN_URL . 'build/index.css',
			[],
			$asset['version']
		);

		// Passes the REST root, nonce, locale and capability flags to the admin script.
		wp_localize_script( 'beyond-elysium-admin', 'beyondElysium', [
			'restUrl' => rest_url( 'be/v1/' ),
			'homeUrl' => trailingslashit( home_url() ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'version' => BE_VERSION,
			'locale'  => get_locale(),
			'capabilities' => [
				'be_manage_plots'          => current_user_can( 'be_manage_plots' ),
				'be_manage_characters'     => current_user_can( 'be_manage_characters' ),
				// Gates the import wizard's "also add to catalog" checkbox.
				'be_manage_schemas'        => current_user_can( 'be_manage_schemas' ),
				// Gates whether WorldObjectCard.tsx shows the full connection manager or a read-only list.
				'be_manage_connections'    => current_user_can( 'be_manage_connections' ),
				// Gates AdminImport.tsx's two tabs independently.
				'be_import'                => current_user_can( 'be_import' ),
				'be_manage_games'          => current_user_can( 'be_manage_games' ),
				'be_manage_apr'            => current_user_can( 'be_manage_apr' ),
				// Gates the Chronicle Setup hub's first tab.
				'be_manage_chronicle_setup' => current_user_can( 'be_manage_chronicle_setup' ),
				// Each of these gates one tab inside a shared hub page.
				'be_manage_world_objects'  => current_user_can( 'be_manage_world_objects' ),
				'be_run_queries'           => current_user_can( 'be_run_queries' ),
				'be_view_reports'          => current_user_can( 'be_view_reports' ),
				'be_manage_templates'      => current_user_can( 'be_manage_templates' ),
				'be_manage_approval_rules' => current_user_can( 'be_manage_approval_rules' ),
				// Gates the wp-admin Game Nights page.
				'be_manage_sessions'       => current_user_can( 'be_manage_sessions' ),
				// Gates the System Config Translations tab.
				'be_manage_translations'   => current_user_can( 'be_manage_translations' ),
			],
		] );

		wp_enqueue_media();
		wp_enqueue_editor();
	}
}
