<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for the Character Editor. Registers the widget's
 * name, title, icon, and category with Elementor, exposes Content section
 * controls for the target game slug, an optional character ID, and an
 * optional creature stack slug, and renders a single mount-point <div> that
 * the front-end script hydrates with the CharacterEditor React component.
 * With no character ID set, the widget falls back to a character_id URL
 * query var, and with neither present it renders in create mode.
 *
 * @see BE_PROCESS/workflow-0.4.md Step 7a
 */
class Character_Editor extends Widget_Base {

	/**
	 * Returns the internal widget name Elementor uses to identify this
	 * widget type. Elementor stores this string in page and template
	 * markup wherever the widget is placed.
	 */
	public function get_name(): string {
		return 'be-character-editor';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in
	 * the editor's widget panel, search results, and layers panel. This is
	 * the text an editor sees when placing the widget on a page.
	 */
	public function get_title(): string {
		return __( 'Character Editor', 'beyond-elysium' );
	}

	/**
	 * Returns the Elementor icon class shown next to this widget's title in
	 * the widget panel. The value is an eicon-* class name supplied by
	 * Elementor's built-in icon font.
	 */
	public function get_icon(): string {
		return 'eicon-form-horizontal';
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
	 * control, a Character ID number control, and a Creature Stack Slug
	 * text control that pins the create-mode form to one creature type.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'content_section', [
			'label' => __( 'Content', 'beyond-elysium' ),
		] );

		$this->add_control( 'widget_description', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => __( 'The player/Storyteller-facing form for creating and editing a character sheet.', 'beyond-elysium' ),
			'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
		] );

		$this->add_control( 'game_slug', [
			'label'       => __( 'Game Slug', 'beyond-elysium' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => '',
			'description' => __( 'The chronicle this editor belongs to.', 'beyond-elysium' ),
		] );

		$this->add_control( 'character_id', [
			'label'       => __( 'Character ID', 'beyond-elysium' ),
			'type'        => Controls_Manager::NUMBER,
			'default'     => 0,
			'description' => __(
				'0 reads the character_id URL query var instead. Still 0 after that = create mode.',
				'beyond-elysium'
			),
		] );

		$this->add_control( 'stack_slug', [
			'label'       => __( 'Creature Stack Slug', 'beyond-elysium' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => '',
			'description' => __(
				'Create mode only. Leave blank to let the player pick a creature type.',
				'beyond-elysium'
			),
		] );

		$this->end_controls_section();
	}

	/**
	 * Outputs the widget's front-end markup. Resolves the character ID from
	 * either the widget setting or a character_id URL query var, builds a
	 * configuration array from that ID plus the widget's other settings,
	 * then prints a single empty <div> carrying the React mount-point
	 * attribute and the config as a JSON-encoded data attribute.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$character_id = (int) $settings['character_id'];
		if ( $character_id === 0 && isset( $_GET['character_id'] ) ) {
			$character_id = absint( wp_unslash( $_GET['character_id'] ) );
		}

		$config = [
			'characterId' => $character_id,
			'gameSlug'    => $settings['game_slug'],
			'stackSlug'   => $settings['stack_slug'],
		];

		printf(
			'<div data-be-widget="%s" data-be-config="%s"></div>',
			esc_attr( 'character-editor' ),
			esc_attr( (string) wp_json_encode( $config ) )
		);
	}
}
