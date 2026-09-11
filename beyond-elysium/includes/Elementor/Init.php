<?php

namespace BeyondElysium\Elementor;

use BeyondElysium\Elementor\Widgets\Character_List;
use BeyondElysium\Elementor\Widgets\Character_Sheet;
use BeyondElysium\Elementor\Widgets\Character_Editor;
use BeyondElysium\Elementor\Widgets\Approval_Queue;
use BeyondElysium\Elementor\Widgets\Plot_Manager;
use BeyondElysium\Elementor\Widgets\My_Plots;
use BeyondElysium\Elementor\Widgets\Query_Tool;
use BeyondElysium\Elementor\Widgets\World_Objects;
use BeyondElysium\Elementor\Widgets\Boon_Ledger;
use BeyondElysium\Elementor\Widgets\Import_Tool;
use BeyondElysium\Elementor\Widgets\Game_Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Beyond Elysium Elementor category and every Elementor widget
 * this plugin defines. Hooks its registration callbacks onto Elementor's
 * `elements/categories_registered` and `widgets/register` actions, and each
 * callback is a no-op on any site where Elementor is not installed or active.
 *
 * @see BE_PROCESS/workflow-0.3.md Step 7
 */
class Init {

	/**
	 * Registers the WordPress action hooks that add the Beyond Elysium
	 * Elementor category and register this plugin's widgets. Safe to call
	 * unconditionally on every request; the hooked callbacks check for
	 * Elementor themselves and do nothing when it is absent.
	 */
	public static function register(): void {
		add_action( 'elementor/elements/categories_registered', [ self::class, 'register_category' ] );
		add_action( 'elementor/widgets/register', [ self::class, 'register_widgets' ] );
	}

	/**
	 * Adds the "Beyond Elysium" category to Elementor's list of element
	 * categories so this plugin's widgets can be grouped together in the
	 * editor's widget panel. Does nothing if Elementor has not finished
	 * loading.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager
	 */
	public static function register_category( $elements_manager ): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		$elements_manager->add_category( 'beyond-elysium', [
			'title' => __( 'Beyond Elysium', 'beyond-elysium' ),
			'icon'  => 'fa fa-plug',
		] );
	}

	/**
	 * Registers each of this plugin's widget classes with Elementor's widget
	 * manager, making them available for placement in the Elementor editor.
	 * Does nothing if Elementor has not finished loading.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager
	 */
	public static function register_widgets( $widgets_manager ): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		$widgets_manager->register( new Character_Sheet() );
		$widgets_manager->register( new Character_List() );
		$widgets_manager->register( new Character_Editor() );
		$widgets_manager->register( new Approval_Queue() );
		$widgets_manager->register( new Plot_Manager() );
		$widgets_manager->register( new My_Plots() );
		$widgets_manager->register( new Query_Tool() );
		$widgets_manager->register( new World_Objects() );
		$widgets_manager->register( new Boon_Ledger() );
		$widgets_manager->register( new Import_Tool() );
		$widgets_manager->register( new Game_Dashboard() );
	}
}
