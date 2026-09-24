<?php

namespace BeyondElysium\Elementor\Widgets;

use BeyondElysium\Models\Creature_Stack;
use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for the Character List.
 */
class Character_List extends Base_Widget {

	/**
	 * Returns the internal widget name Elementor uses to identify this widget type.
	 */
	public function get_name(): string {
		return 'be-character-list';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in the editor's widget panel, search results, and
	 * layers panel.
	 */
	public function get_title(): string {
		return __( 'Character List', 'beyond-elysium' );
	}

	/**
	 * Returns the Elementor icon class shown next to this widget's title in the widget panel.
	 */
	public function get_icon(): string {
		return 'eicon-table-of-contents';
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

	protected function widget_slug(): string {
		return 'character-list';
	}

	protected function widget_config( array $settings ): array {
		return [
			'gameSlug'     => $settings['game_slug'],
			'stackSlug'    => $settings['stack_slug'],
			'status'       => $settings['default_status'],
			'sheetPageUrl' => $settings['sheet_page_url']['url'] ?? '',
			'perPage'      => (int) $settings['per_page'],
		];
	}
}
