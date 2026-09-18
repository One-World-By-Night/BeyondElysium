<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for the Approval Queue. Registers the widget's
 * name, title, and icon with Elementor, exposes a Content section
 * with a Game Slug control, and renders a single mount-point <div> that the
 * front-end script hydrates with the ApprovalQueue React component. The REST
 * route behind that component enforces its own capability checks; this
 * widget only places the mount point on the page.
 *
 * @see BE_PROCESS/releases/workflow-0.4.md Step 7b
 */
class Approval_Queue extends Base_Widget {

	/**
	 * Returns the internal widget name Elementor uses to identify this
	 * widget type. Elementor stores this string in page and template
	 * markup wherever the widget is placed.
	 */
	public function get_name(): string {
		return 'be-approval-queue';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in
	 * the editor's widget panel, search results, and layers panel. This is
	 * the text an editor sees when placing the widget on a page.
	 */
	public function get_title(): string {
		return __( 'Approval Queue', 'beyond-elysium' );
	}

	/**
	 * Returns the Elementor icon class shown next to this widget's title in
	 * the widget panel. The value is an eicon-* class name supplied by
	 * Elementor's built-in icon font.
	 */
	public function get_icon(): string {
		return 'eicon-check-circle-o';
	}

	/**
	 * Builds the Elementor "Content" section shown in the editor panel for
	 * this widget. Adds a read-only description note and a Game Slug text
	 * control that determines which chronicle's pending changes the
	 * rendered queue reviews.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'content_section', [
			'label' => __( 'Content', 'beyond-elysium' ),
		] );

		$this->add_control( 'widget_description', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => __( 'Pending player changes for Storytellers to approve or reject, across every character in the chronicle.', 'beyond-elysium' ),
			'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
		] );

		$this->add_control( 'game_slug', [
			'label'       => __( 'Game Slug', 'beyond-elysium' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => '',
			'description' => __( 'The chronicle whose pending changes this queue reviews.', 'beyond-elysium' ),
		] );

		$this->end_controls_section();
	}

	protected function widget_slug(): string {
		return 'approval-queue';
	}

	protected function widget_config( array $settings ): array {
		return [
			'gameSlug' => $settings['game_slug'],
		];
	}
}
