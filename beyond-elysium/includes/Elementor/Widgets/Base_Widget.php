<?php

namespace BeyondElysium\Elementor\Widgets;

use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * The shape every Beyond Elysium Elementor widget shares (1.1.1 §4 audit): the single
 * "beyond-elysium" category, and a `render()` that prints one mount-point `<div>` carrying
 * `data-be-widget`/`data-be-config` for the front-end hydration router (`src/index.tsx`) to
 * pick up. Twelve widgets hand-rolled both identically until this was extracted - each
 * concrete widget now only supplies `get_name()`, `get_title()`, `get_icon()`,
 * `register_controls()`, and the two methods below.
 */
abstract class Base_Widget extends Widget_Base {

	/**
	 * @return string[]
	 */
	public function get_categories(): array {
		return [ 'beyond-elysium' ];
	}

	/** The `data-be-widget` slug the front-end hydration router matches on. */
	abstract protected function widget_slug(): string;

	/**
	 * This widget's own config, built from its resolved Elementor settings - becomes
	 * `data-be-config`'s JSON payload.
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
