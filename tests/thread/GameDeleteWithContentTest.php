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
 * `Game::delete()` was a bare row delete - every character/plot/world object/game-scoped
 * template/saved query it owned was left behind. `Game::delete_with_content()` is the real
 * cascade.
 *
 * The original safeguard still holds: the plain delete behind the Games screen's one-click
 * button never destroys a chronicle's content. Since 1.0.0-review F-036 it also never leaves
 * that content behind for a same-named chronicle to inherit - it refuses instead, and the
 * screen asks separately whether to delete the content too (`ChronicleDeleteThreadTest`).
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
	 * The plain `delete()` must never destroy content, and must never orphan it either: while
	 * the chronicle holds content it refuses, and the game and its characters stay exactly as
	 * they were.
	 */
	public function test_plain_delete_refuses_while_the_chronicle_holds_content(): void {
		$slug_a = 'thread-test-plain-delete-' . wp_generate_password( 8, false );
		$this->make_game( $slug_a );
		$character_a = Character::create( [
			'name' => 'Survives Plain Delete', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $slug_a,
		] );

		$this->assertFalse( Game::delete( $slug_a ) );

		$this->assertNotNull( Game::find_by_slug( $slug_a ), 'the game must still exist' );
		$this->assertNotNull( Character::find( $character_a ), 'its character must still exist, still owned by it' );
	}
}
