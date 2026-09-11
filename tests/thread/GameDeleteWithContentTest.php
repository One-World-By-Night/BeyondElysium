<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Saved_Query;
use BeyondElysium\Models\Template;
use BeyondElysium\Models\World_Object;
use WP_UnitTestCase;

/**
 * Direct follow-on to Decision 071, same day: "we can delete the demo chronicle content?"
 * `Game::delete()` was a bare row delete, same class of gap as `Character::delete()` had -
 * every character/plot/world object/game-scoped template/saved query it owned was left
 * behind, orphaned. `Game::delete_with_content()` is the real cascade.
 *
 * Deliberately a SEPARATE method from `Game::delete()`, not a change to it - a real
 * near-miss caught before shipping: `delete()` is what the already-live admin "Delete"
 * button calls, and its own confirm dialog explicitly promises characters survive.
 * Changing it in place would have made that one-click button silently destroy a REAL
 * chronicle's content with no warning, exactly what the user's own follow-up instruction
 * ruled out for non-demo content. `test_delete_leaves_content_behind_delete_with_content_
 * does_not` below proves both halves of that promise are still true after this change.
 */
class GameDeleteWithContentTest extends WP_UnitTestCase {

	private function make_game( string $slug ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $slug, 'name' => 'Delete With Content Test Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	public function test_delete_with_content_removes_every_kind_of_owned_content(): void {
		$slug    = 'thread-test-delete-with-content-' . wp_generate_password( 8, false );
		$game_id = $this->make_game( $slug );

		$character_id = Character::create( [
			'name' => 'Cascade Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $slug,
		] );
		$plot_id = Plot::create( [ 'game_id' => $game_id, 'title' => 'Cascade Test Plot' ] );
		$item_id = World_Object::create( [
			'game_id' => $game_id, 'object_type' => 'item', 'name' => 'Cascade Test Item',
		] );
		$template_id = Template::create( [
			'game_id' => $game_id, 'stack_slug' => 'vampire', 'name' => 'Cascade Test Template',
			'template_type' => 'sheet_full', 'layout' => [ 'version' => 1, 'columns' => 3, 'sections' => [] ], 'created_by' => 1,
		] );
		$query_id = Saved_Query::create( [
			'game_id' => $game_id, 'name' => 'Cascade Test Query', 'inventory' => 'char',
			'conditions' => [], 'created_by' => 1,
		] );

		$this->assertNotNull( Character::find( $character_id ), 'fixture sanity check' );
		$this->assertNotNull( Plot::find( $plot_id ), 'fixture sanity check' );
		$this->assertNotNull( World_Object::find( $item_id ), 'fixture sanity check' );
		$this->assertNotNull( Template::find( $template_id ), 'fixture sanity check' );
		$this->assertNotNull( Saved_Query::find( $query_id ), 'fixture sanity check' );

		$this->assertTrue( Game::delete_with_content( $slug ) );

		$this->assertNull( Character::find( $character_id ), 'character must be gone' );
		$this->assertNull( Plot::find( $plot_id ), 'plot must be gone' );
		$this->assertNull( World_Object::find( $item_id ), 'world object must be gone' );
		$this->assertNull( Template::find( $template_id ), 'template must be gone' );
		$this->assertNull( Saved_Query::find( $query_id ), 'saved query must be gone' );
		$this->assertNull( Game::find_by_slug( $slug ), 'the game itself must be gone' );
	}

	/**
	 * The real safeguard this whole design is built around: the plain `delete()` - what
	 * the already-live admin button calls - must be completely unaffected by adding
	 * `delete_with_content()` alongside it. If this ever fails, the near-miss this
	 * decision's own doc comment describes has actually happened.
	 */
	public function test_plain_delete_still_leaves_content_behind_only_delete_with_content_does_not(): void {
		$slug_a  = 'thread-test-plain-delete-' . wp_generate_password( 8, false );
		$this->make_game( $slug_a );
		$character_a = Character::create( [
			'name' => 'Survives Plain Delete', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $slug_a,
		] );

		Game::delete( $slug_a );

		$this->assertNull( Game::find_by_slug( $slug_a ), 'the game row itself is still removed' );
		$this->assertNotNull( Character::find( $character_a ), 'plain delete() must still leave characters behind - the existing admin button\'s own promise' );

		// Clean up what the plain delete() deliberately left behind, since this is a
		// throwaway fixture, not real content.
		Character::delete( $character_a );
	}
}
