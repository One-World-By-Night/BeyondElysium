<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Page_Provisioner;
use WP_UnitTestCase;

/**
 * page-consolidation-design.md: four fixed pages, none of them chronicle-specific -
 * My Chronicle and Storyteller Toolkit each resolve their own chronicle from a
 * client-side switcher, and Print/Verify never needed one at all. Provisioning no
 * longer waits for a game to exist (the old per-chronicle model's own reason for
 * that gate is gone), and creates no data-be-config at all - every widget here
 * reads its state from the URL or its own hook, not from config baked in at
 * creation time.
 */
class PageProvisionerTest extends WP_UnitTestCase {

	public function test_provisions_all_four_fixed_pages_with_no_game_at_all(): void {
		Page_Provisioner::maybe_provision();

		$player      = get_page_by_path( Page_Provisioner::PLAYER_SLUG, OBJECT, 'page' );
		$storyteller = get_page_by_path( Page_Provisioner::STORYTELLER_SLUG, OBJECT, 'page' );
		$print       = get_page_by_path( Page_Provisioner::PRINT_SLUG, OBJECT, 'page' );
		$verify      = get_page_by_path( Page_Provisioner::VERIFY_SLUG, OBJECT, 'page' );

		$this->assertNotNull( $player, 'My Chronicle must provision with zero chronicles on the install' );
		$this->assertNotNull( $storyteller );
		$this->assertNotNull( $print );
		$this->assertNotNull( $verify );

		$this->assertStringContainsString( 'data-be-widget="my-chronicle"', $player->post_content );
		$this->assertStringContainsString( 'data-be-widget="storyteller-toolkit-page"', $storyteller->post_content );
		$this->assertStringContainsString( 'data-be-widget="character-sheet"', $print->post_content );
		$this->assertStringContainsString( 'data-be-widget="verify-character"', $verify->post_content );
	}

	public function test_no_page_bakes_in_any_gameslug_or_config_at_all(): void {
		Page_Provisioner::maybe_provision();

		foreach ( Page_Provisioner::PAGES as $slug => $page ) {
			$post = get_page_by_path( $slug, OBJECT, 'page' );
			$this->assertStringNotContainsString( 'gameSlug', $post->post_content, "{$slug} must not bake in a chronicle" );
			$this->assertStringNotContainsString( 'data-be-config', $post->post_content, "{$slug} must carry no config at all" );
		}
	}

	public function test_running_it_twice_does_not_create_duplicate_pages(): void {
		Page_Provisioner::maybe_provision();
		Page_Provisioner::maybe_provision();

		$pages = get_posts( [ 'post_type' => 'page', 'name' => Page_Provisioner::PLAYER_SLUG, 'post_status' => 'publish', 'numberposts' => -1 ] );
		$this->assertCount( 1, $pages );
	}

	public function test_never_overwrites_a_page_a_chronicle_already_customized(): void {
		Page_Provisioner::maybe_provision();

		$player = get_page_by_path( Page_Provisioner::PLAYER_SLUG, OBJECT, 'page' );
		wp_update_post( [ 'ID' => $player->ID, 'post_content' => 'A chronicle wrote its own content here.' ] );

		Page_Provisioner::maybe_provision();

		$reloaded = get_post( $player->ID );
		$this->assertSame( 'A chronicle wrote its own content here.', $reloaded->post_content );
	}
}
