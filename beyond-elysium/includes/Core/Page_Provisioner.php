<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the front-end pages a fresh install needs to use Beyond
 * Elysium at all: character list, sheet, editor, plots feed, storyteller
 * toolkit, print canvas, and game dashboard. Each page is created once,
 * by slug, the first time a game exists to point it at, and is never
 * overwritten once it exists.
 */
class Page_Provisioner {

	const PRINT_SLUG = 'character-sheet-print';

	const PAGES = [
		'characters'       => [
			'title'  => 'Characters',
			'widget' => 'character-list',
		],
		'character-sheet'  => [
			'title'  => 'Character Sheet',
			'widget' => 'character-sheet',
		],
		'character-editor' => [
			'title'  => 'Edit Character',
			'widget' => 'character-editor',
		],
		// A player's own plots/rumors feed.
		'my-plots'         => [
			'title'  => 'My Plots & Rumors',
			'widget' => 'my-plots',
		],
		// Reuses the plot-manager widget; PlotManager.tsx itself gates its content on be_manage_plots.
		'storyteller-toolkit' => [
			'title'  => 'Storyteller Toolkit',
			'widget' => 'plot-manager',
		],
		// Same character-sheet widget, rendered through Print_Canvas.php's bare template.
		self::PRINT_SLUG   => [
			'title'  => 'Character Sheet (Print)',
			'widget' => 'character-sheet',
		],
		// One page for both ST and player views; GameDashboard.tsx branches on be_manage_characters.
		'game-dashboard'   => [
			'title'  => 'Dashboard',
			'widget' => 'game-dashboard',
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
	 * Creates any of the pages in PAGES that don't already exist, using
	 * the first game on this install (by creation date) as the default
	 * game each page's widget points at. Does nothing until at least one
	 * game exists.
	 */
	public static function maybe_provision(): void {
		$games = Game::all( [ 'per_page' => 1, 'orderby' => 'created_at', 'order' => 'ASC' ] );
		if ( empty( $games ) ) {
			return;
		}
		$game_slug = $games[0]->slug;

		$created = [];
		foreach ( self::PAGES as $slug => $page ) {
			$id = self::create_if_missing( $slug, $page['title'], $page['widget'], $game_slug );
			if ( $id ) {
				$created[ $slug ] = $id;
			}
		}

		if ( ! empty( $created['characters'] ) && ! empty( $created['character-sheet'] ) ) {
			self::wire_sheet_link( (int) $created['characters'], (int) $created['character-sheet'], $game_slug );
		}

		if ( ! empty( $created ) ) {
			self::maybe_add_to_nav( (int) reset( $created ) );
		}
	}

	/**
	 * Creates one front-end page with the given slug, title, and widget,
	 * pointed at the given game. Does nothing if a page already exists at
	 * that slug.
	 *
	 * @return int 0 if the page already existed (or creation failed); the new post ID otherwise.
	 */
	private static function create_if_missing( string $slug, string $title, string $widget, string $game_slug ): int {
		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing ) {
			return 0;
		}

		$config  = wp_json_encode( [ 'gameSlug' => $game_slug ] );
		$content = sprintf(
			'<!-- wp:html --><div data-be-widget="%s" data-be-config="%s"></div><!-- /wp:html -->',
			esc_attr( $widget ),
			esc_attr( (string) $config )
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

	/**
	 * Rewrites the Characters list page's widget config to include the
	 * Character Sheet page's URL, so character names link to it. Does
	 * nothing if the sheet page has no permalink.
	 */
	private static function wire_sheet_link( int $list_page_id, int $sheet_page_id, string $game_slug ): void {
		$sheet_url = get_permalink( $sheet_page_id );
		if ( ! $sheet_url ) {
			return;
		}

		// $game_slug comes from the caller, not parsed back out of the page's stored content.
		$config  = wp_json_encode( [ 'gameSlug' => $game_slug, 'sheetPageUrl' => $sheet_url ] );
		$content = sprintf(
			'<!-- wp:html --><div data-be-widget="character-list" data-be-config="%s"></div><!-- /wp:html -->',
			esc_attr( (string) $config )
		);
		wp_update_post( [ 'ID' => $list_page_id, 'post_content' => $content ] );
	}

	/**
	 * Adds the given page to the site's navigation menu, but only when
	 * exactly one theme location has a menu assigned. Does nothing when
	 * zero or more than one location has a menu.
	 */
	private static function maybe_add_to_nav( int $page_id ): void {
		$locations = get_nav_menu_locations();
		if ( count( $locations ) !== 1 ) {
			return;
		}

		$menu_id = reset( $locations );
		$menu    = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return;
		}

		wp_update_nav_menu_item( $menu->term_id, 0, [
			'menu-item-title'     => __( 'Characters', 'beyond-elysium' ),
			'menu-item-object-id' => $page_id,
			'menu-item-object'    => 'page',
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
		] );
	}
}
