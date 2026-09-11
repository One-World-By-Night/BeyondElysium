<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for the Plot Manager, the Storyteller dashboard
 * for browsing plots, managing connections, allocating actions, and
 * generating rumors. Registers the widget's name, title, icon, and category
 * with Elementor, exposes Content section controls for the target game slug
 * and a default status filter, and renders a single mount-point <div> that
 * the front-end script hydrates with the PlotManager React component.
 *
 * @see BE_PROCESS/workflow-0.5.md Step 7a
 */
class Plot_Manager extends Widget_Base {

	/**
	 * Returns the internal widget name Elementor uses to identify this
	 * widget type. Elementor stores this string in page and template
	 * markup wherever the widget is placed.
	 */
	public function get_name(): string {
		return 'be-plot-manager';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in
	 * the editor's widget panel, search results, and layers panel. This is
	 * the text an editor sees when placing the widget on a page.
	 */
	public function get_title(): string {
		return __( 'Plot Manager', 'beyond-elysium' );
	}

	/**
	 * Returns the Elementor icon class shown next to this widget's title in
	 * the widget panel. The value is an eicon-* class name supplied by
	 * Elementor's built-in icon font.
	 */
	public function get_icon(): string {
		return 'eicon-post-list';
	}

	/**
	 * Returns the Elementor category slugs this widget belongs to, which
	 * controls where it appears in the widget panel. Every Beyond Elysium
	 * widget belongs to the single "beyond-elysium" category that Init
	 * registers.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return [ 'beyond-elysium' ];
	}

	/**
	 * Builds the Elementor "Content" section shown in the editor panel for
	 * this widget. Adds a read-only description note, a Game Slug text
	 * control, and a Default Status Filter select that pre-filters the
	 * rendered dashboard to active, resolved, or archived plots.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'content_section', [
			'label' => __( 'Content', 'beyond-elysium' ),
		] );

		$this->add_control( 'widget_description', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => __( 'The Storyteller Toolkit for creating and running plots, actions, and rumors.', 'beyond-elysium' ),
			'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
		] );

		$this->add_control( 'game_slug', [
			'label'       => __( 'Game Slug', 'beyond-elysium' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => '',
			'description' => __( 'The chronicle whose plots this dashboard manages.', 'beyond-elysium' ),
		] );

		$this->add_control( 'default_status', [
			'label'   => __( 'Default Status Filter', 'beyond-elysium' ),
			'type'    => Controls_Manager::SELECT,
			'default' => '',
			'options' => [
				''          => __( 'All', 'beyond-elysium' ),
				'active'    => __( 'Active', 'beyond-elysium' ),
				'resolved'  => __( 'Resolved', 'beyond-elysium' ),
				'archived'  => __( 'Archived', 'beyond-elysium' ),
			],
		] );

		$this->end_controls_section();
	}

	/**
	 * Outputs the widget's front-end markup. Builds a configuration array
	 * from this widget's Elementor settings, adding a defaultStatus entry
	 * only when a filter was chosen, then prints a single empty <div>
	 * carrying the React mount-point attribute and the config as a
	 * JSON-encoded data attribute.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$config = [ 'gameSlug' => $settings['game_slug'] ];
		if ( ! empty( $settings['default_status'] ) ) {
			$config['defaultStatus'] = $settings['default_status'];
		}

		printf(
			'<div data-be-widget="%s" data-be-config="%s"></div>',
			esc_attr( 'plot-manager' ),
			esc_attr( (string) wp_json_encode( $config ) )
		);
	}
}
