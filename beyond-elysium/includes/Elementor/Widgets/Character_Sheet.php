<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for the Character Sheet. Registers the widget's
 * name, title, and icon with Elementor, exposes Content section
 * controls for the target game slug, an optional character ID, and a sheet
 * template type, and renders a single mount-point <div> that the front-end
 * script hydrates with the CharacterSheet React component. With no character
 * ID set, the widget falls back to a character_id URL query var.
 *
 * @see BE_PROCESS/releases/workflow-0.3.md Step 7c
 */
class Character_Sheet extends Base_Widget {

	/**
	 * Returns the internal widget name Elementor uses to identify this
	 * widget type. Elementor stores this string in page and template
	 * markup wherever the widget is placed.
	 */
	public function get_name(): string {
		return 'be-character-sheet';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in
	 * the editor's widget panel, search results, and layers panel. This is
	 * the text an editor sees when placing the widget on a page.
	 */
	public function get_title(): string {
		return __( 'Character Sheet', 'beyond-elysium' );
	}

	/**
	 * Returns the Elementor icon class shown next to this widget's title in
	 * the widget panel. The value is an eicon-* class name supplied by
	 * Elementor's built-in icon font.
	 */
	public function get_icon(): string {
		return 'eicon-post-list';
	}

	/**
	 * Builds the Elementor "Content" section shown in the editor panel for
	 * this widget. Adds a read-only description note, a Game Slug text
	 * control, a Character ID number control, and a Template Type select
	 * that chooses between the full, compact, and mobile sheet layouts.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'content_section', [
			'label' => __( 'Content', 'beyond-elysium' ),
		] );

		$this->add_control( 'widget_description', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => __( 'A read-only, printable character sheet.', 'beyond-elysium' ),
			'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
		] );

		$this->add_control( 'game_slug', [
			'label'       => __( 'Game Slug', 'beyond-elysium' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => '',
			'description' => __( 'The chronicle this sheet belongs to.', 'beyond-elysium' ),
		] );

		$this->add_control( 'character_id', [
			'label'       => __( 'Character ID', 'beyond-elysium' ),
			'type'        => Controls_Manager::NUMBER,
			'default'     => 0,
			'description' => __( '0 reads the character_id URL query var instead.', 'beyond-elysium' ),
		] );

		$this->add_control( 'template_type', [
			'label'   => __( 'Template Type', 'beyond-elysium' ),
			'type'    => Controls_Manager::SELECT,
			'default' => 'sheet_full',
			'options' => [
				'sheet_full'    => __( 'Full Sheet', 'beyond-elysium' ),
				'sheet_compact' => __( 'Compact Sheet', 'beyond-elysium' ),
				'sheet_mobile'  => __( 'Mobile Sheet', 'beyond-elysium' ),
			],
		] );

		$this->end_controls_section();
	}

	protected function widget_slug(): string {
		return 'character-sheet';
	}

	/** Resolves the character ID from the widget setting or a character_id URL query var. */
	protected function widget_config( array $settings ): array {
		$character_id = (int) $settings['character_id'];
		if ( $character_id === 0 && isset( $_GET['character_id'] ) ) {
			$character_id = absint( wp_unslash( $_GET['character_id'] ) );
		}

		return [
			'characterId'  => $character_id,
			'gameSlug'     => $settings['game_slug'],
			'templateType' => $settings['template_type'],
		];
	}
}
