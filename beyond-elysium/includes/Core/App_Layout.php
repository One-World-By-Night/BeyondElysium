<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * Puts My Chronicle and the Storyteller Toolkit on Beyond Elysium's own app shell: no theme header, footer, page title
 * or theme stylesheets, with a home-screen manifest. A site administrator turns it off for the whole site, and a page a
 * site has rebuilt keeps its own layout.
 */
class App_Layout {

	/**
	 * The site's switch: absent or '1' is on, '0' is off.
	 */
	const OPTION = 'be_app_layout';

	/**
	 * The fixed pages the shell carries, by slug, with the name each goes by in the manifest route.
	 */
	const PAGES = [
		Page_Provisioner::PLAYER_SLUG      => 'player',
		Page_Provisioner::STORYTELLER_SLUG => 'storyteller',
	];

	/**
	 * The accent a page shows when neither the site nor a chronicle sets one.
	 */
	const DEFAULT_ACCENT = '#8b0000';

	/**
	 * The sizes of the icons the plugin ships for a site with no icon of its own.
	 */
	const ICON_SIZES = [ 180, 192, 512 ];

	/**
	 * Hooks the shell template, the theme-style removal and the head tags.
	 */
	public static function register(): void {
		add_filter( 'template_include', [ self::class, 'maybe_use_shell' ], 20 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'dequeue_theme_styles' ], 100 );
		add_action( 'wp_head', [ self::class, 'print_head_tags' ], 2 );
	}

	/**
	 * Whether the site's switch is on.
	 */
	public static function enabled(): bool {
		return '0' !== (string) get_option( self::OPTION, '1' );
	}

	/**
	 * Turns the site's switch on or off.
	 */
	public static function set_enabled( bool $on ): void {
		update_option( self::OPTION, $on ? '1' : '0' );
	}

	/**
	 * The shell page the current request is for (`player` or `storyteller`), or null when the shell does not apply: the
	 * switch is off, the request is for another page, or the page has its own template or is built with Elementor.
	 */
	public static function current_page(): ?string {
		if ( ! self::enabled() || ! is_page() ) {
			return null;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$page = self::PAGES[ $post->post_name ] ?? null;
		if ( null === $page ) {
			return null;
		}
		$template = (string) get_post_meta( $post->ID, '_wp_page_template', true );
		if ( '' !== $template && 'default' !== $template ) {
			return null;
		}
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			return null;
		}
		return $page;
	}

	/**
	 * Swaps in the app shell template on a shell page.
	 */
	public static function maybe_use_shell( string $template ): string {
		return null === self::current_page() ? $template : BE_PLUGIN_DIR . 'includes/templates/app-shell.php';
	}

	/**
	 * Takes the active theme's own stylesheets off a shell page: every style whose source sits under the theme's or the
	 * child theme's directory is dequeued and left registered with no file and no inline CSS, so a style that depends on
	 * one still loads and the theme's CSS never prints. Every other style stays.
	 */
	public static function dequeue_theme_styles(): void {
		if ( null === self::current_page() ) {
			return;
		}
		$roots  = array_unique( [ trailingslashit( get_template_directory_uri() ), trailingslashit( get_stylesheet_directory_uri() ) ] );
		$styles = wp_styles();
		foreach ( $styles->registered as $handle => $style ) {
			$src = is_string( $style->src ) ? $style->src : '';
			if ( '' === $src || ! self::starts_with_any( $src, $roots ) ) {
				continue;
			}
			wp_dequeue_style( $handle );
			$style->src = false;
			unset( $style->extra['before'], $style->extra['after'] );
		}
	}

	/**
	 * @param string[] $prefixes
	 */
	private static function starts_with_any( string $value, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $value, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prints the manifest link, the theme colour and the home-screen icon on a shell page.
	 */
	public static function print_head_tags(): void {
		$page = self::current_page();
		if ( null === $page ) {
			return;
		}
		printf( '<link rel="manifest" href="%s">' . "\n", esc_url( rest_url( 'be/v1/app-manifest/' . $page ) ) );
		printf( '<meta name="theme-color" content="%s">' . "\n", esc_attr( self::theme_color() ) );
		printf( '<link rel="apple-touch-icon" href="%s">' . "\n", esc_url( self::icon_url( 180 ) ) );
	}

	/**
	 * The colour a shell page's browser bar and home-screen icon take: the site's brand accent, else the default.
	 */
	public static function theme_color(): string {
		$accent = (string) get_option( Game::ACCENT_COLOR_OPTION, '' );
		return '' !== $accent ? $accent : self::DEFAULT_ACCENT;
	}

	/**
	 * A home-screen icon at one size: the site's own icon when it has one, else the plugin's.
	 */
	public static function icon_url( int $size ): string {
		$site = (string) get_site_icon_url( $size );
		if ( '' !== $site ) {
			return $site;
		}
		$closest = self::ICON_SIZES[0];
		foreach ( self::ICON_SIZES as $shipped ) {
			if ( abs( $shipped - $size ) < abs( $closest - $size ) ) {
				$closest = $shipped;
			}
		}
		return BE_PLUGIN_URL . 'assets/app-icons/be-icon-' . $closest . '.png';
	}

	/**
	 * The web app manifest for one shell page, or null for a page the site does not have.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function manifest( string $page ): ?array {
		$slug = array_search( $page, self::PAGES, true );
		$post = false === $slug ? null : get_page_by_path( (string) $slug, OBJECT, 'page' );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$address = (string) get_permalink( $post );
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$color   = self::theme_color();

		return [
			'name'             => $site . ': ' . wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
			'short_name'       => $site,
			'start_url'        => $address,
			'scope'            => $address,
			'display'          => 'browser',
			'theme_color'      => $color,
			'background_color' => $color,
			'icons'            => [
				[ 'src' => self::icon_url( 192 ), 'sizes' => '192x192', 'type' => 'image/png' ],
				[ 'src' => self::icon_url( 512 ), 'sizes' => '512x512', 'type' => 'image/png' ],
			],
		];
	}
}
