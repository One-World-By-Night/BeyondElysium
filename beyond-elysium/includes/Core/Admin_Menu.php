<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Beyond Elysium's wp-admin menu pages and renders their mount
 * points. Each admin page is a thin PHP shell containing one container div
 * that the front-end React router hydrates into the matching admin widget.
 * Also enqueues the admin script/style bundle and localizes REST connection
 * details and capability flags for it.
 */
class Admin_Menu {

	/**
	 * Hooks add_pages() onto admin_menu so Beyond Elysium's top-level menu
	 * and all of its submenu pages are registered the next time wp-admin
	 * builds its menu.
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_pages' ] );
	}

	/**
	 * Adds the top-level "Beyond Elysium" admin menu (now a real landing
	 * dashboard, not an alias for Games) and its 8 group submenu pages:
	 * Characters, Plots, Items & Locations, Query Tool (+ Reports), Import,
	 * Chronicle Setup (+ Chronicle Access + Action & Rumor Settings), System
	 * Config (Games + Schema Blocks + Creature Stacks + Templates + Approval
	 * Rules), and Docs - 9 visible rows in total, the Dashboard row being
	 * what the top-level label itself links to (see the comment on that
	 * add_submenu_page() call for why it stays visible rather than hidden).
	 * admin-menu-consolidation-design.md collapsed 16 flat pages down to
	 * these 9. Each hub page is gated on the broadest capability among its
	 * own tabs, since a tab hides itself individually when its own capability
	 * is absent (StorytellerToolkitPage's established client-side pattern).
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

		// Registered with the parent's own slug so admin.php?page=beyond-elysium (what
		// the top-level label itself links to) renders the dashboard rather than
		// WordPress's own auto-duplicated first-submenu fallback. Deliberately left
		// visible as a normal "Dashboard" row rather than hidden via
		// remove_submenu_page(): WordPress core builds the top-level label's own href
		// from the first REMAINING $submenu entry for this parent, not the slug
		// add_menu_page() was originally given - hiding this row silently repoints the
		// top-level click at whatever became the new first entry instead (confirmed
		// live; this is why "Characters" briefly became that entry during development).
		// 9 visible rows including this one, not 8, is the honest tradeoff for a
		// top-level click that reliably lands on the dashboard.
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

		// Mounts the game-nights widget, gated on be_manage_sessions (1.1.0 §3.1).
		add_submenu_page(
			'beyond-elysium',
			__( 'Game Nights', 'beyond-elysium' ),
			__( 'Game Nights', 'beyond-elysium' ),
			'be_manage_sessions',
			'beyond-elysium-game-nights',
			[ self::class, 'render_game_nights' ]
		);

		// Query Tool + Reports (owner's call: "fold into Query Tool"). Gated on the
		// broader of the two tabs' capabilities; each tab hides itself individually.
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

		// Chronicle Setup + Chronicle Access + Action & Rumor Settings + AI Assist - all
		// "configure this one chronicle," and every one of them staff-only. The page takes the
		// widest capability among its tabs, the rule System Config follows with
		// be_manage_schemas: be_manage_chronicle_setup (administrator, editor) is held by
		// everyone who holds any of the others. It used to be be_view_characters, which every
		// role has, so a plain player was listed this page and could open it (1.3.2.2).
		add_submenu_page(
			'beyond-elysium',
			__( 'Chronicle Setup', 'beyond-elysium' ),
			__( 'Chronicle Setup', 'beyond-elysium' ),
			'be_manage_chronicle_setup',
			'beyond-elysium-chronicle-setup-hub',
			[ self::class, 'render_chronicle_setup_hub' ]
		);

		// Games + Schema Blocks + Creature Stacks + Templates + Approval Rules - all
		// global, cross-chronicle admin. be_manage_schemas is the widest of the five.
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
	 * Renders the top-level "Beyond Elysium" landing page. Outputs the mount
	 * point for the admin-dashboard widget: an about/what's-where reference,
	 * the widgets & shortcodes inventory, and a call-to-action that's
	 * prominent only while no real chronicle exists yet
	 * (admin-menu-consolidation-design.md).
	 */
	public static function render_dashboard(): void {
		self::render_mount( 'admin-dashboard' );
	}

	/**
	 * Renders the Characters admin page. Outputs the mount point for the
	 * admin-characters widget, which lists and manages player characters
	 * and NPCs (NPC Roster folded in - the same page's own NPC/player
	 * toggle already made a separate page redundant), followed by the
	 * shared memorial footer.
	 */
	public static function render_characters(): void {
		self::render_mount( 'admin-characters' );
	}

	/**
	 * Renders the Plots admin page. Outputs the mount point for the
	 * admin-plots widget, a tabbed shell over Plots & Rumors and Releases
	 * (1.1.0 §3.2) - both be_manage_plots surfaces - followed by the shared
	 * memorial footer.
	 */
	public static function render_plots(): void {
		self::render_mount( 'admin-plots' );
	}

	/**
	 * Renders the Items & Locations admin page. Outputs the mount point
	 * for the admin-world-objects widget, which manages world objects,
	 * followed by the shared memorial footer.
	 */
	public static function render_world_objects(): void {
		self::render_mount( 'admin-world-objects' );
	}

	/**
	 * Renders the Game Nights admin page. Outputs the mount point for the
	 * admin-game-nights widget: a chronicle picker plus the shared
	 * GameNights component (1.1.0 §3.1).
	 */
	public static function render_game_nights(): void {
		self::render_mount( 'admin-game-nights' );
	}

