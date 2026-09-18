<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget wrapper for the Boon Ledger. Registers the widget's name,
 * title, and icon with Elementor, exposes Content section controls
 * for the target game slug and an optional character ID, and renders a
 * single mount-point <div> that the front-end script hydrates with the
 * BoonLedger React component. A character ID of 0 shows the whole
 * chronicle's ledger; a nonzero ID scopes it to one character.
 *
 * @see BE_PROCESS/releases/workflow-0.7.md Step 5b
 */
class Boon_Ledger extends Base_Widget {

	/**
	 * Returns the internal widget name Elementor uses to identify this
	 * widget type. Elementor stores this string in page and template
	 * markup wherever the widget is placed.
	 */
	public function get_name(): string {
		return 'be-boon-ledger';
	}

	/**
	 * Returns the human-readable label Elementor shows for this widget in
	 * the editor's widget panel, search results, and layers panel. This is
	 * the text an editor sees when placing the widget on a page.
	 */
	public function get_title(): string {
		return __( 'Boon Ledger', 'beyond-elysium' );
	}

	/**
	 * Returns the Elementor icon class shown next to this widget's title in
	 * the widget panel. The value is an eicon-* class name supplied by
	 * Elementor's built-in icon font.
	 */
	public function get_icon(): string {
		return 'eicon-price-list';
	}

	/**
	 * Builds the Elementor "Content" section shown in the editor panel for
	 * this widget. Adds a read-only description note, a Game Slug text
	 * control, and a Character ID number control that scopes the rendered
	 * ledger to one character instead of the whole chronicle.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'content_section', [
			'label' => __( 'Content', 'beyond-elysium' ),
		] );

		$this->add_control( 'widget_description', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => __( 'A transactional ledger of boons owed and paid between characters.', 'beyond-elysium' ),
			'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
		] );

		$this->add_control( 'game_slug', [
			'label'       => __( 'Game Slug', 'beyond-elysium' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => '',
			'description' => __( 'The chronicle whose boons this ledger shows.', 'beyond-elysium' ),
		] );

		$this->add_control( 'character_id', [
			'label'       => __( 'Character ID', 'beyond-elysium' ),
			'type'        => Controls_Manager::NUMBER,
			'default'     => 0,
			'description' => __( '0 shows the whole game\'s ledger; a character ID scopes to that character.', 'beyond-elysium' ),
		] );

		$this->end_controls_section();
	}

	protected function widget_slug(): string {
		return 'boon-ledger';
	}

	/** Adds a characterId entry only when one was set - absent means the whole game's ledger. */
	protected function widget_config( array $settings ): array {
		$config = [ 'gameSlug' => $settings['game_slug'] ];
		if ( ! empty( $settings['character_id'] ) ) {
			$config['characterId'] = (int) $settings['character_id'];
		}
		return $config;
	}
}
