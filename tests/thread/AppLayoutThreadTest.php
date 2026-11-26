<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\App_Layout;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * My Chronicle and the Storyteller Toolkit on Beyond Elysium's own app shell: which pages take it, what it removes, the
 * home-screen manifest, and the site's switch.
 */
class AppLayoutThreadTest extends WP_UnitTestCase {

	private const THEME_TEMPLATE = '/theme/page.php';

	/** @var array<string,int> */
	private array $pages = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		delete_option( App_Layout::OPTION );
		foreach ( [ 'be-player' => 'My Chronicle', 'be-storyteller' => 'Storyteller Toolkit', 'be-verify' => 'Verify Character', 'character-sheet-print' => 'Character Sheet (Print)', 'about-us' => 'About Us' ] as $slug => $title ) {
			$existing = get_page_by_path( $slug, OBJECT, 'page' );
			$this->pages[ $slug ] = $existing ? (int) $existing->ID : self::factory()->post->create( [
				'post_type'    => 'page',
				'post_name'    => $slug,
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => '<div data-be-widget="x"></div>',
			] );
		}
	}

	public function tearDown(): void {
		delete_option( App_Layout::OPTION );
		parent::tearDown();
	}

	private function template_for( string $slug ): string {
		$this->go_to( get_permalink( $this->pages[ $slug ] ) );
		return (string) apply_filters( 'template_include', self::THEME_TEMPLATE );
	}

	public function test_the_two_app_pages_take_the_shell_and_no_other_page_does(): void {
		$this->assertStringEndsWith( 'includes/templates/app-shell.php', $this->template_for( 'be-player' ) );
		$this->assertStringEndsWith( 'includes/templates/app-shell.php', $this->template_for( 'be-storyteller' ) );
		$this->assertSame( self::THEME_TEMPLATE, $this->template_for( 'be-verify' ) );
		$this->assertSame( self::THEME_TEMPLATE, $this->template_for( 'about-us' ) );
		$this->assertStringEndsWith( 'includes/templates/blank-canvas.php', $this->template_for( 'character-sheet-print' ), 'the print page keeps its own canvas' );
	}

	public function test_the_switch_off_leaves_both_pages_on_the_theme(): void {
		App_Layout::set_enabled( false );
		$this->assertSame( self::THEME_TEMPLATE, $this->template_for( 'be-player' ) );
		$this->assertSame( self::THEME_TEMPLATE, $this->template_for( 'be-storyteller' ) );
		App_Layout::set_enabled( true );
		$this->assertStringEndsWith( 'app-shell.php', $this->template_for( 'be-player' ) );
	}

	public function test_a_page_the_site_rebuilt_keeps_its_own_layout(): void {
		update_post_meta( $this->pages['be-player'], '_elementor_edit_mode', 'builder' );
		update_post_meta( $this->pages['be-storyteller'], '_wp_page_template', 'elementor_canvas' );

		$this->assertSame( self::THEME_TEMPLATE, $this->template_for( 'be-player' ) );
		$this->assertSame( self::THEME_TEMPLATE, $this->template_for( 'be-storyteller' ) );
	}

	public function test_the_theme_stylesheets_leave_a_shell_page_and_every_other_style_stays(): void {
		$this->go_to( get_permalink( $this->pages['be-player'] ) );
		wp_enqueue_style( 'thread-theme-style', get_template_directory_uri() . '/style.css', [], '1' );
		wp_enqueue_style( 'thread-child-style', get_stylesheet_directory_uri() . '/assets/child.css', [], '1' );
		wp_enqueue_style( 'thread-kit-style', content_url( '/uploads/elementor/css/post-5.css' ), [], '1' );
		wp_enqueue_style( 'thread-plugin-style', plugins_url( '/some-plugin/style.css' ), [], '1' );

		App_Layout::dequeue_theme_styles();

		$this->assertFalse( wp_style_is( 'thread-theme-style', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'thread-child-style', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'thread-kit-style', 'enqueued' ), "Elementor's kit stays" );
		$this->assertTrue( wp_style_is( 'thread-plugin-style', 'enqueued' ) );
	}

	public function test_a_theme_style_another_style_depends_on_prints_nothing_and_its_dependent_still_loads(): void {
		$this->go_to( get_permalink( $this->pages['be-storyteller'] ) );
		wp_register_style( 'thread-theme-dep', get_template_directory_uri() . '/assets/css/theme.css', [], '1' );
		wp_add_inline_style( 'thread-theme-dep', '.thread-theme-inline{color:red}' );
		wp_enqueue_style( 'thread-theme-dep' );
		wp_enqueue_style( 'thread-addon-style', plugins_url( '/some-addon/general.css' ), [ 'thread-theme-dep' ], '1' );

		App_Layout::dequeue_theme_styles();
		ob_start();
		wp_styles()->do_items( [ 'thread-addon-style' ] );
		$printed = (string) ob_get_clean();

		$this->assertStringContainsString( 'some-addon/general.css', $printed, "the add-on's own style still loads" );
		$this->assertStringNotContainsString( '/assets/css/theme.css', $printed );
		$this->assertStringNotContainsString( 'thread-theme-inline', $printed );
	}

	public function test_no_style_leaves_any_other_page(): void {
		$this->go_to( get_permalink( $this->pages['about-us'] ) );
		wp_enqueue_style( 'thread-theme-style-2', get_template_directory_uri() . '/style.css', [], '1' );

		App_Layout::dequeue_theme_styles();

		$this->assertTrue( wp_style_is( 'thread-theme-style-2', 'enqueued' ) );
	}

	public function test_a_shell_page_names_its_manifest_colour_and_icon_in_the_head(): void {
		$this->go_to( get_permalink( $this->pages['be-storyteller'] ) );
		ob_start();
		App_Layout::print_head_tags();
		$head = (string) ob_get_clean();

		$this->assertStringContainsString( 'rel="manifest"', $head );
		$this->assertStringContainsString( 'be/v1/app-manifest/storyteller', $head );
		$this->assertStringContainsString( '<meta name="theme-color" content="#8b0000">', $head );
		$this->assertStringContainsString( 'rel="apple-touch-icon"', $head );

		$this->go_to( get_permalink( $this->pages['about-us'] ) );
		ob_start();
		App_Layout::print_head_tags();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_manifest_names_the_site_and_page_and_opens_in_the_browser(): void {
		update_option( 'blogname', 'Kings of New York' );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/app-manifest/player' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( 'application/manifest+json', $response->get_headers()['Content-Type'] );
		$manifest = $response->get_data();
		$this->assertSame( 'Kings of New York: My Chronicle', $manifest['name'] );
		$this->assertSame( 'Kings of New York', $manifest['short_name'] );
		$this->assertSame( 'browser', $manifest['display'] );
		$this->assertSame( get_permalink( $this->pages['be-player'] ), $manifest['start_url'] );
		$this->assertSame( $manifest['start_url'], $manifest['scope'] );
		$this->assertStringEndsWith( 'assets/app-icons/be-icon-192.png', $manifest['icons'][0]['src'], 'a site with no icon of its own gets BE\'s' );
		$this->assertStringEndsWith( 'assets/app-icons/be-icon-512.png', $manifest['icons'][1]['src'] );
	}

	public function test_the_manifest_uses_the_sites_own_icon_and_accent_when_set(): void {
		$icon = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.png' );
		update_option( 'site_icon', $icon );
		update_option( \BeyondElysium\Models\Game::ACCENT_COLOR_OPTION, '#123456' );

		$manifest = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/app-manifest/storyteller' ) )->get_data();
		$this->assertStringNotContainsString( 'assets/app-icons', $manifest['icons'][0]['src'] );
		$this->assertSame( '#123456', $manifest['theme_color'] );

		delete_option( 'site_icon' );
		delete_option( \BeyondElysium\Models\Game::ACCENT_COLOR_OPTION );
	}

	public function test_an_unknown_manifest_page_is_not_found(): void {
		$this->assertSame( 404, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/app-manifest/verify' ) )->get_status() );
	}

	public function test_a_site_administrator_turns_the_switch_and_an_hst_cannot(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		update_option( \BeyondElysium\Models\Game::ACCENT_COLOR_OPTION, '#abcdef' );

		$off = new WP_REST_Request( 'PUT', '/be/v1/branding' );
		$off->set_param( 'app_layout', false );
		$response = rest_get_server()->dispatch( $off );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['app_layout'] );
		$this->assertFalse( App_Layout::enabled() );
		$this->assertSame( '#abcdef', $response->get_data()['accent_color'], 'turning the switch leaves the accent alone' );

		$read = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/branding' ) )->get_data();
		$this->assertFalse( $read['app_layout'] );

		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );
		$on = new WP_REST_Request( 'PUT', '/be/v1/branding' );
		$on->set_param( 'app_layout', true );
		$this->assertSame( 403, rest_get_server()->dispatch( $on )->get_status() );
		$this->assertFalse( App_Layout::enabled() );

		delete_option( \BeyondElysium\Models\Game::ACCENT_COLOR_OPTION );
	}
}
