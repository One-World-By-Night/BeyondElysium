<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for the Game Dashboard, a chronicle's landing page.
 */
class Game_Dashboard extends Base_Widget {

	/**
	 * Returns the internal widget name Elementor uses to identify this widget type.
	 */
	public function get_name(): string {
		return 'be-game-dashboard';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in the editor's widget panel, search results, and
	 * layers panel.
	 */
	public function get_title(): string {
		return __( 'Game Dashboard', 'beyond-elysium' );
	}

	/**
	 * Returns the Elementor icon class shown next to this widget's title in the widget panel.
	 */
	public function get_icon(): string {
		return 'eicon-dashboard';
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
			'raw'             => __( 'The landing page for a chronicle. Storytellers see aggregate stats and recent activity; players see their own characters, pending changes, and plots.', 'beyond-elysium' ),
			'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
		] );

		$this->add_control( 'game_slug', [
			'label'       => __( 'Game Slug', 'beyond-elysium' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => '',
			'description' => __( 'The chronicle this dashboard summarizes.', 'beyond-elysium' ),
		] );

		$this->add_control( 'sheet_page_url', [
			'label'       => __( 'Sheet Page URL', 'beyond-elysium' ),
			'type'        => Controls_Manager::URL,
			'default'     => [ 'url' => '' ],
			'description' => __( 'Page that renders the Character Sheet widget. Left blank, the player view lists character names as plain text.', 'beyond-elysium' ),
		] );

		$this->add_control( 'approval_queue_url', [
			'label'       => __( 'Approval Queue Page URL', 'beyond-elysium' ),
			'type'        => Controls_Manager::URL,
			'default'     => [ 'url' => '' ],
			'description' => __( 'Storyteller quick link. Left blank, the link is omitted.', 'beyond-elysium' ),
		] );

		$this->add_control( 'roster_url', [
			'label'       => __( 'Roster Page URL', 'beyond-elysium' ),
			'type'        => Controls_Manager::URL,
			'default'     => [ 'url' => '' ],
			'description' => __( 'Storyteller quick link. Left blank, the link is omitted.', 'beyond-elysium' ),
		] );

		$this->add_control( 'plots_url', [
			'label'       => __( 'Plots Page URL', 'beyond-elysium' ),
			'type'        => Controls_Manager::URL,
			'default'     => [ 'url' => '' ],
			'description' => __( 'Storyteller quick link. Left blank, the link is omitted.', 'beyond-elysium' ),
		] );

		$this->end_controls_section();
	}

	protected function widget_slug(): string {
		return 'game-dashboard';
	}

	protected function widget_config( array $settings ): array {
		return [
			'gameSlug'         => $settings['game_slug'],
			'sheetPageUrl'     => $settings['sheet_page_url']['url'] ?? '',
			'approvalQueueUrl' => $settings['approval_queue_url']['url'] ?? '',
			'rosterUrl'        => $settings['roster_url']['url'] ?? '',
			'plotsUrl'         => $settings['plots_url']['url'] ?? '',
		];
	}
}
