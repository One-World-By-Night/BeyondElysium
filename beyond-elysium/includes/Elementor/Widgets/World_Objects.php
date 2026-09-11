<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for World Objects, the item/location/rote catalog
 * with an optional create/edit form. Registers the widget's name, title,
 * icon, and category with Elementor, exposes Content section controls for
 * the target game slug, the default type tab, and whether editor controls
 * are shown, and renders a single mount-point <div> that the front-end
 * script hydrates with the WorldObjectManager React component.
 *
 * @see BE_PROCESS/workflow-0.7.md Step 5a
 */
class World_Objects extends Widget_Base {

	/**
	 * Returns the internal widget name Elementor uses to identify this
	 * widget type. Elementor stores this string in page and template
	 * markup wherever the widget is placed.
	 */
	public function get_name(): string {
		return 'be-world-objects';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in
	 * the editor's widget panel, search results, and layers panel. This is
	 * the text an editor sees when placing the widget on a page.
	 */
	public function get_title(): string {
		return __( 'World Objects', 'beyond-elysium' );
	}

	/**
	 * Returns the Elementor icon class shown next to this widget's title in
	 * the widget panel. The value is an eicon-* class name supplied by
	 * Elementor's built-in icon font.
	 */
	public function get_icon(): string {
		return 'eicon-product-images';
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
	 * control, a Default Type select that chooses which catalog tab opens
	 * first, and a switcher that shows or hides the create/edit controls.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'content_section', [
			'label' => __( 'Content', 'beyond-elysium' ),
		] );

		$this->add_control( 'widget_description', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => __( 'Manages items, locations, rotes, and boons for the chronicle.', 'beyond-elysium' ),
			'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
		] );

		$this->add_control( 'game_slug', [
			'label'       => __( 'Game Slug', 'beyond-elysium' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => '',
			'description' => __( 'The chronicle whose world objects this catalog shows.', 'beyond-elysium' ),
		] );

		$this->add_control( 'default_type', [
			'label'   => __( 'Default Type', 'beyond-elysium' ),
			'type'    => Controls_Manager::SELECT,
			'default' => 'item',
			'options' => [
				'item'     => __( 'Items', 'beyond-elysium' ),
				'location' => __( 'Locations', 'beyond-elysium' ),
				'rote'     => __( 'Rotes', 'beyond-elysium' ),
			],
		] );

		$this->add_control( 'show_editor', [
			'label'        => __( 'Show Create/Edit Controls', 'beyond-elysium' ),
			'type'         => Controls_Manager::SWITCHER,
			'label_on'     => __( 'Yes', 'beyond-elysium' ),
			'label_off'    => __( 'No', 'beyond-elysium' ),
			'return_value' => 'yes',
			'default'      => '',
			'description'  => __( 'The REST layer still enforces permissions regardless - this only hides controls a player would see rejected anyway.', 'beyond-elysium' ),
		] );

		$this->end_controls_section();
	}

	/**
	 * Outputs the widget's front-end markup. Builds a configuration array
	 * from this widget's Elementor settings, defaulting the type to "item"
	 * and normalizing the editor-visibility switcher to a boolean, then
	 * prints a single empty <div> carrying the React mount-point attribute
	 * and the config as a JSON-encoded data attribute.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$config = [
			'gameSlug'    => $settings['game_slug'],
			'defaultType' => $settings['default_type'] ?: 'item',
			'showEditor'  => $settings['show_editor'] === 'yes',
		];

		printf(
			'<div data-be-widget="%s" data-be-config="%s"></div>',
			esc_attr( 'world-objects' ),
			esc_attr( (string) wp_json_encode( $config ) )
		);
	}
}
