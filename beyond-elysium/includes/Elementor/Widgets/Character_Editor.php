<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for the Character Editor.
 */
class Character_Editor extends Base_Widget {

	/**
	 * Returns the internal widget name Elementor uses to identify this widget type.
	 */
	public function get_name(): string {
		return 'be-character-editor';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in the editor's widget panel, search results, and
	 * layers panel.
	 */
	public function get_title(): string {
		return __( 'Character Editor', 'beyond-elysium' );
	}

	/**
	 * Returns the Elementor icon class shown next to this widget's title in the widget panel.
	 */
	public function get_icon(): string {
		return 'eicon-form-horizontal';
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

		$this->add_control( 'template_type', [
			'label'       => __( 'Template Type', 'beyond-elysium' ),
			'type'        => Controls_Manager::SELECT,
			'default'     => 'sheet_full',
			'options'     => [
				'sheet_full'    => __( 'Full Sheet', 'beyond-elysium' ),
				'sheet_compact' => __( 'Compact Sheet', 'beyond-elysium' ),
				'sheet_mobile'  => __( 'Mobile Sheet', 'beyond-elysium' ),
			],
			'description' => __(
				'An NPC always edits against its own npc_full/npc_quick template regardless of this setting.',
				'beyond-elysium'
			),
		] );

		$this->end_controls_section();
	}

	protected function widget_slug(): string {
		return 'character-editor';
	}

	/**
	 * Resolves the character ID from the widget setting or a character_id URL query var.
	 */
	protected function widget_config( array $settings ): array {
		$character_id = (int) $settings['character_id'];
		if ( $character_id === 0 && isset( $_GET['character_id'] ) ) {
			$character_id = absint( wp_unslash( $_GET['character_id'] ) );
		}

		return [
			'characterId'  => $character_id,
			'gameSlug'     => $settings['game_slug'],
			'stackSlug'    => $settings['stack_slug'],
			'templateType' => $settings['template_type'],
		];
	}
}
