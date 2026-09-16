<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for House Rules - a live, front-end view of every
 * catalog item, tiered power level, or tiered power family carrying a
 * `description` note (Decision 094), grouped by schema block. Same shape as
 * `My_Plots`: a Content section exposing a Game Slug control, and a single
 * mount-point <div> the front-end script hydrates with the HouseRules React
 * component. The REST route behind it (`Reports_Controller::get_document()`)
 * enforces `be_view_reports`; this widget only places the mount point.
 *
 * @see BE_PROCESS/reference/DECISIONLOG.md Decision 094
 */
class House_Rules extends Widget_Base {

	public function get_name(): string {
		return 'be-house-rules';
	}

	public function get_title(): string {
		return __( 'House Rules', 'beyond-elysium' );
	}

	public function get_icon(): string {
		return 'eicon-post-list';
	}

	/**
	 * @return string[]
	 */
	public function get_categories(): array {
		return [ 'beyond-elysium' ];
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'content_section', [
			'label' => __( 'Content', 'beyond-elysium' ),
		] );

		$this->add_control( 'widget_description', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => __( 'Every house-rule note set on this chronicle\'s catalog.', 'beyond-elysium' ),
			'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
		] );

		$this->add_control( 'game_slug', [
			'label'       => __( 'Game Slug', 'beyond-elysium' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => '',
			'description' => __( 'The chronicle whose house rules this shows.', 'beyond-elysium' ),
		] );

		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$config = [ 'gameSlug' => $settings['game_slug'] ];

		printf(
			'<div data-be-widget="%s" data-be-config="%s"></div>',
			esc_attr( 'house-rules' ),
			esc_attr( (string) wp_json_encode( $config ) )
		);
	}
}
