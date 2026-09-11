<?php

namespace BeyondElysium\Elementor\Widgets;

use BeyondElysium\Models\Creature_Stack;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for the Character List. Registers the widget's
 * name, title, icon, and category with Elementor, exposes Content section
 * controls for the target game slug, creature type and status filters, an
 * optional sheet page URL, and a results-per-page setting, and renders a
 * single mount-point <div> that the front-end script hydrates with the
 * CharacterList React component.
 *
 * @see BE_PROCESS/workflow-0.3.md Step 7d
 */
class Character_List extends Widget_Base {

	/**
	 * Returns the internal widget name Elementor uses to identify this
	 * widget type. Elementor stores this string in page and template
	 * markup wherever the widget is placed.
	 */
	public function get_name(): string {
		return 'be-character-list';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in
	 * the editor's widget panel, search results, and layers panel. This is
	 * the text an editor sees when placing the widget on a page.
	 */
	public function get_title(): string {
		return __( 'Character List', 'beyond-elysium' );
	}

	/**
	 * Returns the Elementor icon class shown next to this widget's title in
	 * the widget panel. The value is an eicon-* class name supplied by
	 * Elementor's built-in icon font.
	 */
	public function get_icon(): string {
		return 'eicon-table-of-contents';
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
	 * control, a Creature Type select populated from the registered
	 * creature stacks, a Default Status Filter select, a Sheet Page URL
	 * control used to link character names, and a Per Page number control.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'content_section', [
			'label' => __( 'Content', 'beyond-elysium' ),
		] );

		$this->add_control( 'widget_description', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => __( 'The character roster for this chronicle, with search and filtering.', 'beyond-elysium' ),
			'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
		] );

		$this->add_control( 'game_slug', [
			'label'   => __( 'Game Slug', 'beyond-elysium' ),
			'type'    => Controls_Manager::TEXT,
			'default' => '',
		] );

		$stack_options = [ '' => __( 'All types', 'beyond-elysium' ) ];
		foreach ( Creature_Stack::all() as $stack ) {
			$stack_options[ $stack->slug ] = $stack->name;
		}

		$this->add_control( 'stack_slug', [
			'label'   => __( 'Creature Type', 'beyond-elysium' ),
			'type'    => Controls_Manager::SELECT,
			'default' => '',
			'options' => $stack_options,
		] );

		$this->add_control( 'default_status', [
			'label'   => __( 'Default Status Filter', 'beyond-elysium' ),
			'type'    => Controls_Manager::SELECT,
			'default' => '',
			'options' => [
				''         => __( 'All statuses', 'beyond-elysium' ),
				'active'   => __( 'Active', 'beyond-elysium' ),
				'inactive' => __( 'Inactive', 'beyond-elysium' ),
				'retired'  => __( 'Retired', 'beyond-elysium' ),
				'dead'     => __( 'Dead', 'beyond-elysium' ),
			],
		] );

		$this->add_control( 'sheet_page_url', [
			'label'       => __( 'Sheet Page URL', 'beyond-elysium' ),
			'type'        => Controls_Manager::URL,
			'default'     => [ 'url' => '' ],
			'description' => __( 'Page that renders the Character Sheet widget. A chronicle may put sheets on a different page than the roster; left blank, names render as plain text.', 'beyond-elysium' ),
		] );

		$this->add_control( 'per_page', [
			'label'   => __( 'Per Page', 'beyond-elysium' ),
			'type'    => Controls_Manager::NUMBER,
			'default' => 20,
			'min'     => 1,
			'max'     => 100,
		] );

		$this->end_controls_section();
	}

	/**
	 * Outputs the widget's front-end markup. Builds a configuration array
	 * from this widget's Elementor settings, including the roster's
	 * default filters and page size, then prints a single empty <div>
	 * carrying the React mount-point attribute and the config as a
	 * JSON-encoded data attribute.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$config = [
			'gameSlug'     => $settings['game_slug'],
			'stackSlug'    => $settings['stack_slug'],
			'status'       => $settings['default_status'],
			'sheetPageUrl' => $settings['sheet_page_url']['url'] ?? '',
			'perPage'      => (int) $settings['per_page'],
		];

		printf(
			'<div data-be-widget="%s" data-be-config="%s"></div>',
			esc_attr( 'character-list' ),
			esc_attr( (string) wp_json_encode( $config ) )
		);
	}
}
