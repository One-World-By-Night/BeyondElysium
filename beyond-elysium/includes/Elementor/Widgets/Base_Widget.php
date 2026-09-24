<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * The shape every Beyond Elysium Elementor widget shares: the single "beyond-elysium" category, and a `render()` that
 * prints one mount-point `<div>` carrying `data-be-widget` and `data-be-config` for the front-end hydration router
 * (`src/index.tsx`) to pick up.
 */
abstract class Base_Widget extends Widget_Base {

	/**
	 * @return string[]
	 */
	public function get_categories(): array {
		return [ 'beyond-elysium' ];
	}

	/**
	 * The `data-be-widget` slug the front-end hydration router matches on.
	 */
	abstract protected function widget_slug(): string;

	/**
	 * This widget's own config, built from its resolved Elementor settings.
	 *
	 * @param array<string,mixed> $settings `$this->get_settings_for_display()`.
	 * @return array<string,mixed>
	 */
	abstract protected function widget_config( array $settings ): array;

	protected function render(): void {
		$settings = $this->get_settings_for_display();

		printf(
			'<div data-be-widget="%s" data-be-config="%s"></div>',
			esc_attr( $this->widget_slug() ),
			esc_attr( (string) wp_json_encode( $this->widget_config( $settings ) ) )
		);
	}
}
