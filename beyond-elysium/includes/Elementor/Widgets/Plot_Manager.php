<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for the Plot Manager, the Storyteller dashboard for browsing plots, managing connections,
 * allocating actions, and generating rumors.
 */
class Plot_Manager extends Base_Widget {

	/**
	 * Returns the internal widget name Elementor uses to identify this widget type.
	 */
	public function get_name(): string {
		return 'be-plot-manager';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in the editor's widget panel, search results, and
	 * layers panel.
	 */
	public function get_title(): string {
		return __( 'Plot Manager', 'beyond-elysium' );
	}

	/**
	 * Returns the Elementor icon class shown next to this widget's title in the widget panel.
	 */
	public function get_icon(): string {
		return 'eicon-post-list';
	}

	/**
	 * Builds the Elementor "Content" section shown in the editor panel for this widget.
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

	protected function widget_slug(): string {
		return 'plot-manager';
	}

	/**
	 * Adds a defaultStatus entry only when a filter was chosen.
	 */
	protected function widget_config( array $settings ): array {
		$config = [ 'gameSlug' => $settings['game_slug'] ];
		if ( ! empty( $settings['default_status'] ) ) {
			$config['defaultStatus'] = $settings['default_status'];
		}
		return $config;
	}
}
