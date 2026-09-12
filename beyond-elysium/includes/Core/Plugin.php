<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap. Wires up every other Core component on plugins_loaded
 * and init: asset enqueuing, the Elementor integration, admin menu, page
 * provisioning, print canvas, and chronicle sync. The central place every
 * other component is hooked in from.
 */
class Plugin {

	/**
	 * Runs on plugins_loaded. Loads the plugin's text domain, enqueues
	 * assets, and registers every other Core component (Elementor init,
	 * user settings, health notice, admin menu, page provisioner, print
	 * canvas, chronicle sync) plus the deleted_user and init hooks used
	 * for member cleanup and deferred schema upgrades.
	 */
	public static function init(): void {
		// Loads the beyond-elysium text domain for translation strings.
		load_plugin_textdomain( 'beyond-elysium', false, dirname( plugin_basename( BE_PLUGIN_FILE ) ) . '/languages' );

		self::enqueue_assets();
		\BeyondElysium\Core\Elementor_Requirement::register();
		\BeyondElysium\Elementor\Init::register();
		User_Settings::register();
		Health_Notice::register();
		Admin_Menu::register();
		Page_Provisioner::register();
		Print_Canvas::register();
		Chronicle_Sync::register();

		// Cleans up be_game_members rows when a user is deleted, single-site or multisite.
		add_action( 'deleted_user', [ '\BeyondElysium\Models\Game_Member', 'remove_user_everywhere' ] );

		// Runs Schema::maybe_upgrade() on init, once the rewrite system is available.
		add_action( 'init', [ '\BeyondElysium\Database\Schema', 'maybe_upgrade' ] );
	}

	/**
	 * Hooks enqueue_frontend() onto wp_enqueue_scripts and
	 * Admin_Menu::enqueue_assets() onto admin_enqueue_scripts, so the
	 * front-end and admin asset bundles load on their respective sides.
	 */
	private static function enqueue_assets(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_frontend' ] );
		add_action( 'admin_enqueue_scripts', [ Admin_Menu::class, 'enqueue_assets' ] );
	}

	/**
	 * Enqueues the front-end React bundle, its translations, and its
	 * stylesheet unconditionally on every page. Localizes REST connection
	 * details and capability flags for the bundle, then enqueues the
	 * media and editor scripts the character editor and sheet customizer
	 * depend on.
	 */
	public static function enqueue_frontend(): void {
		$asset_file = BE_PLUGIN_DIR . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'beyond-elysium',
			BE_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Registers translations under the 'beyond-elysium' script handle.
		wp_set_script_translations( 'beyond-elysium', 'beyond-elysium', BE_PLUGIN_DIR . 'languages' );

		wp_enqueue_style(
			'beyond-elysium',
			BE_PLUGIN_URL . 'build/index.css',
			[],
			$asset['version']
		);

		wp_localize_script( 'beyond-elysium', 'beyondElysium', [
			'restUrl'  => rest_url( 'be/v1/' ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'version'  => BE_VERSION,
			// UI affordance only; every REST route enforces its own capability check server-side.
			'capabilities' => [
				'be_manage_plots'       => current_user_can( 'be_manage_plots' ),
				'be_manage_characters'  => current_user_can( 'be_manage_characters' ),
				// Gates the import wizard's "also add to catalog" checkbox.
				'be_manage_schemas'     => current_user_can( 'be_manage_schemas' ),
				// Gates whether WorldObjectCard.tsx shows the full connection manager or a read-only list.
				'be_manage_connections' => current_user_can( 'be_manage_connections' ),
				// Gates BoonLedger.tsx's record/repay controls (the `boons` chronicle role).
				'be_manage_boons'       => current_user_can( 'be_manage_boons' ),
			],
		] );

		// Backs the sheet customizer's image picker (wp.media()).
		wp_enqueue_media();

		// Backs the character editor's rich-text Background and Notes fields (TinyMCE).
		wp_enqueue_editor();
	}

	/**
	 * Instantiates every REST controller this plugin defines and calls
	 * register_routes() on each. Hooked onto rest_api_init from the
	 * plugin's main file, beyond-elysium.php.
	 */
	public static function register_rest_routes(): void {
		$controllers = [
			new \BeyondElysium\REST\Games_Controller(),
			new \BeyondElysium\REST\Schema_Blocks_Controller(),
			new \BeyondElysium\REST\Creature_Stacks_Controller(),
			new \BeyondElysium\REST\Characters_Controller(),
			new \BeyondElysium\REST\Changes_Controller(),
			new \BeyondElysium\REST\Snapshots_Controller(),
			new \BeyondElysium\REST\Sheet_Style_Controller(),
			new \BeyondElysium\REST\Experience_Controller(),
			new \BeyondElysium\REST\Query_Fields_Controller(),
			new \BeyondElysium\REST\Templates_Controller(),
			new \BeyondElysium\REST\Plots_Controller(),
			new \BeyondElysium\REST\Entries_Controller(),
			new \BeyondElysium\REST\Connections_Controller(),
			new \BeyondElysium\REST\Query_Controller(),
			new \BeyondElysium\REST\World_Objects_Controller(),
			new \BeyondElysium\REST\Boons_Controller(),
			new \BeyondElysium\REST\Import_Controller(),
			new \BeyondElysium\REST\Game_Import_Controller(),
			new \BeyondElysium\REST\Game_Members_Controller(),
			new \BeyondElysium\REST\Authorization_Settings_Controller(),
			new \BeyondElysium\REST\Game_Stats_Controller(),
			new \BeyondElysium\REST\Docs_Controller(),
			new \BeyondElysium\REST\Credits_Controller(),
			new \BeyondElysium\REST\Approval_Rules_Controller(),
			new \BeyondElysium\REST\Data_Management_Controller(),
			new \BeyondElysium\REST\Apr_Controller(),
		];
		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
