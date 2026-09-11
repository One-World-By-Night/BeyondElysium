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
	 * Adds the top-level "Beyond Elysium" admin menu and its submenu pages:
	 * Games, Characters, NPC Roster, Schema Blocks, Creature Stacks,
	 * Templates, Plots, Query Tool, Items & Locations, Import, Chronicle
	 * Access, and Docs. Each submenu page is gated on its own capability
	 * and renders a mount point for the matching admin widget.
	 */
	public static function add_pages(): void {
		add_menu_page(
			__( 'Beyond Elysium', 'beyond-elysium' ),
			__( 'Beyond Elysium', 'beyond-elysium' ),
			'be_manage_games',
			'beyond-elysium',
			[ self::class, 'render_games' ],
			'dashicons-groups',
			30
		);

		add_submenu_page(
			'beyond-elysium',
			__( 'Games', 'beyond-elysium' ),
			__( 'Games', 'beyond-elysium' ),
			'be_manage_games',
			'beyond-elysium',
			[ self::class, 'render_games' ]
		);

		add_submenu_page(
			'beyond-elysium',
			__( 'Characters', 'beyond-elysium' ),
			__( 'Characters', 'beyond-elysium' ),
			'be_manage_characters',
			'beyond-elysium-characters',
			[ self::class, 'render_characters' ]
		);

		// NPC Roster is a separate page from the Characters page's inline NPC toggle.
		add_submenu_page(
			'beyond-elysium',
			__( 'NPC Roster', 'beyond-elysium' ),
			__( 'NPC Roster', 'beyond-elysium' ),
			'be_manage_characters',
			'beyond-elysium-npc-roster',
			[ self::class, 'render_npc_roster' ]
		);

		add_submenu_page(
			'beyond-elysium',
			__( 'Schema Blocks', 'beyond-elysium' ),
			__( 'Schema Blocks', 'beyond-elysium' ),
			'be_manage_schemas',
			'beyond-elysium-schema-blocks',
			[ self::class, 'render_schema_blocks' ]
		);

		add_submenu_page(
			'beyond-elysium',
			__( 'Creature Stacks', 'beyond-elysium' ),
			__( 'Creature Stacks', 'beyond-elysium' ),
			'be_manage_schemas',
			'beyond-elysium-creature-stacks',
			[ self::class, 'render_creature_stacks' ]
		);

		add_submenu_page(
			'beyond-elysium',
			__( 'Templates', 'beyond-elysium' ),
			__( 'Templates', 'beyond-elysium' ),
			'be_manage_templates',
			'beyond-elysium-templates',
			[ self::class, 'render_templates' ]
		);

		// Plots and Query Tool pages, each gated on its own capability.
		add_submenu_page(
			'beyond-elysium',
			__( 'Plots', 'beyond-elysium' ),
			__( 'Plots', 'beyond-elysium' ),
			'be_manage_plots',
			'beyond-elysium-plots',
			[ self::class, 'render_plots' ]
		);

		add_submenu_page(
			'beyond-elysium',
			__( 'Query Tool', 'beyond-elysium' ),
			__( 'Query Tool', 'beyond-elysium' ),
			'be_run_queries',
			'beyond-elysium-query',
			[ self::class, 'render_query' ]
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

		// One page with two tabs (character import and game-file import), gated on be_import.
		add_submenu_page(
			'beyond-elysium',
			__( 'Import', 'beyond-elysium' ),
			__( 'Import', 'beyond-elysium' ),
			'be_import',
			'beyond-elysium-import',
			[ self::class, 'render_import' ]
		);

		// Manages accessSchema settings, per-game role path, and chronicle membership.
		add_submenu_page(
			'beyond-elysium',
			__( 'Chronicle Access', 'beyond-elysium' ),
			__( 'Chronicle Access', 'beyond-elysium' ),
			'be_manage_games',
			'beyond-elysium-chronicle-access',
			[ self::class, 'render_chronicle_access' ]
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

		// Create/edit/delete approval overrides on a chronicle's own trait_list items and tiered_power powers/levels.
		add_submenu_page(
			'beyond-elysium',
			__( 'Approval Rules', 'beyond-elysium' ),
			__( 'Approval Rules', 'beyond-elysium' ),
			'be_manage_approval_rules',
			'beyond-elysium-approval-rules',
			[ self::class, 'render_approval_rules' ]
		);
	}

	/**
	 * Renders the Games admin page. Outputs the mount point for the
	 * admin-games widget, which lists and manages this plugin's games,
	 * followed by the shared memorial footer.
	 */
	public static function render_games(): void {
		self::render_mount( 'admin-games' );
	}

	/**
	 * Renders the Characters admin page. Outputs the mount point for the
	 * admin-characters widget, which lists and manages player characters,
	 * followed by the shared memorial footer.
	 */
	public static function render_characters(): void {
		self::render_mount( 'admin-characters' );
	}

	/**
	 * Renders the NPC Roster admin page. Outputs the mount point for the
	 * admin-npc-roster widget, which lists and manages NPC characters,
	 * followed by the shared memorial footer.
	 */
	public static function render_npc_roster(): void {
		self::render_mount( 'admin-npc-roster' );
	}

	/**
	 * Renders the Schema Blocks admin page. Outputs the mount point for
	 * the admin-schema-blocks widget, which manages schema block
	 * definitions, followed by the shared memorial footer.
	 */
	public static function render_schema_blocks(): void {
		self::render_mount( 'admin-schema-blocks' );
	}

	/**
	 * Renders the Creature Stacks admin page. Outputs the mount point for
	 * the admin-creature-stacks widget, which manages creature stack
	 * definitions, followed by the shared memorial footer.
	 */
	public static function render_creature_stacks(): void {
		self::render_mount( 'admin-creature-stacks' );
	}

	/**
	 * Renders the Templates admin page. Outputs the mount point for the
	 * admin-templates widget, which manages character sheet templates,
	 * followed by the shared memorial footer.
	 */
	public static function render_templates(): void {
		self::render_mount( 'admin-templates' );
	}

	/**
	 * Renders the Plots admin page. Outputs the mount point for the
	 * admin-plots widget, which manages plots and rumors, followed by the
	 * shared memorial footer.
	 */
	public static function render_plots(): void {
		self::render_mount( 'admin-plots' );
	}

	/**
	 * Renders the Query Tool admin page. Outputs the mount point for the
	 * admin-query widget, which runs ad hoc queries across game data,
	 * followed by the shared memorial footer.
	 */
	public static function render_query(): void {
		self::render_mount( 'admin-query' );
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
	 * Renders the Import admin page. Outputs the mount point for the
	 * admin-import widget, which imports character and game exchange
	 * files, followed by the shared memorial footer.
	 */
	public static function render_import(): void {
		self::render_mount( 'admin-import' );
	}

	/**
	 * Renders the Chronicle Access admin page. Outputs the mount point for
	 * the admin-chronicle-access widget, which manages accessSchema
	 * settings and chronicle membership, followed by the shared memorial
	 * footer.
	 */
	public static function render_chronicle_access(): void {
		self::render_mount( 'admin-chronicle-access' );
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
	 * Renders the Approval Rules admin page. Outputs the mount point for
	 * the admin-approval-rules widget, which lets a Storyteller create,
	 * edit, and delete approval overrides for a chronicle, followed by the
	 * shared memorial footer.
	 */
	public static function render_approval_rules(): void {
		self::render_mount( 'admin-approval-rules' );
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
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'version' => BE_VERSION,
			'capabilities' => [
				'be_manage_plots'       => current_user_can( 'be_manage_plots' ),
				'be_manage_characters'  => current_user_can( 'be_manage_characters' ),
				// Gates the import wizard's "also add to catalog" checkbox.
				'be_manage_schemas'     => current_user_can( 'be_manage_schemas' ),
				// Gates whether WorldObjectCard.tsx shows the full connection manager or a read-only list.
				'be_manage_connections' => current_user_can( 'be_manage_connections' ),
				// Gates AdminImport.tsx's two tabs independently.
				'be_import'             => current_user_can( 'be_import' ),
				'be_manage_games'       => current_user_can( 'be_manage_games' ),
			],
		] );

		wp_enqueue_media();
		wp_enqueue_editor();
	}
}
