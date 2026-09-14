<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the front-end pages a fresh install needs to use Beyond Elysium at
 * all: a chronicle-switching player page (Characters/Sheet/Edit/My Plots &
 * Rumors, tabbed) and a chronicle-switching Storyteller page (Dashboard/
 * Approval Queue/Plots/Boon Ledger, tabbed), plus the standalone print canvas
 * and public verification page. Four fixed pages total, regardless of how
 * many chronicles this install ever hosts (page-consolidation-design.md) -
 * replaces the old model of ten page-kinds each duplicated per chronicle.
 * Each page is created once, by slug, and is never overwritten once it
 * exists.
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
		// Public, unauthenticated (GX-7) - VerifyCharacter.tsx reads its own ?code=
		// URL param rather than anything in data-be-config.
		self::VERIFY_SLUG      => [
			'title'  => 'Verify Character',
			'widget' => 'verify-character',
		],
	];

	/**
	 * Hooks maybe_provision() onto the be_after_upgrade action, so this
	 * plugin's default front-end pages are checked and created, if
	 * missing, every time that action fires.
	 */
	public static function register(): void {
		add_action( 'be_after_upgrade', [ self::class, 'maybe_provision' ] );
	}

	/**
	 * Creates any of the four fixed pages that don't already exist. Unlike the
	 * old per-chronicle model, none of these bake in a gameSlug at creation
	 * time - each resolves its own chronicle from `?game_slug=` via
	 * useChronicleSwitcher() (the two tabbed pages), or needs no chronicle at
	 * all (Print and Verify read their own URL params). No longer gated on at
	 * least one game existing - a fresh install with zero chronicles can still
	 * show these pages, with the switcher's own "you don't belong to any
	 * chronicle yet" state.
	 */
	public static function maybe_provision(): void {
		foreach ( self::PAGES as $slug => $page ) {
			self::create_if_missing( $slug, $page['title'], $page['widget'] );
		}
	}

	/**
	 * Creates one front-end page with the given slug, title, and widget, and
	 * no data-be-config at all - every widget mounted from PAGES resolves its
	 * own chronicle/character/tab state from the URL or its own hook, not from
	 * config baked in at creation time. Does nothing if a page already exists
	 * at that slug.
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
