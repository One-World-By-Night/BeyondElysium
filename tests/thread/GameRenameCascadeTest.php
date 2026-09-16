<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use WP_UnitTestCase;

/**
 * BE_PROCESS/chronicle-rename-design.md CR-8. A real chronicle rename, against real rows
 * in all three tables the cascade touches, is what proves the fix - not a unit test against
 * a mocked wpdb. The most important single assertion is the fork one: `find_for_game()`
 * must resolve the renamed fork through its own row, not silently fall back to the global
 * catalog (§7.4's "silent wrong answer, not a visible break" finding).
 */
class GameRenameCascadeTest extends WP_UnitTestCase {

	private function create_game( string $slug ): int {
		$id = Game::create( [ 'name' => 'Rename Cascade ' . $slug, 'slug' => $slug ] );
		$this->assertIsInt( $id, 'Game::create() must succeed for the test to mean anything' );
		return (int) $id;
	}

	public function test_a_real_rename_moves_characters_and_the_fork_and_resolves_correctly_after(): void {
		$game_id = $this->create_game( 'thread-cascade-old' );

		$character_ids = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$character_ids[] = Character::create( [
				'name'       => "Cascade Character {$i}",
				'stack_slug' => 'vampire',
				'owner_type' => 'chronicle',
				'owner_slug' => 'thread-cascade-old',
			] );
		}

		$fork = Schema_Block::find_or_create_fork_for_game( 'vampire-disciplines', 'thread-cascade-old' );
		$this->assertNotNull( $fork );
		$fork_version_before = $fork->version;

		$result = Game::rename( $game_id, 'thread-cascade-new' );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 3, $result['characters'] );
		$this->assertSame( 1, $result['schema_blocks'] );

		// All three characters followed, by both direct lookup and the game-scoped query.
		foreach ( $character_ids as $id ) {
			$this->assertSame( 'thread-cascade-new', Character::find( (int) $id )->owner_slug );
		}
		$this->assertCount( 3, Character::all_for_game( 'thread-cascade-new' ) );
		$this->assertCount( 0, Character::all_for_game( 'thread-cascade-old' ) );

		// The fork itself: resolves through find_for_game() to the FORK at the new slug,
		// not a silent fallback to the global catalog - and its version must not have
		// incremented, since a rename is not a content change (§7.4.3).
		$resolved = Schema_Block::find_for_game( 'vampire-disciplines', 'thread-cascade-new' );
		$this->assertNotNull( $resolved );
		$this->assertSame( 'thread-cascade-new', $resolved->game_slug, 'must be the fork itself, not the global row falling back' );
		$this->assertSame( $fork_version_before, $resolved->version, 'a rename must not bump the fork version - it is not a definition change' );

		// The old slug now falls back to the global row - confirms the fork actually
		// moved rather than being copied, leaving nothing behind at the old slug.
		$old_slug_lookup = Schema_Block::find_for_game( 'vampire-disciplines', 'thread-cascade-old' );
		$this->assertNotSame( 'thread-cascade-old', $old_slug_lookup->game_slug ?? null );

		$this->assertSame( 'thread-cascade-new', Game::find( $game_id )->slug );
	}

	public function test_a_rename_into_a_slug_with_an_orphaned_fork_aborts_and_leaves_everything_untouched(): void {
		$source_id = $this->create_game( 'thread-cascade-collide-source' );
		Character::create( [
			'name'       => 'Should Not Move',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => 'thread-cascade-collide-source',
		] );

		// An orphaned fork at the destination slug - no games row owns it, matching the
		// real, logged delete_with_content() gap (§7.2) that makes this collision real.
		$orphan_fork = Schema_Block::find_or_create_fork_for_game( 'vampire-disciplines', 'thread-cascade-collide-target' );
		$this->assertNotNull( $orphan_fork );

		$result = Game::rename( $source_id, 'thread-cascade-collide-target' );

		$this->assertFalse( $result['changed'] );
		$this->assertSame( 'fork_collision', $result['error'] );
		$this->assertContains( 'vampire-disciplines', $result['blocks'] );

		// Nothing moved: the source game keeps its slug, its character stayed put, and the
		// orphaned fork is exactly where it was.
		$this->assertSame( 'thread-cascade-collide-source', Game::find( $source_id )->slug );
		$this->assertCount( 1, Character::all_for_game( 'thread-cascade-collide-source' ) );
		$untouched_fork = Schema_Block::find_for_game( 'vampire-disciplines', 'thread-cascade-collide-target' );
		$this->assertNotNull( $untouched_fork );
		$this->assertSame( 'thread-cascade-collide-target', $untouched_fork->game_slug );
	}

	/**
	 * 1.0.0-review F-083 (Pass H intake `t1-sync-data-credits-apr-db`). A rename rewrote the slug in
	 * a provisioned page's widget markup and in Elementor widget settings, but not in the House Rules
	 * shortcode a Storyteller types onto a page - which kept asking for the old chronicle.
	 */
	public function test_a_rename_rewrites_the_house_rules_shortcode_wherever_it_was_placed(): void {
		$game_id = $this->create_game( 'thread-shortcode-old' );
		$page    = wp_insert_post( [
			'post_type'    => 'page', 'post_status' => 'publish', 'post_title' => 'Our House Rules',
			'post_content' => "Read these first.\n[be_house_rules game=\"thread-shortcode-old\"]\n[be_house_rules game='thread-shortcode-old-annex']\nWelcome to thread-shortcode-old.",
		] );
		$elementor_page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Elementor Rules' ] );
		update_post_meta( $elementor_page, '_elementor_data', wp_slash( wp_json_encode( [
			[ 'elType' => 'widget', 'widgetType' => 'shortcode', 'settings' => [ 'shortcode' => "[be_house_rules game='thread-shortcode-old']" ] ],
		] ) ) );

		$this->assertTrue( Game::rename( $game_id, 'thread-shortcode-new' )['changed'] );

		$content = get_post( $page )->post_content;
		$this->assertStringContainsString( '[be_house_rules game="thread-shortcode-new"]', $content );
		$this->assertStringContainsString( "game='thread-shortcode-old-annex'", $content, 'another chronicle whose slug starts the same way is left alone' );
		$this->assertStringContainsString( 'Welcome to thread-shortcode-old.', $content, 'prose naming the slug is left alone' );
		$elementor = json_decode( get_post_meta( $elementor_page, '_elementor_data', true ), true );
		$this->assertSame( "[be_house_rules game='thread-shortcode-new']", $elementor[0]['settings']['shortcode'] );
	}

	public function test_renaming_a_game_to_its_own_current_slug_is_a_no_op(): void {
		$game_id = $this->create_game( 'thread-cascade-noop' );

		$result = Game::rename( $game_id, 'thread-cascade-noop' );

		$this->assertFalse( $result['changed'] );
		$this->assertArrayNotHasKey( 'error', $result, 'renaming to the same slug is a no-op, not a failure' );
	}
}
