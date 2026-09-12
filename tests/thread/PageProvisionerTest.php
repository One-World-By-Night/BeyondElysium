<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Page_Provisioner;
use BeyondElysium\Models\Game;
use WP_UnitTestCase;

/**
 * Front end never had an entry point either (2026-09-09 deep dive) - the widgets work,
 * but nothing on a fresh install ever puts them on a real page. Confirmed live against
 * kony-sabbat.net, which had never had a single game created, let alone a Characters
 * page, despite the plugin being active since 0.8.9.
 *
 * be-demo (Seeder::seed_demo_characters(), same day) is seeded once, outside any single
 * test's own transaction, as real "core install" data - so on a genuinely fresh install
 * it is always the first game that exists, and Page_Provisioner naturally uses it as the
 * default. That is the actual, intended interaction between the two features (a fresh
 * install's auto-provisioned Characters page shows 22 real characters with zero
 * configuration), not a bug - but it means every test here that wants to control which
 * game gets picked has to remove be-demo first, since WP_UnitTestCase's per-test
 * transaction rollback does not touch data seeded during test-suite bootstrap.
 */
class PageProvisionerTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		self::remove_be_demo();
	}

	private static function remove_be_demo(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_characters WHERE owner_slug = 'be-demo'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_games WHERE slug = 'be-demo'" );
	}

	public function test_does_nothing_when_no_game_exists_yet(): void {
		Page_Provisioner::maybe_provision();

		$this->assertNull( get_page_by_path( 'characters', OBJECT, 'page' ) );
	}

	public function test_uses_be_demo_by_default_on_a_fresh_install(): void {
		Game::create( [ 'name' => 'Beyond Elysium Demo', 'slug' => 'be-demo' ] );

		Page_Provisioner::maybe_provision();

		$characters = get_page_by_path( 'characters', OBJECT, 'page' );
		$this->assertNotNull( $characters, 'A fresh install always has be-demo, so the Characters page must provision immediately, with no chronicle setup required.' );
		$this->assertStringContainsString( 'be-demo', $characters->post_content );
	}

	public function test_creates_all_five_pages_once_a_game_exists(): void {
		Game::create( [ 'name' => 'Provisioner Test Game', 'slug' => 'thread-test-provisioner-game' ] );

		Page_Provisioner::maybe_provision();

		$characters = get_page_by_path( 'characters', OBJECT, 'page' );
		$sheet      = get_page_by_path( 'character-sheet', OBJECT, 'page' );
		$editor     = get_page_by_path( 'character-editor', OBJECT, 'page' );
		$my_plots   = get_page_by_path( 'my-plots', OBJECT, 'page' );
		$print      = get_page_by_path( Page_Provisioner::PRINT_SLUG, OBJECT, 'page' );

		$this->assertNotNull( $characters );
		$this->assertNotNull( $sheet );
		$this->assertNotNull( $editor );
		$this->assertNotNull( $my_plots, 'Decision 046: my-plots never had a page to mount on either, the same gap this class already closed for the three Character pages.' );
		$this->assertNotNull( $print, 'Decision 052: the print-canvas page is provisioned exactly like every other page here - only its page template differs.' );

		$this->assertStringContainsString( 'data-be-widget="character-list"', $characters->post_content );
		$this->assertStringContainsString( 'data-be-widget="character-sheet"', $sheet->post_content );
		$this->assertStringContainsString( 'data-be-widget="character-editor"', $editor->post_content );
		$this->assertStringContainsString( 'data-be-widget="my-plots"', $my_plots->post_content );
		$this->assertStringContainsString( 'data-be-widget="character-sheet"', $print->post_content );

		$this->assertStringContainsString( 'thread-test-provisioner-game', $characters->post_content );
		$this->assertStringContainsString( 'thread-test-provisioner-game', $my_plots->post_content );
	}

	public function test_provisions_the_game_dashboard_page(): void {
		Game::create( [ 'name' => 'Dashboard Provisioner Test Game', 'slug' => 'thread-test-dashboard-provisioner-game' ] );

		Page_Provisioner::maybe_provision();

		$dashboard = get_page_by_path( 'game-dashboard', OBJECT, 'page' );
		$this->assertNotNull( $dashboard, 'Step 7c: the game-dashboard widget had no page to mount on until now, same gap this class exists to close for every other widget.' );
		$this->assertStringContainsString( 'data-be-widget="game-dashboard"', $dashboard->post_content );
		$this->assertStringContainsString( 'thread-test-dashboard-provisioner-game', $dashboard->post_content );
	}

	/**
	 * BE_PROCESS/0.99.2-workflow.md, "Shipped code that cannot be reached": both widgets
	 * existed and worked with no page to mount on, the same class of gap this class already
	 * closed for my-plots (Decision 046) and game-dashboard (Step 7c).
	 */
	public function test_provisions_the_approval_queue_and_boon_ledger_pages(): void {
		Game::create( [ 'name' => 'Approval Queue Provisioner Test Game', 'slug' => 'thread-test-approval-queue-provisioner-game' ] );

		Page_Provisioner::maybe_provision();

		$approval_queue = get_page_by_path( 'approval-queue', OBJECT, 'page' );
		$boon_ledger    = get_page_by_path( 'boon-ledger', OBJECT, 'page' );

		$this->assertNotNull( $approval_queue );
		$this->assertNotNull( $boon_ledger );
		$this->assertStringContainsString( 'data-be-widget="approval-queue"', $approval_queue->post_content );
		$this->assertStringContainsString( 'data-be-widget="boon-ledger"', $boon_ledger->post_content );
		$this->assertStringContainsString( 'thread-test-approval-queue-provisioner-game', $approval_queue->post_content );
		$this->assertStringContainsString( 'thread-test-approval-queue-provisioner-game', $boon_ledger->post_content );
	}

	public function test_the_characters_page_links_to_the_real_sheet_page(): void {
		Game::create( [ 'name' => 'Link Test Game', 'slug' => 'thread-test-link-game' ] );
		Page_Provisioner::maybe_provision();

		$characters = get_page_by_path( 'characters', OBJECT, 'page' );
		$sheet      = get_page_by_path( 'character-sheet', OBJECT, 'page' );

		$this->assertStringContainsString( 'sheetPageUrl', $characters->post_content );
		$this->assertStringContainsString( (string) get_permalink( $sheet->ID ), str_replace( '\\/', '/', $characters->post_content ) );
	}

	public function test_running_it_twice_does_not_create_duplicate_pages(): void {
		Game::create( [ 'name' => 'Idempotent Test Game', 'slug' => 'thread-test-idempotent-game' ] );
		Page_Provisioner::maybe_provision();
		Page_Provisioner::maybe_provision();

		$pages = get_posts( [ 'post_type' => 'page', 'name' => 'characters', 'post_status' => 'publish', 'numberposts' => -1 ] );
		$this->assertCount( 1, $pages );
	}

	public function test_never_overwrites_a_page_a_chronicle_already_customized(): void {
		Game::create( [ 'name' => 'Respect Test Game', 'slug' => 'thread-test-respect-game' ] );
		Page_Provisioner::maybe_provision();

		$characters = get_page_by_path( 'characters', OBJECT, 'page' );
		wp_update_post( [ 'ID' => $characters->ID, 'post_content' => 'A chronicle wrote its own content here.' ] );

		Page_Provisioner::maybe_provision();

		$reloaded = get_post( $characters->ID );
		$this->assertSame( 'A chronicle wrote its own content here.', $reloaded->post_content );
	}
}
