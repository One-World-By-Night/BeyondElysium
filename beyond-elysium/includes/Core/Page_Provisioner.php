<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the four fixed front-end pages a fresh install needs: a player page, a Storyteller page, the print canvas
 * and the public verification page.
 */
class Page_Provisioner {

	const PRINT_SLUG       = 'character-sheet-print';
	const VERIFY_SLUG      = 'be-verify';
	const PLAYER_SLUG      = 'be-player';
	const STORYTELLER_SLUG = 'be-storyteller';

	const PAGES = [
		self::PLAYER_SLUG      => [
			'title'  => 'My Chronicle',
			'widget' => 'my-chronicle',
		],
		self::STORYTELLER_SLUG => [
			'title'  => 'Storyteller Toolkit',
			'widget' => 'storyteller-toolkit-page',
		],
		// Same character-sheet widget, rendered through Print_Canvas.php's bare template.
		self::PRINT_SLUG       => [
			'title'  => 'Character Sheet (Print)',
			'widget' => 'character-sheet',
		],
		// The public verification page; needs no login.
		self::VERIFY_SLUG      => [
			'title'  => 'Verify Character',
			'widget' => 'verify-character',
		],
	];

	/**
	 * Hooks maybe_provision() onto the be_after_upgrade action.
	 */
	public static function register(): void {
		add_action( 'be_after_upgrade', [ self::class, 'maybe_provision' ] );
	}

	/**
	 * Creates any of the four fixed pages that don't already exist.
	 */
	public static function maybe_provision(): void {
		foreach ( self::PAGES as $slug => $page ) {
			self::create_if_missing( $slug, $page['title'], $page['widget'] );
		}
	}

	/**
	 * Creates one front-end page with the given slug, title and widget.
	 *
	 * @return int 0 if the page already existed (or creation failed); the new post ID otherwise.
	 */
	private static function create_if_missing( string $slug, string $title, string $widget ): int {
		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing ) {
			return 0;
		}

		$content = sprintf(
			'<!-- wp:html --><div data-be-widget="%s"></div><!-- /wp:html -->',
			esc_attr( $widget )
		);

		$id = wp_insert_post( [
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => $content,
		], true );

		return is_wp_error( $id ) ? 0 : (int) $id;
	}
}
