<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Attachment;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Item_Event;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Attachment_Storage;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 §3.12 item 1: copying an item for a specific character - every field, property, and
 * audience_rules copied, the copy forced to `restricted` audience, its own upload (if any)
 * copied to a brand-new private file, a `holds` connection from the character, and a `copied`
 * item event. All in one transaction.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.12 item 1
 */
class ItemCopyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-item-copy';
	private int $game_id;
	private int $storyteller_id;
	private int $player_id;
	private int $character_id;

	/** @var string[] Real files written during a test, removed in tearDown() regardless of outcome. */
	private array $written_paths = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Item Copy' ] );

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		$this->character_id = (int) Character::create( [
			'name' => 'Holder', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id, 'created_by' => $this->storyteller_id,
		] );
	}

	public function tearDown(): void {
		foreach ( $this->written_paths as $path ) {
			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} elseif ( file_exists( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		parent::tearDown();
	}

	private function remove_dir( string $dir ): void {
		foreach ( scandir( $dir ) ?: [] as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->remove_dir( $path ) : unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private function make_item( array $overrides = [] ): int {
		return (int) World_Object::create( array_merge( [
			'game_id'        => $this->game_id,
			'object_type'    => 'item',
			'name'           => 'A Relic',
			'description'    => 'A relic of some note.',
			'rarity'         => 'rare',
			'cost'           => '3',
			'limitations'    => 'None known.',
			'properties'     => [],
			'audience'       => 'everyone',
			'audience_rules' => null,
			'created_by'     => $this->storyteller_id,
		], $overrides ) );
	}

	/** A real, valid PNG - generated with GD so it genuinely passes wp_check_filetype_and_ext(). */
	private function real_png_path(): string {
		$path  = tempnam( sys_get_temp_dir(), 'be-test-png-' );
		$image = imagecreate( 2, 2 );
		imagecolorallocate( $image, 255, 0, 0 );
		imagepng( $image, $path );
		$this->written_paths[] = $path;
		return $path;
	}

	private function attach_file_to( int $item_id ): int {
		$path       = $this->real_png_path();
		$attachment = Attachment_Storage::store( [
			'tmp_name' => $path, 'name' => 'photo.png', 'error' => 0, 'size' => filesize( $path ), 'type' => 'image/png',
		] );
		$id = (int) Attachment::create( array_merge( $attachment, [
			'game_id' => $this->game_id, 'entity_type' => 'item', 'entity_id' => $item_id, 'created_by' => $this->storyteller_id,
		] ) );
		$this->written_paths[] = dirname( Attachment_Storage::path_for( $attachment['stored_name'], $attachment['original_name'] ) );
		return $id;
	}

	private function copy_for_character( int $item_id, int $character_id, ?string $name = null ) {
		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/world-objects/{$item_id}/copy-for-character" );
		$request->set_param( 'character_id', $character_id );
		if ( $name !== null ) {
			$request->set_param( 'name', $name );
		}
		$response = rest_get_server()->dispatch( $request );

		if ( $response->get_status() === 201 ) {
			$copy = $response->get_data();
			foreach ( Attachment::for_entity( 'item', $copy->id ) as $attachment ) {
				$this->written_paths[] = dirname( Attachment_Storage::path_for( $attachment->stored_name, $attachment->original_name ) );
			}
		}

		return $response;
	}

	// -------------------------------------------------------------------------
	// Fields, properties, and audience_rules copied.
	// -------------------------------------------------------------------------

	public function test_every_field_property_and_audience_rules_are_copied(): void {
		$rules   = [ 'conditions' => [ [ 'field' => 'clan', 'operator' => 'equals', 'find' => 'Tremere' ] ] ];
		$item_id = $this->make_item( [ 'properties' => [ 'item_type' => 'Weapon' ], 'audience_rules' => $rules ] );

		$response = $this->copy_for_character( $item_id, $this->character_id );
		$this->assertSame( 201, $response->get_status() );

		$copy = $response->get_data();
		$this->assertSame( 'A Relic', $copy->name );
		$this->assertSame( 'A relic of some note.', $copy->description );
		$this->assertSame( 'rare', $copy->rarity );
		$this->assertSame( '3', $copy->cost );
		$this->assertSame( 'None known.', $copy->limitations );
		$this->assertSame( [ 'item_type' => 'Weapon' ], $copy->properties );
		$this->assertEquals( $rules, $copy->audience_rules );
		$this->assertSame( $item_id, (int) $copy->based_on_id );
	}

	public function test_a_given_name_overrides_the_source_name(): void {
		$item_id  = $this->make_item();
		$response = $this->copy_for_character( $item_id, $this->character_id, 'Marcus\'s Relic' );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Marcus\'s Relic', $response->get_data()->name );
	}

	// -------------------------------------------------------------------------
	// The copy is always restricted, regardless of the source's own audience.
	// -------------------------------------------------------------------------

	public function test_the_copy_is_always_restricted_regardless_of_the_source_audience(): void {
		$item_id  = $this->make_item( [ 'audience' => 'everyone' ] );
		$response = $this->copy_for_character( $item_id, $this->character_id );

		$this->assertSame( 'restricted', $response->get_data()->audience );
	}

	// -------------------------------------------------------------------------
	// Editing one never changes the other.
	// -------------------------------------------------------------------------

	public function test_editing_the_copy_leaves_the_source_untouched(): void {
		$item_id  = $this->make_item();
		$copy_id  = (int) $this->copy_for_character( $item_id, $this->character_id )->get_data()->id;

		World_Object::update( $copy_id, [ 'name' => 'Changed Copy Name' ] );

		$source = World_Object::find( $item_id );
		$copy   = World_Object::find( $copy_id );
		$this->assertSame( 'A Relic', $source->name );
		$this->assertSame( 'Changed Copy Name', $copy->name );
	}

	public function test_the_copys_upload_is_a_real_separate_file_from_the_source(): void {
		$item_id = $this->make_item();
		$this->attach_file_to( $item_id );

		$copy_id = (int) $this->copy_for_character( $item_id, $this->character_id )->get_data()->id;

		$source_files = Attachment::for_entity( 'item', $item_id );
		$copy_files   = Attachment::for_entity( 'item', $copy_id );
		$this->assertCount( 1, $source_files );
		$this->assertCount( 1, $copy_files );
		$this->assertNotSame( $source_files[0]->stored_name, $copy_files[0]->stored_name );

		$source_path = Attachment_Storage::path_for( $source_files[0]->stored_name, $source_files[0]->original_name );
		$copy_path   = Attachment_Storage::path_for( $copy_files[0]->stored_name, $copy_files[0]->original_name );
		$this->assertFileExists( $source_path );
		$this->assertFileExists( $copy_path );

		// Overwriting the copy's own file must never touch the source's bytes.
		file_put_contents( $copy_path, 'changed' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertNotEquals( 'changed', file_get_contents( $source_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function test_copying_an_item_with_no_upload_creates_no_attachment_row(): void {
		$item_id = $this->make_item();
		$copy_id = (int) $this->copy_for_character( $item_id, $this->character_id )->get_data()->id;

		$this->assertSame( [], Attachment::for_entity( 'item', $copy_id ) );
	}

	// -------------------------------------------------------------------------
	// A holds connection and a copied event.
	// -------------------------------------------------------------------------

	public function test_a_holds_connection_is_created_from_the_character(): void {
		$item_id = $this->make_item();
		$copy_id = (int) $this->copy_for_character( $item_id, $this->character_id )->get_data()->id;

		$connections = Connection::for_source( 'character', $this->character_id );
		$found       = array_filter( $connections, static fn( $c ) => $c->target_type === 'world_object' && (int) $c->target_id === $copy_id );
		$this->assertCount( 1, $found );
		$this->assertSame( 'holds', array_values( $found )[0]->label );
	}

	public function test_a_copied_event_is_recorded(): void {
		$item_id = $this->make_item();
		$copy_id = (int) $this->copy_for_character( $item_id, $this->character_id )->get_data()->id;

		$events = Item_Event::for_object( $copy_id );
		$this->assertCount( 1, $events );
		$this->assertSame( 'copied', $events[0]->event );
		$this->assertSame( $this->character_id, (int) $events[0]->character_id );
	}

	// -------------------------------------------------------------------------
	// Catalog list excludes copies by default.
	// -------------------------------------------------------------------------

	public function test_the_catalog_list_excludes_copies_by_default(): void {
		$item_id = $this->make_item();
		$this->copy_for_character( $item_id, $this->character_id );

		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/world-objects" );
		$request->set_param( 'object_type', 'item' );
		$response = rest_get_server()->dispatch( $request );

		$names = array_map( static fn( $o ) => $o->name, $response->get_data() );
		$this->assertContains( 'A Relic', $names );
		$this->assertCount( 1, $response->get_data() );
	}

	public function test_copies_only_returns_just_the_copy(): void {
		$item_id = $this->make_item();
		$copy_id = (int) $this->copy_for_character( $item_id, $this->character_id )->get_data()->id;

		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/world-objects" );
		$request->set_param( 'object_type', 'item' );
		$request->set_param( 'copies', 'only' );
		$response = rest_get_server()->dispatch( $request );

		$ids = array_map( static fn( $o ) => (int) $o->id, $response->get_data() );
		$this->assertSame( [ $copy_id ], $ids );
	}

	public function test_copies_include_returns_both(): void {
		$item_id = $this->make_item();
		$this->copy_for_character( $item_id, $this->character_id );

		wp_set_current_user( $this->storyteller_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/world-objects" );
		$request->set_param( 'object_type', 'item' );
		$request->set_param( 'copies', 'include' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertCount( 2, $response->get_data() );
	}

	// -------------------------------------------------------------------------
	// Refusals.
	// -------------------------------------------------------------------------

	public function test_a_location_cannot_be_copied_for_a_character(): void {
		$location_id = (int) World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'location', 'name' => 'A Place', 'created_by' => $this->storyteller_id,
		] );

		$response = $this->copy_for_character( $location_id, $this->character_id );
		$this->assertSame( 409, $response->get_status() );
	}

	public function test_a_missing_character_id_is_refused(): void {
		$item_id = $this->make_item();

		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/world-objects/{$item_id}/copy-for-character" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_character_from_another_game_is_refused(): void {
		Game::create( [ 'slug' => 'thread-item-copy-other', 'name' => 'Other' ] );
		$other_character_id = (int) Character::create( [
			'name' => 'Stranger', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => 'thread-item-copy-other', 'created_by' => $this->storyteller_id,
		] );
		$item_id = $this->make_item();

		$response = $this->copy_for_character( $item_id, $other_character_id );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_player_may_not_copy_an_item(): void {
		$item_id = $this->make_item();

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/world-objects/{$item_id}/copy-for-character" );
		$request->set_param( 'character_id', $this->character_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}
