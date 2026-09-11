<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for the Query Tool, the Storyteller search and
 * statistics tool for a chronicle. Registers the widget's name, title, icon,
 * and category with Elementor, exposes a Content section control for the
 * target game slug, and renders a single mount-point <div> that the
 * front-end script hydrates with the QueryTool React component. The REST
 * routes behind that component enforce their own capability checks; this
 * widget only places the mount point on the page.
 *
 * @see BE_PROCESS/workflow-0.6.md Step 8g
 */
class Query_Tool extends Widget_Base {

	/**
	 * Returns the internal widget name Elementor uses to identify this
	 * widget type. Elementor stores this string in page and template
	 * markup wherever the widget is placed.
	 */
	public function get_name(): string {
		return 'be-query-tool';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in
	 * the editor's widget panel, search results, and layers panel. This is
	 * the text an editor sees when placing the widget on a page.
	 */
	public function get_title(): string {
		return __( 'Query Tool', 'beyond-elysium' );
	}

	/**
	 * Returns the Elementor icon class shown next to this widget's title in
	 * the widget panel. The value is an eicon-* class name supplied by
	 * Elementor's built-in icon font.
	 */
	public function get_icon(): string {
		return 'eicon-search';
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
	 * this widget. Adds a read-only description note and a Game Slug text
	 * control that determines which chronicle the rendered tool builds and
	 * runs queries against.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'content_section', [
			'label' => __( 'Content', 'beyond-elysium' ),
		] );

		$this->add_control( 'widget_description', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => __( 'Builds and runs saved queries against this chronicle, plus roster statistics.', 'beyond-elysium' ),
			'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
		] );

		$this->add_control( 'game_slug', [
			'label'       => __( 'Game Slug', 'beyond-elysium' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => '',
			'description' => __( 'The chronicle this search tool queries.', 'beyond-elysium' ),
		] );

		$this->end_controls_section();
	}

	/**
	 * Outputs the widget's front-end markup. Builds a configuration array
	 * from this widget's Elementor settings, then prints a single empty
	 * <div> carrying the React mount-point attribute and the config as a
	 * JSON-encoded data attribute for the front-end bundle to read.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$config = [ 'gameSlug' => $settings['game_slug'] ];

		printf(
			'<div data-be-widget="%s" data-be-config="%s"></div>',
			esc_attr( 'query-tool' ),
			esc_attr( (string) wp_json_encode( $config ) )
		);
	}
}
