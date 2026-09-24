<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for My Plots, a player's cross-character feed of plots and rumors.
 */
class My_Plots extends Base_Widget {

	/**
	 * Returns the internal widget name Elementor uses to identify this widget type.
	 */
	public function get_name(): string {
		return 'be-my-plots';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in the editor's widget panel, search results, and
	 * layers panel.
	 */
	public function get_title(): string {
		return __( 'My Plots', 'beyond-elysium' );
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
			'raw'             => __( 'A feed of the plots and rumors a player is connected to.', 'beyond-elysium' ),
			'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
		] );

		$this->add_control( 'game_slug', [
			'label'       => __( 'Game Slug', 'beyond-elysium' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => '',
			'description' => __( 'The chronicle whose plots this feed shows.', 'beyond-elysium' ),
		] );

		$this->end_controls_section();
	}

	protected function widget_slug(): string {
		return 'my-plots';
	}

	protected function widget_config( array $settings ): array {
		return [ 'gameSlug' => $settings['game_slug'] ];
	}
}