	/**
	 * Renders the Query Tool hub admin page. Outputs the mount point for
	 * the admin-query-hub widget, a tabbed shell over Query Tool and
	 * Reports - each tab hides itself independently by capability
	 * (admin-menu-consolidation-design.md).
	 */
	public static function render_query_hub(): void {
		self::render_mount( 'admin-query-hub' );
	}

	/**
	 * Renders the Import admin page. Outputs the mount point for the
	 * admin-import widget, which imports character and game exchange
	 * files, followed by the shared memorial footer.
	 */
	public static function render_import(): void {
		self::render_mount( 'admin-import' );
	}

	/**
	 * Renders the Chronicle Setup hub admin page. Outputs the mount point
	 * for the admin-chronicle-setup-hub widget, a tabbed shell over
	 * Chronicle Setup, Chronicle Access, and Action & Rumor Settings - all
	 * three "configure this one chronicle" (admin-menu-consolidation-design.md).
	 */
	public static function render_chronicle_setup_hub(): void {
		// GS-8's fix link for row 4 (Setup_Status::row_front_end_pages()) - a
		// plain admin-page link rather than a REST call, matching this row's own "link,
		// not duplicated UI" shape (§6.7). Capability-gated the same as the page itself.
		// page-consolidation-design.md: the four fixed pages are chronicle-independent, so
		// this no longer takes a &game= param - it just re-runs the same provisioning
		// be_after_upgrade already does.
		if ( isset( $_GET['provision_pages'] ) && current_user_can( 'be_manage_games' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idempotent, capability-gated GET action, matching this project's other admin-link fixes.
			\BeyondElysium\Core\Page_Provisioner::maybe_provision();
		}
		self::render_mount( 'admin-chronicle-setup-hub' );
	}

	/**
	 * Renders the System Config hub admin page. Outputs the mount point
	 * for the admin-system-config-hub widget, a tabbed shell over Games,
	 * Schema Blocks, Creature Stacks, Templates, and Approval Rules - all
	 * global, cross-chronicle admin (admin-menu-consolidation-design.md).
	 */
	public static function render_system_config_hub(): void {
		self::render_mount( 'admin-system-config-hub' );
	}

	/**
	 * Renders the Docs admin page. Outputs the mount point for the
	 * admin-docs widget, which displays the Storyteller, admin, and REST
	 * guides, followed by the shared memorial footer.
	 */
	public static function render_docs(): void {
		self::render_mount( 'admin-docs' );
	}

	/**
	 * Outputs the widget mount markup for one admin page: a wrapped div
	 * carrying the given widget name as a data attribute, followed by the
	 * memorial footer. The front-end router hydrates the div into the
	 * matching React component.
	 */
	private static function render_mount( string $widget ): void {
		printf(
			'<div class="wrap"><h1></h1><div data-be-widget="%s" data-be-config="{}"></div></div>',
			esc_attr( $widget )
		);
		self::render_memorial_footer();
	}

	/**
	 * Outputs the memorial footer paragraph centered beneath the content
	 * on every admin page rendered through render_mount(). Plain
	 * inline-styled markup, not a widget mount point.
	 */
	private static function render_memorial_footer(): void {
		echo '<p style="text-align:center;color:#787c82;font-size:13px;margin:2em 0 1em;">'
			. 'In Memory of Arielle &ldquo;XP Day&rdquo; M.'
			. '</p>';
	}

	/**
	 * Enqueues the admin script and style bundle on this plugin's own
	 * wp-admin pages only, identified by $hook_suffix. Registers script
	 * translations and localizes REST connection details and capability
	 * flags, then enqueues the media and editor scripts the admin widgets
	 * depend on.
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

		// Capability flags mirror Plugin::enqueue_frontend()'s payload; REST routes enforce the real checks server-side.
		wp_localize_script( 'beyond-elysium-admin', 'beyondElysium', [
			'restUrl' => rest_url( 'be/v1/' ),
			// See Plugin::enqueue_frontend()'s identical field for why a link is built from
			// this and never from window.location.origin (1.2.9.1, D95).
			'homeUrl' => trailingslashit( home_url() ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'version' => BE_VERSION,
			// See Plugin::enqueue_frontend()'s identical field for why this is site locale,
			// not per-user (i18n-pt-br-design.md, Decision 106).
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
				// Gates the Chronicle Setup hub's own first tab (1.3.2.2) - it had no gate at all.
				'be_manage_chronicle_setup' => current_user_can( 'be_manage_chronicle_setup' ),
				// admin-menu-consolidation-design.md: each of these now gates one tab inside a
				// shared hub page rather than an entire wp-admin submenu of its own.
				'be_manage_world_objects'  => current_user_can( 'be_manage_world_objects' ),
				'be_run_queries'           => current_user_can( 'be_run_queries' ),
				'be_view_reports'          => current_user_can( 'be_view_reports' ),
				'be_manage_templates'      => current_user_can( 'be_manage_templates' ),
				'be_manage_approval_rules' => current_user_can( 'be_manage_approval_rules' ),
				// Gates the wp-admin Game Nights page (1.1.0 §3.1).
				'be_manage_sessions'       => current_user_can( 'be_manage_sessions' ),
				// Gates the System Config Translations tab (1.2.0 §6, B10).
				'be_manage_translations'   => current_user_can( 'be_manage_translations' ),
			],
		] );

		wp_enqueue_media();
		wp_enqueue_editor();
	}
}
