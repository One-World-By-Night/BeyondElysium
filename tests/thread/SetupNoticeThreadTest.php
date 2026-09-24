<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Setup_Notice;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_UnitTestCase;

/**
 * The new-admin pointer to Chronicle Setup (1.3.6, guided-chronicle-setup-design.md §6.6): who
 * is pointed at which chronicle, where it shows, and that dismissing it is per user.
 */
class SetupNoticeThreadTest extends WP_UnitTestCase {

	private string $slug = 'setup-notice-test';
	private int $admin_id;
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Game::create( [ 'slug' => $this->slug, 'name' => 'Setup Notice Test', 'created_by' => $this->admin_id ] );
		$this->game_id = (int) Game::find_by_slug( $this->slug )->id;
	}

	/** @return string[] The slugs this user is pointed at. */
	private function pointed_at( int $user_id ): array {
		return array_map( static fn( $entry ) => $entry['game']->slug, Setup_Notice::chronicles_needing_setup( $user_id ) );
	}

	private function rendered_on( string $screen, int $as_user ): string {
		wp_set_current_user( $as_user );
		set_current_screen( $screen );
		ob_start();
		Setup_Notice::render();
		return (string) ob_get_clean();
	}

	public function test_an_administrator_is_pointed_at_a_chronicle_nobody_has_created_a_character_in(): void {
		$this->assertContains( $this->slug, $this->pointed_at( $this->admin_id ) );

		foreach ( Setup_Notice::chronicles_needing_setup( $this->admin_id ) as $entry ) {
			if ( $entry['game']->slug === $this->slug ) {
				$this->assertGreaterThan( 0, $entry['attention'], 'a chronicle with no characters always has the Characters row to do' );
			}
		}
	}

	public function test_a_chronicle_that_has_a_character_is_not_pointed_at(): void {
		Character::create( [ 'name' => 'Setup Notice Character', 'owner_slug' => $this->slug, 'stack_slug' => 'vampire', 'created_by' => $this->admin_id ] );

		$this->assertNotContains( $this->slug, $this->pointed_at( $this->admin_id ), 'an established chronicle is never nagged' );
	}

	public function test_the_demo_chronicle_is_never_mentioned(): void {
		$this->assertNotContains( 'be-demo', $this->pointed_at( $this->admin_id ) );
	}

	public function test_dismissing_is_per_user_and_per_chronicle(): void {
		$other_admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

		Setup_Notice::dismiss( $this->admin_id, [ $this->slug ] );

		$this->assertNotContains( $this->slug, $this->pointed_at( $this->admin_id ) );
		$this->assertContains( $this->slug, $this->pointed_at( $other_admin ), 'someone else still gets it' );

		Setup_Notice::dismiss( $this->admin_id, [ $this->slug, $this->slug ] );
		$this->assertSame( [ $this->slug ], get_user_meta( $this->admin_id, Setup_Notice::DISMISSED_META, true ), 'no duplicates' );
	}

	public function test_an_hst_is_pointed_at_their_own_chronicle_and_nobody_else_is(): void {
		$hst_id      = self::factory()->user->create( [ 'role' => 'editor' ] );
		$stranger_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$ast_id      = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		Game_Member::set_role( $this->game_id, $ast_id, 'ast' );

		$this->assertContains( $this->slug, $this->pointed_at( $hst_id ) );
		$this->assertNotContains( $this->slug, $this->pointed_at( $stranger_id ), 'an editor with no part in the chronicle is not pointed at it' );
		$this->assertNotContains( $this->slug, $this->pointed_at( $ast_id ), 'setting a chronicle up is the HST\'s' );
	}

	public function test_someone_who_cannot_set_a_chronicle_up_is_pointed_at_nothing(): void {
		$this->assertSame( [], $this->pointed_at( self::factory()->user->create( [ 'role' => 'subscriber' ] ) ) );
	}

	public function test_it_shows_on_the_dashboard_plugins_and_this_plugins_own_screens_but_not_on_chronicle_setup(): void {
		$this->assertTrue( Setup_Notice::screen_shows_pointer( 'dashboard' ) );
		$this->assertTrue( Setup_Notice::screen_shows_pointer( 'plugins' ) );
		$this->assertTrue( Setup_Notice::screen_shows_pointer( 'toplevel_page_beyond-elysium' ) );
		$this->assertTrue( Setup_Notice::screen_shows_pointer( 'beyond-elysium_page_beyond-elysium-characters' ) );
		$this->assertFalse( Setup_Notice::screen_shows_pointer( 'beyond-elysium_page_beyond-elysium-chronicle-setup-hub' ), 'it points at this page' );
		$this->assertFalse( Setup_Notice::screen_shows_pointer( 'edit-post' ) );
		$this->assertFalse( Setup_Notice::screen_shows_pointer( 'options-general' ) );
	}

	public function test_the_notice_names_the_chronicle_links_to_its_setup_and_offers_a_dismiss_link(): void {
		Game::update( 'be-demo', [ 'name' => 'Beyond Elysium Demo' ] );
		Setup_Notice::dismiss( $this->admin_id, array_filter( $this->pointed_at( $this->admin_id ), fn( $slug ) => $slug !== $this->slug ) );

		$html = $this->rendered_on( 'dashboard', $this->admin_id );

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Setup Notice Test is not set up yet', $html );
		$this->assertStringContainsString( 'item', $html );
		$this->assertStringContainsString( 'page=beyond-elysium-chronicle-setup-hub', $html );
		$this->assertStringContainsString( 'game=' . $this->slug, $html );
		$this->assertStringContainsString( Setup_Notice::DISMISS_ACTION . '=' . $this->slug, $html );
		$this->assertStringContainsString( '_wpnonce=', $html, 'dismissing is a nonce-checked link' );
	}

	public function test_the_notice_says_nothing_on_chronicle_setup_or_to_someone_with_nothing_to_do(): void {
		$this->assertSame( '', $this->rendered_on( 'beyond-elysium_page_beyond-elysium-chronicle-setup-hub', $this->admin_id ) );

		Setup_Notice::dismiss( $this->admin_id, $this->pointed_at( $this->admin_id ) );
		$this->assertSame( '', $this->rendered_on( 'dashboard', $this->admin_id ) );
	}
}
