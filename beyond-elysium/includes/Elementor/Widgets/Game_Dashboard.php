<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for the Game Dashboard, a chronicle's landing
 * page. Registers the widget's name, title, icon, and category with
 * Elementor, exposes Content section controls for the target game slug and
 * several optional quick-link page URLs, and renders a single mount-point
 * <div> that the front-end script hydrates with the GameDashboard React
 * component. The component itself picks between a Storyteller aggregate
 * view and a player-facing view based on the viewer's own capabilities;
 * every underlying route enforces its own capability checks server-side.
 *
 * @see BE_PROCESS/workflow-0.9.md Step 7c
 */
class Game_Dashboard extends Widget_Base {

	/**
	 * Returns the internal widget name Elementor uses to identify this
	 * widget type. Elementor stores this string in page and template
	 * markup wherever the widget is placed.
	 */
	public function get_name(): string {
		return 'be-game-dashboard';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in
	 * the editor's widget panel, search results, and layers panel. This is
	 * the text an editor sees when placing the widget on a page.
	 */
	public function get_title(): string {
		return __( 'Game Dashboard', 'beyond-elysium' );
	}

	/**
	 * Returns the Elementor icon class shown next to this widget's title in
	 * the widget panel. The value is an eicon-* class name supplied by
	 * Elementor's built-in icon font.
	 */
	public function get_icon(): string {
		return 'eicon-dashboard';
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
	 * control, and optional URL controls for the sheet page plus the
	 * Storyteller quick links to the approval queue, roster, and plots
	 * pages.
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

	/**
	 * Outputs the widget's front-end markup. Builds a configuration array
	 * from this widget's Elementor settings, resolving each optional URL
	 * control to an empty string when unset, then prints a single empty
	 * <div> carrying the React mount-point attribute and the config as a
	 * JSON-encoded data attribute.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$config = [
			'gameSlug'         => $settings['game_slug'],
			'sheetPageUrl'     => $settings['sheet_page_url']['url'] ?? '',
			'approvalQueueUrl' => $settings['approval_queue_url']['url'] ?? '',
			'rosterUrl'        => $settings['roster_url']['url'] ?? '',
			'plotsUrl'         => $settings['plots_url']['url'] ?? '',
		];

		printf(
			'<div data-be-widget="%s" data-be-config="%s"></div>',
			esc_attr( 'game-dashboard' ),
			esc_attr( (string) wp_json_encode( $config ) )
		);
	}
}
